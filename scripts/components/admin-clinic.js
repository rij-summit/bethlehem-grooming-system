let clinicPetWeightToolsReady;
function loadClinicPetWeightTools() {
  return clinicPetWeightToolsReady ??= import("./pet-weight-size.js?v=pet-weight-size-20261011-v3");
}

// ── Shared attachment helpers ─────────────────────────────────────────────────

function formatClinicFileSize(bytes) {
  const size = Number(bytes);
  if (!Number.isFinite(size) || size < 0) return "Size unavailable";
  if (size < 1024) return `${size} B`;
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`;
  return `${(size / (1024 * 1024)).toFixed(1)} MB`;
}

async function downloadClinicAttachment(apptId, attachment) {
  const result = await API.clinicDownloadAttachment(apptId, attachment.id);
  const objectUrl = URL.createObjectURL(result.blob);
  const link = document.createElement("a");
  link.href = objectUrl;
  link.download = result.fileName || attachment.file_name || "clinic-attachment";
  document.body.appendChild(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
}

async function viewClinicAttachment(apptId, attachment) {
  // Open the tab before awaiting so popup blockers treat it as user-initiated.
  const win = window.open("", "_blank");
  try {
    const result = await API.clinicDownloadAttachment(apptId, attachment.id);
    const type = result.blob.type || result.contentType || "";
    if (!win || !(type.startsWith("image/") || type === "application/pdf")) {
      // Browsers can't render DICOM; fall back to a download.
      win?.close();
      await downloadClinicAttachment(apptId, attachment);
      return;
    }
    const objectUrl = URL.createObjectURL(result.blob);
    win.location.href = objectUrl;
    setTimeout(() => URL.revokeObjectURL(objectUrl), 60000);
  } catch (error) {
    win?.close();
    throw error;
  }
}

function clinicLocalDate(d) {
  const pad = (n) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

// ── Shared confirm dialog and toast ───────────────────────────────────────────
// Used by every clinic page component instead of the browser's alert()/confirm().

document.addEventListener("alpine:init", () => {
  let resolveDialog = null;
  let toastTimer = null;

  Alpine.store("clinicAccess", {
    permissions: {},
    user: null,
    async init() {
      try {
        const response = await API.getStaffIdentity();
        this.user = response.user || null;
        this.permissions = response.user?.clinic_permissions || {};
      } catch {
        this.permissions = {};
      }
    },
  });

  Alpine.store("clinicConfinementClock", {
    now: Date.now(),
    init() { this.timer = setInterval(() => { this.now = Date.now(); }, 60000); },
  });

  Alpine.store("clinicFeedback", {
    dialog: { open: false, title: "", message: "", confirmLabel: "Confirm", cancelLabel: "Cancel", danger: false },
    toast: { show: false, message: "", ok: true },

    confirm({ title, message, confirmLabel = "Confirm", cancelLabel = "Cancel", danger = false, dismissOnEscape = true }) {
      resolveDialog?.(false);
      this.dialog = { open: true, title, message, confirmLabel, cancelLabel, danger, dismissOnEscape };
      return new Promise((resolve) => { resolveDialog = resolve; });
    },

    answer(confirmed) {
      this.dialog.open = false;
      resolveDialog?.(confirmed);
      resolveDialog = null;
    },

    notify(message, ok = true) {
      clearTimeout(toastTimer);
      this.toast = { show: true, message, ok };
      toastTimer = setTimeout(() => { this.toast.show = false; }, 3500);
    },
  });
});

// Threshold bands use the next starting weight; explicit historical ranges retain their endpoints.
function clinicMedicinePrice(item, weight) {
  if (item.pricing_method !== "weight_based") {
    if (!(Number(item.selling_price) > 0)) return { error: "Set this medicine's selling price in Inventory." };
    return { price: Number(item.selling_price), pet_weight_kg: null, min_weight_kg: null, max_weight_kg: null };
  }
  if (weight === "" || weight == null || !Number.isFinite(Number(weight)) || Number(weight) <= 0)
    return { error: "Record or update the pet's weight before selecting a weight-based medicine price." };
  const ranges = item.weight_prices || [];
  const matches = ranges.map((range, index) => {
    if (item.weight_pricing_mode === "catalog") {
      const min = Number(range.min_weight_kg), next = ranges[index + 1];
      let max = range.max_weight_kg, matches;
      if (min === 0) matches = max != null && Number(weight) < Number(max);
      else if (max == null) matches = Number(weight) > min;
      else {
        if (next) max = next.min_weight_kg;
        const inclusive = !next || next.max_weight_kg == null;
        matches = Number(weight) >= min && (inclusive ? Number(weight) <= Number(max) : Number(weight) < Number(max));
      }
      return {...range, max_weight_kg: max, matches};
    }
    const final = index === ranges.length - 1;
    const threshold = item.weight_pricing_mode === "threshold";
    const max = threshold && !final ? ranges[index + 1].min_weight_kg : range.max_weight_kg;
    const inclusive = !threshold || (final && Number(range.min_weight_kg) > 0);
    const open = threshold && final && Number(range.min_weight_kg) > 0 && max === null;
    return { ...range, max_weight_kg: max, matches: Number(weight) >= Number(range.min_weight_kg)
      && (open || (max != null && max !== "" && (inclusive ? Number(weight) <= Number(max) : Number(weight) < Number(max)))) };
  }).filter(range => range.matches);
  if (matches.length !== 1) return { error: "No price is configured for this pet's weight. Correct the pricing configuration or pet weight." };
  return { price: Number(matches[0].price), pet_weight_kg: Number(weight),
    min_weight_kg: matches[0].min_weight_kg, max_weight_kg: matches[0].max_weight_kg };
}

function clinicPriceRange(range) {
  return range.max_weight_kg == null ? `${Number(range.min_weight_kg)} kg and above`
    : `${Number(range.min_weight_kg)}–${Number(range.max_weight_kg)} kg`;
}

// ── Medical Record Modal ──────────────────────────────────────────────────────

function adminClinicModal() {
  return {
    open:       false,
    saving:     false,
    modalError: "",
    workspaceSaving: false,
    weightTools: null,
    title:      "Medical Record",
    apptId:     null,
    currentAppt: null,
    activeSection: "medical",
    chargesOpen: false,
    formErrors: {},
    veterinarians: [], optionsLoading: false, optionsError: "",
    vetDropdownOpen: false,
    vetSearch: "",
    administrationRoutes: [], diagnosticOptions: [],
    recordedByName: "",
    returnFocus: null,
    _optionsRequest: 0,
    _clinicalOptionsLoaded: false,
    attachments: [],
    attachmentFile: null,
    attachmentLabel: "",
    attachmentDiagnosticKey: "",
    attachmentBusy: false,
    attachmentBusyId: null,
    attachmentError: "",
    form: {
      chief_complaint: "", relevant_history: "", reported_signs: "", physical_exam_findings: "",
      differential_diagnoses: "", working_diagnosis: "", treatment_given: "",
      primary_veterinarian_id: "", additional_veterinarian_ids: [], diagnostics: [], prescriptions: [],
      vet_notes: "", follow_up_date: "", follow_up_notes: "",
      weight_kg: "", temperature_c: "", heart_rate_bpm: "",
      respiratory_rate_bpm: "", body_condition_score: "",
    },

    init() {
      window.addEventListener("clinic-open-modal", (e) => this.openModal(e.detail));
      window.addEventListener("clinic-workspace-busy", (event) => {
        if (event.detail.id === this.apptId) this.workspaceSaving = event.detail.saving;
      });
      window.addEventListener("clinic-cases-loaded", (event) => {
        if (!this.open) return;
        const current = event.detail?.find((item) => item.id === this.apptId);
        if (current) this.currentAppt = current;
        else if (!this.currentAppt?.confinement) this.open = false;
      });
      window.addEventListener('clinic-case-patch', (event) => {
        if (this.open && event.detail.id === this.apptId) this.currentAppt = {...this.currentAppt, ...event.detail};
      });
      window.addEventListener('clinic-review-payment', (event) => {
        if (this.open && event.detail.id === this.apptId) this.closeModal();
      });
    },

    get canEditClinical() {
      return !!this.$store.clinicAccess.permissions.clinical
        && !this.currentAppt?.paid && !this.currentAppt?.archived_at
        && (["checked_in", "in_consultation"].includes(this.currentAppt?.status)
          || (API.getUserRole() === "admin" && this.currentAppt?.status === "for_payment"));
    },

    get canFinish() { return this.canEditClinical && this.currentAppt?.status === "in_consultation"
      && !(this.currentAppt?.confinement && !this.currentAppt.confinement.discharged_at); },
    get isVeterinarian() { return this.$store.clinicAccess.user?.staff_subrole === "veterinarian" && this.$store.clinicAccess.user?.role === "staff"; },
    get generalAttachments() {
      const resultIds = this.form.diagnostics.map(row => Number(row.result_attachment_id));
      return this.attachments.filter(file => !resultIds.includes(Number(file.id)));
    },
    get selectedVeterinarianIds() {
      return [...new Set([this.form.primary_veterinarian_id, ...this.form.additional_veterinarian_ids].filter(Boolean).map(String))];
    },
    get lockedVeterinarianId() {
      return this.canEditClinical && this.isVeterinarian ? String(this.$store.clinicAccess.user.user_id) : null;
    },
    get selectedVeterinarians() {
      const saved = [
        { user_id: this.currentAppt?.record?.primary_veterinarian_id, name: this.currentAppt?.record?.primary_veterinarian_name },
        ...(this.currentAppt?.record?.additional_veterinarians || []),
      ];
      return this.selectedVeterinarianIds.map(id => {
        const vet = this.veterinarians.find(vet => String(vet.user_id) === id) || saved.find(vet => String(vet.user_id) === id);
        const user = this.$store.clinicAccess.user;
        const ownName = id === this.lockedVeterinarianId ? [user?.first_name, user?.last_name].filter(Boolean).join(" ") : "";
        return { user_id: id, name: vet?.name || ownName || "Veterinarian" };
      });
    },
    get filteredVeterinarians() {
      const search = this.vetSearch.trim().toLowerCase();
      return this.veterinarians.filter(vet => String(vet.user_id) !== this.lockedVeterinarianId && vet.name.toLowerCase().includes(search));
    },
    toggleVeterinarian(id) {
      id = String(id);
      if (!this.canEditClinical || this.saving || id === this.lockedVeterinarianId) return;
      const selected = this.selectedVeterinarianIds;
      const next = selected.includes(id) ? selected.filter(value => value !== id) : [...selected, id];
      if (this.lockedVeterinarianId) {
        this.form.primary_veterinarian_id = this.lockedVeterinarianId;
        this.form.additional_veterinarian_ids = next.filter(value => value !== this.lockedVeterinarianId);
      } else {
        this.form.primary_veterinarian_id = next[0] || "";
        this.form.additional_veterinarian_ids = next.slice(1);
      }
    },
    removeVeterinarian(id) {
      const trigger = this.$refs.vetTrigger;
      this.toggleVeterinarian(id);
      this.$nextTick?.(() => trigger?.focus());
    },
    toggleVetDropdown() {
      this.vetDropdownOpen = !this.vetDropdownOpen;
      this.vetSearch = "";
      if (this.vetDropdownOpen) this.$nextTick?.(() => {
        const selector = this.veterinarians.length > 8 ? 'input[type="search"]' : 'input[type="checkbox"]';
        this.$refs.vetDropdown?.querySelector(selector)?.focus();
      });
    },
    closeVetDropdown(restoreFocus = false) {
      this.vetDropdownOpen = false;
      if (restoreFocus) this.$refs.vetTrigger?.focus();
    },
    vetDropdownKeydown(event) {
      if (event.key === "Enter") {
        event.preventDefault();
        if (event.target.type === "checkbox") event.target.click();
        return;
      }
      if (!["ArrowDown", "ArrowUp", "Home", "End"].includes(event.key)) return;
      if (event.target.type === "search" && event.key !== "ArrowDown") return;
      const choices = [...this.$refs.vetDropdown.querySelectorAll('input[type="checkbox"]')];
      if (!choices.length) return;
      event.preventDefault();
      const index = choices.indexOf(event.target);
      const next = event.key === "Home" ? 0 : event.key === "End" ? choices.length - 1
        : (index + (event.key === "ArrowDown" ? 1 : -1) + choices.length) % choices.length;
      choices[next].focus();
    },
    errorForField(field) {
      return Object.entries(this.formErrors).find(([key]) => key === field || key.startsWith(field + "."));
    },
    hasFieldError(field) { return Boolean(this.errorForField(field)); },
    isMissingFieldError(message) {
      return /\bis required\b|^required$/i.test(message);
    },
    fieldError(field) {
      const error = this.errorForField(field);
      if (!error) return "";
      const [key, messages] = error;
      if (this.isMissingFieldError(messages[0])) return "";
      if (/characters/i.test(messages[0])) {
        const limit = messages[0].match(/\b(\d[\d,]*)\s+characters/i)?.[1];
        if (limit) return `Use up to ${Number(limit.replaceAll(",", "")).toLocaleString()} characters.`;
      }
      const name = key.split(".").at(-1);
      if (key.startsWith("additional_veterinarian_ids")) return "Select an available veterinarian.";
      if (name === "weight_kg") return this.weightTools?.getWeightValueValidationMessage(this.form.weight_kg) || "Enter a valid weight.";
      return {
        primary_veterinarian_id: "Select an available veterinarian.",
        temperature_c: "Enter a temperature from 0 to 999.9 °C, using at most one decimal place.",
        heart_rate_bpm: "Enter a whole number from 0 to 65,535.",
        respiratory_rate_bpm: "Enter a whole number from 0 to 65,535.",
        body_condition_score: "Select a score from 1 to 9.",
        follow_up_date: "Choose a follow-up date within the allowed range.",
        diagnostic: "Select a diagnostic.", custom_name: "Enter a test name.", status: "Select a status.",
        result_attachment_id: "Select an available result file.",
        medicine: "Enter a medicine name.", strength_concentration: "Enter a valid strength or concentration.",
        dose: "Enter a valid dose.", route: "Select a route.", frequency: "Enter a valid frequency.",
        duration: "Enter a valid duration.", quantity: "Enter a positive quantity, e.g. 14 tablets.",
        diagnostics: "Check the diagnostic entries.", prescriptions: "Check the prescription entries.",
      }[name] || "Check this value.";
    },
    showValidationErrors(errors) {
      this.formErrors = errors;
      const missingOnly = Object.values(errors).every(messages => this.isMissingFieldError(messages[0]));
      this.modalError = missingOnly ? "Complete the required fields marked *." : "Check the highlighted fields.";
      this.focusInvalidField();
    },
    focusInvalidField() {
      this.$nextTick?.(() => {
        const field = this.$el.querySelector('#medical-record-form [aria-invalid="true"]')
          || this.$el.querySelector('#medical-validation-summary');
        field?.focus();
        field?.scrollIntoView?.({ block: "nearest" });
      });
    },
    trapFocus(event) {
      const scope = this.chargesOpen ? event.currentTarget.querySelector('#case-billing-panel') : event.currentTarget;
      const controls = [...scope.querySelectorAll('button, input, select, textarea, a[href], [tabindex="0"]')]
        .filter(element => !element.disabled && element.getClientRects().length);
      const first = controls[0], last = controls.at(-1);
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    },
    routeLabel(route) { return route ? route[0].toUpperCase() + route.slice(1) : "—"; },
    diagnosticName(row) { return row.diagnostic === "Other" ? row.custom_name || "Other" : row.diagnostic || "Unnamed diagnostic"; },
    addDiagnostic() {
      this.form.diagnostics.push({ key: crypto.randomUUID(), diagnostic: "", custom_name: "", status: "Ordered", notes: "", result_attachment_id: "", result_reference: "" });
    },
    addPrescription() {
      this.form.prescriptions.push({ medicine: "", strength_concentration: "", dose: "", route: "", frequency: "", duration: "", quantity: "", instructions: "" });
    },
    removeRow(section, index) { this.form[section].splice(index, 1); this.formErrors = {}; },
    async loadClinicalOptions() {
      const request = ++this._optionsRequest;
      this.optionsLoading = true;
      this.optionsError = "";
      try {
        const response = await API.getAdminVaccinationOptions(true);
        if (request !== this._optionsRequest || !this.open) return;
        this.veterinarians = response.veterinarians || [];
        this.administrationRoutes = response.administration_routes || [];
        this.diagnosticOptions = response.diagnostic_options || [];
        this._clinicalOptionsLoaded = true;
        if (!this.currentAppt?.record?.recorded_by_name) this.recordedByName = response.current_user_name || "—";
        if (this.canEditClinical && response.current_user_is_veterinarian) {
          this.form.primary_veterinarian_id = String(response.current_user_id);
          this.form.additional_veterinarian_ids = this.form.additional_veterinarian_ids.filter(id => String(id) !== String(response.current_user_id));
        }
      } catch (error) {
        if (request === this._optionsRequest) this.optionsError = error.message || "Unable to load veterinarian and clinical options.";
      } finally { if (request === this._optionsRequest) this.optionsLoading = false; }
    },
    get followUpMinDate() {
      return clinicLocalDate(new Date());
    },

    get followUpMaxDate() {
      const d = new Date();
      d.setMonth(d.getMonth() + 3);
      return clinicLocalDate(d);
    },

    async openModal({ appt, section = "medical" } = {}) {
      if (!appt) return;
      this.returnFocus = document.activeElement;
      this.weightTools = await loadClinicPetWeightTools();
      this.vetDropdownOpen = false;
      this.vetSearch = "";
      this.apptId     = appt.id;
      this.currentAppt = appt;
      ++this._optionsRequest;
      this.optionsLoading = false;
      this._clinicalOptionsLoaded = false;
      this.activeSection = "medical";
      this.chargesOpen = false;
      this.modalError = "";
      this.title      = `Patient Record — ${appt.ownerName}${appt.pet?.name ? " / " + appt.pet.name : ""}`;
      const r = appt.record  || {};
      const v = appt.vitals  || {};
      this.attachments = (r.attachments || []).map((attachment) => ({ ...attachment }));
      this.attachmentFile = null;
      this.attachmentLabel = "";
      this.attachmentDiagnosticKey = "";
      this.attachmentBusy = false;
      this.attachmentBusyId = null;
      this.attachmentError = "";
      this.formErrors = {};
      this.recordedByName = r.recorded_by_name || "—";
      this.form = {
        chief_complaint:      r.chief_complaint      || appt.chief_complaint || "",
        relevant_history: r.relevant_history || "",
        reported_signs: r.reported_signs || "",
        physical_exam_findings: r.physical_exam_findings ?? r.findings ?? "",
        differential_diagnoses: r.differential_diagnoses || "",
        working_diagnosis: r.working_diagnosis ?? r.diagnosis ?? "",
        primary_veterinarian_id: String(r.primary_veterinarian_id || ""),
        additional_veterinarian_ids: (r.additional_veterinarians || []).map(vet => String(vet.user_id)),
        diagnostics: (r.diagnostics || []).map(row => ({ ...row, result_attachment_id: String(row.result_attachment_id || "") })),
        prescriptions: (r.prescriptions || []).map(row => ({ ...row })),
        treatment_given:      r.treatment_given      || "",
        vet_notes:            r.vet_notes            || "",
        follow_up_date:       r.follow_up_date       || "",
        follow_up_notes:      r.follow_up_notes      || "",
        weight_kg:            this.weightTools.formatSavedWeight(v.weight_kg ?? appt.pet?.weight),
        temperature_c:        v.temperature_c        ?? "",
        heart_rate_bpm:       v.heart_rate_bpm       ?? "",
        respiratory_rate_bpm: v.respiratory_rate_bpm ?? "",
        body_condition_score: v.body_condition_score ?? "",
      };
      this.open = true;
      if (this.canEditClinical) {
        if (this.isVeterinarian) {
          this.form.primary_veterinarian_id = String(this.$store.clinicAccess.user.user_id);
          this.form.additional_veterinarian_ids = this.form.additional_veterinarian_ids.filter(id => id !== this.form.primary_veterinarian_id);
        }
      }
      this.selectSection(section);
      this.$nextTick?.(() => {
        this.$el.querySelector('[aria-label="Close patient record"]')?.focus();
        window.lucide?.createIcons();
      });
    },

    selectSection(section) {
      if (this.saving || this.attachmentBusy || this.workspaceSaving) return;
      if (section === "billing") { this.openCharges(); return; }
      this.activeSection = ["vaccinations", "services", "confinement"].includes(section) ? section : "medical";
      if (this.activeSection === "medical" && this.canEditClinical && !this._clinicalOptionsLoaded && !this.optionsLoading) {
        this.loadClinicalOptions();
      }

      if (this.activeSection === "vaccinations" && this.currentAppt) {
        window.dispatchEvent(new CustomEvent("clinic-vaccinations-open", {
          detail: { appt: this.currentAppt },
        }));
      }
    },

    openCharges() {
      if (!this.$store.clinicAccess.permissions.billing || !this.currentAppt?.id || this.saving || this.attachmentBusy || this.workspaceSaving) return;
      this.chargesOpen = true;
      this.$nextTick?.(() => this.$el.closest('[x-data="adminClinicModal()"]')?.querySelector('[aria-label="Close charges"]')?.focus());
    },
    closeCharges() {
      this.chargesOpen = false;
      this.$nextTick?.(() => this.$el.closest('[x-data="adminClinicModal()"]')?.querySelector('[aria-controls="case-billing-panel"]')?.focus());
      if (this.activeSection === 'medical') this.selectSection('medical');
    },
    closeModal() {
      if (this.saving || this.attachmentBusy || this.workspaceSaving) return;
      this.open = false;
      this.chargesOpen = false;
      this.vetDropdownOpen = false;
      ++this._optionsRequest;
      this.optionsLoading = false;
      this.returnFocus?.focus();
      this.activeSection = "medical";
    },

    selectAttachment(event) {
      this.attachmentFile = event.target.files?.[0] || null;
      this.attachmentError = "";
    },

    formatFileSize: formatClinicFileSize,

    async uploadAttachment() {
      if (!this.canEditClinical || this.saving || this.attachmentBusy) return;
      if (!this.attachmentFile) {
        this.attachmentError = "Choose a JPG, PNG, PDF, or DCM file first.";
        return;
      }

      this.attachmentBusy = true;
      this.attachmentError = "";
      try {
        const result = await API.clinicUploadAttachment(
          this.apptId,
          this.attachmentFile,
          this.attachmentLabel.trim(),
        );
        this.attachments.push(result.attachment);
        const diagnostic = this.form.diagnostics.find(row => row.key === this.attachmentDiagnosticKey);
        if (diagnostic) diagnostic.result_attachment_id = String(result.attachment.id);
        this.attachmentDiagnosticKey = "";
        this.attachmentFile = null;
        this.attachmentLabel = "";
        if (this.$refs.attachmentFile) this.$refs.attachmentFile.value = "";
      } catch (error) {
        this.attachmentError = error.errors
          ? (error.errors.file ? "Use a JPG, PNG, PDF or DCM file up to 10 MB." : "Keep the label within 200 characters.")
          : (error.message || "Failed to upload attachment.");
      } finally {
        this.attachmentBusy = false;
      }
    },

    async viewAttachment(attachment) {
      this.attachmentBusyId = attachment.id;
      this.attachmentError = "";
      try {
        await viewClinicAttachment(this.apptId, attachment);
      } catch (error) {
        this.attachmentError = error.message || "Failed to open attachment.";
      } finally {
        this.attachmentBusyId = null;
      }
    },

    async deleteAttachment(attachment) {
      if (!this.canEditClinical || this.saving) return;
      const confirmed = await this.$store.clinicFeedback.confirm({
        title: "Remove attachment?",
        message: `${attachment.file_name} will be removed. This cannot be undone.`,
        confirmLabel: "Remove",
        danger: true,
      });
      if (!confirmed) return;

      this.attachmentBusyId = attachment.id;
      this.attachmentError = "";
      try {
        await API.clinicDeleteAttachment(this.apptId, attachment.id);
        this.attachments = this.attachments.filter((item) => item.id !== attachment.id);
        this.form.diagnostics.forEach(row => {
          if (Number(row.result_attachment_id) === Number(attachment.id)) row.result_attachment_id = "";
        });
      } catch (error) {
        this.attachmentError = error.message || "Failed to remove attachment.";
      } finally {
        this.attachmentBusyId = null;
      }
    },

    async saveRecord(finishCase = false) {
      if (!this.canEditClinical || this.saving || this.attachmentBusy || this.optionsLoading || (finishCase && !this.canFinish)) return;
      this.formErrors = {};
      this.modalError = "";
      this.weightTools = await loadClinicPetWeightTools();
      const weightError = this.weightTools.getWeightValueValidationMessage(this.form.weight_kg);
      if (weightError) {
        this.showValidationErrors({ weight_kg: [weightError] });
        return;
      }
      if (finishCase) {
        const errors = {};
        for (const field of ["primary_veterinarian_id", "weight_kg", "temperature_c", "heart_rate_bpm", "respiratory_rate_bpm"]) {
          if (String(this.form[field] ?? "").trim() === "") errors[field] = ["Required"];
        }
        if (Object.keys(errors).length) {
          this.showValidationErrors(errors);
          return;
        }
      }
      const followUp = this.form.follow_up_date;
      if (followUp && (followUp < this.followUpMinDate || followUp > this.followUpMaxDate)) {
        this.showValidationErrors({ follow_up_date: ["Invalid date"] });
        return;
      }
      this.saving     = true;
      this.modalError = "";
      try {
        const numberOrNull = (value) => value === "" || value == null ? null : Number(value);
        const payload = {
          chief_complaint:      this.form.chief_complaint      || null,
          relevant_history: this.form.relevant_history || null,
          reported_signs: this.form.reported_signs || null,
          physical_exam_findings: this.form.physical_exam_findings || null,
          differential_diagnoses: this.form.differential_diagnoses || null,
          working_diagnosis: this.form.working_diagnosis || null,
          primary_veterinarian_id: this.form.primary_veterinarian_id || null,
          additional_veterinarian_ids: this.form.additional_veterinarian_ids,
          diagnostics: this.form.diagnostics.map(row => ({ ...row, custom_name: row.diagnostic === "Other" ? row.custom_name : null, result_attachment_id: row.result_attachment_id || null })),
          prescriptions: this.form.prescriptions,
          treatment_given:      this.form.treatment_given       || null,
          vet_notes:            this.form.vet_notes             || null,
          follow_up_date:       this.form.follow_up_date        || null,
          follow_up_notes:      this.form.follow_up_notes       || null,
          weight_kg:            String(this.form.weight_kg ?? "").trim() || null,
          temperature_c:        numberOrNull(this.form.temperature_c),
          heart_rate_bpm:       numberOrNull(this.form.heart_rate_bpm),
          respiratory_rate_bpm: numberOrNull(this.form.respiratory_rate_bpm),
          body_condition_score: numberOrNull(this.form.body_condition_score),
          finish_case:          finishCase,
        };
        await API.clinicSaveRecord(this.apptId, payload);
        this.open = false;
        this.returnFocus?.focus();
        window.dispatchEvent(new CustomEvent("clinic-case-updated"));
      } catch (e) {
        if (e.errors) this.showValidationErrors(e.errors);
        else this.modalError = e.message || "Unable to save the record. Try again.";
      } finally {
        this.saving = false;
      }
    },
  };
}

// ── Clinic Records Page ───────────────────────────────────────────────────────

function adminClinicPage() {
  return {
    // Search state
    searchQuery: "",
    searchRows: [],
    noResults: false,
    searching: false,
    _timer: null,
    _reqId: 0,
    recordsPage: 1,
    hasMoreRecords: false,
    activeCases: [],
    activeCasesLoading: false,
    confinedCases: [], confinedCount: 0, confinedLoading: false, confinedError: '', confinedPage: 1, confinedLastPage: 1,
    _confinedRequest: 0,
    caseBusyId: null,
    accessError: "",
    payment: { open: false, case: null, method: "cash", saving: false, error: "" },
    paymentReturnFocus: null,
    tab: "active-cases",
    addCustomer: { open: false, saving: false, error: "", form: {} },

    // Patient profile panel
    profile: {
      open: false,
      loading: false,
      error: null,
      pet: null,
      owner: null,
      records: [],
    },
    _profileRequest: 0,
    profileReturnFocus: null,
    profilePageOverflow: null,
    profileScrollPosition: 0,
    profileRecordTrigger: null,
    profileVisit: null,
    profileBusy: false,
    profileActionError: '',
    profileEditing: false,
    profileEditorLoading: false,
    profileEditorSaving: false,

    // Owner's pet list (opened by View)
    petPicker: { open: false, owner: null, pets: [] },

    // Read-only consultation detail
    detail: { record: null },
    detailAttachmentBusyId: null,
    detailAttachmentError: "",

    async init() {
      const token = API.getAdminToken?.();
      const role  = API.getUserRole?.();
      if (!token || (role !== "admin" && role !== "staff")) {
        API.redirectToSignIn?.();
        return;
      }
      try {
        const response = await API.getStaffIdentity();
        if (!response.user?.clinic_permissions?.access) {
          this.accessError = "Your staff role does not have access to Clinic.";
          return;
        }
      } catch (error) { this.accessError = error.message || "Could not verify Clinic access."; return; }
      if (new URLSearchParams(window.location.search).get("tab") === "active-cases") this.tab = "active-cases";
      if (new URLSearchParams(window.location.search).get("tab") === "for-payment") this.tab = "for-payment";
      if (new URLSearchParams(window.location.search).get("tab") === "confined") { this.tab = "confined"; this.loadConfinedCases(); }
      this.loadActiveCases();
      this.loadAllRecords();
      window.addEventListener("clinic-case-updated", () => {
        this.loadActiveCases();
        if (this.tab === 'confined') this.loadConfinedCases();
      });
      window.addEventListener("clinic-review-payment", (event) => this.reviewPayment(event.detail));
    },

    async loadActiveCases() {
      this.activeCasesLoading = true;
      try {
        const response = await API.getActiveClinicCases();
        const visitWasListed = this.activeCases.some(item => item.id === this.profileVisit?.id);
        this.activeCases = response.cases || [];
        this.confinedCount = response.confined_count || 0;
        if (visitWasListed && !this.activeCases.some(item => item.id === this.profileVisit?.id)) this.profileVisit = null;
        window.dispatchEvent(new CustomEvent("clinic-cases-loaded", { detail: this.activeCases }));
        return true;
      }
      catch { this.activeCases = []; }
      finally { this.activeCasesLoading = false; }
    },

    get visibleActiveCases() {
      return this.tab === 'for-payment' ? this.activeCases.filter(item => item.status === 'for_payment') : this.currentActiveCases;
    },
    get currentActiveCases() { return this.activeCases.filter(item => ['checked_in', 'in_consultation'].includes(item.status) && !(item.confinement && !item.confinement.discharged_at)); },
    get activeCount() { return this.currentActiveCases.length; },
    get waitingCases() { return this.activeCases.filter(item => item.status === 'waiting_to_arrive'); },
    get caseRows() { return this.tab === 'for-payment' ? this.visibleActiveCases : [...this.visibleActiveCases, ...this.waitingCases]; },
    get forPaymentCount() { return this.activeCases.filter(item => item.status === 'for_payment').length; },
    async loadConfinedCases(page = 1) {
      const request = ++this._confinedRequest;
      this.confinedLoading = true; this.confinedError = '';
      try {
        const response = await API.getClinicConfinedCases(page);
        if (request !== this._confinedRequest) return;
        this.confinedCases = response.data; this.confinedCount = response.total;
        this.confinedPage = response.current_page; this.confinedLastPage = response.last_page;
      } catch (error) { if (request === this._confinedRequest) this.confinedError = error.message || 'Could not load confined patients.'; }
      finally { if (request === this._confinedRequest) this.confinedLoading = false; }
    },
    confinementDuration(caseItem) {
      const episode = caseItem?.confinement;
      return clinicConfinementDuration(episode?.admitted_at, episode?.discharged_at || this.$store.clinicConfinementClock?.now || Date.now());
    },
    setTab(tab) {
      this.tab = tab;
      const url = new URL(window.location.href);
      if (tab === "active-cases") url.searchParams.set("tab", "active-cases");
      else if (tab === 'for-payment') url.searchParams.set('tab', 'for-payment');
      else if (tab === 'confined') { url.searchParams.set('tab', 'confined'); this.loadConfinedCases(); }
      else url.searchParams.delete("tab");
      window.history.replaceState(null, "", url.pathname + url.search + url.hash);
      window.scrollTo(0, 0);
      this.$nextTick?.(() => { if (window.lucide) window.lucide.createIcons(); });
    },

    caseLabel(item) { return ({ online_request: "Online request", consultation: "Consultation", vaccination: "Vaccination" })[item.case_type] || "Clinic case"; },
    caseStatus(item) { return ({ waiting_to_arrive: "Waiting for Arrival", checked_in: "Checked In", in_consultation: "In Consultation", for_payment: "For Payment" })[item.status] || item.status; },
    caseActionLabel(item) {
      if (item.status === 'waiting_to_arrive' && this.permissions.intake) return 'Check In';
      if (item.status === 'checked_in' && this.permissions.clinical) return 'Start Consultation';
      if (item.status === 'for_payment' && this.permissions.payment) return 'Review & Collect Payment';
      return 'Open Case';
    },
    runCaseAction(item) {
      if (this.caseBusyId !== null) return;
      if (item.status === 'waiting_to_arrive' && this.permissions.intake) return this.checkInCase(item);
      if (item.status === 'checked_in' && this.permissions.clinical) return this.startCase(item);
      if (item.status === 'for_payment' && this.permissions.payment) return this.reviewPayment(item);
      this.openCase(item);
    },
    get permissions() { return this.$store.clinicAccess.permissions; },
    money(value) { return Number(value || 0).toLocaleString("en-PH", { style: "currency", currency: "PHP" }); },
    async checkInCase(item) {
      if (!this.permissions.intake || this.caseBusyId) return;
      this.caseBusyId = item.id;
      try { await API.clinicCheckIn(item.id); await this.loadActiveCases(); }
      catch (error) { this.$store.clinicFeedback.notify(error.message || "Could not check in this patient.", false); }
      finally { this.caseBusyId = null; }
    },
    reviewPayment(item) {
      if (!this.permissions.payment || item.status !== "for_payment") return;
      this.paymentReturnFocus = document.activeElement;
      this.payment = { open: true, case: item, method: "cash", saving: false, error: "" };
      this.$nextTick?.(() => document.getElementById('clinic-payment-method')?.focus());
    },
    trapPaymentFocus(event) {
      const controls = [...event.currentTarget.querySelectorAll('button, select')].filter(element => !element.disabled);
      const first = controls[0], last = controls.at(-1);
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    },
    closePayment() {
      if (this.payment.saving) return;
      this.payment.open = false;
      this.paymentReturnFocus?.focus();
    },
    async recordPayment() {
      if (!this.permissions.payment || this.payment.saving) return;
      this.payment.saving = true; this.payment.error = "";
      try {
        await API.clinicRecordPayment(this.payment.case.id, {
          total_amount: this.payment.case.total_amount, payment_method: this.payment.method,
        });
        window.dispatchEvent(new CustomEvent('clinic-case-patch', {detail: {id: this.payment.case.id, paid: true, status: 'completed'}}));
        this.payment.open = false;
        await this.loadActiveCases();
        this.$store.clinicFeedback.notify("Payment recorded. Visit completed.");
        window.dispatchEvent(new CustomEvent("clinic-case-updated"));
      } catch (error) {
        this.payment.error = error.message || "Could not record payment.";
        if (error.errors?.total_amount) {
          await this.loadActiveCases();
          const current = this.activeCases.find((item) => item.id === this.payment.case.id);
          if (current) this.payment.case = current;
        }
      }
      finally { this.payment.saving = false; }
    },
    caseOpened(item) { return item.created_at ? new Date(item.created_at).toLocaleString("en-PH", { dateStyle: "medium", timeStyle: "short" }) : "—"; },
    async cancelCase(item) {
      if (!this.permissions.queue) return;
      if (item.confinement && !item.confinement.discharged_at) return;
      const confirmed = await this.$store.clinicFeedback.confirm({
        title: "Cancel this case?",
        message: `The case for ${item.pet?.name || "this patient"} will be cancelled.`,
        confirmLabel: "Cancel Case",
        cancelLabel: "Keep Case",
        danger: true,
      });
      if (!confirmed) return;
      try { await API.clinicCancel(item.id); await this.loadActiveCases(); }
      catch (error) { this.$store.clinicFeedback.notify(error.message || "Could not cancel this case.", false); }
    },
    openCase(item, section) { window.dispatchEvent(new CustomEvent("clinic-open-modal", { detail: { appt: item, section: section || (item.case_type === "vaccination" ? "vaccinations" : "medical") } })); },
    async startCase(item) {
      if (!this.permissions.clinical || this.caseBusyId || item.status !== 'checked_in') return;
      this.caseBusyId = item.id;
      try { const response = await API.startClinicCase(item.id); await this.loadActiveCases(); this.openCase(response.case); }
      catch (error) { this.$store.clinicFeedback.notify(error.message || "Could not start this case.", false); }
      finally { this.caseBusyId = null; }
    },
    openAddCustomer() {
      if (!this.permissions.customers) return;
      this.addCustomer = { open: true, saving: false, error: "", form: { first_name: "", last_name: "", middle_name: "", phone: "", email: "", pet_name: "", species: "", breed: "" } };
    },
    closeAddCustomer() { if (!this.addCustomer.saving) this.addCustomer.open = false; },
    async saveCustomerRecord(confirmSimilarName = false) {
      if (!this.permissions.customers) return;
      this.addCustomer.saving = true; this.addCustomer.error = "";
      try {
        const form = this.addCustomer.form;
        const owner = await API.createUnregisteredCustomer({ first_name: form.first_name, last_name: form.last_name, middle_name: form.middle_name || null, phone: form.phone, email: form.email || null, confirm_similar_name: confirmSimilarName });
        const petResponse = await API.adminAddCustomerPet("unregistered", owner.customer.id, { pet_name: form.pet_name, species: form.species, breed: form.breed || null });
        const pet = petResponse.pet;
        this.searchRows.unshift({ owner: owner.customer, pets: [{ id: pet.pet_id, petName: pet.pet_name, species: pet.species, breed: pet.breed }] });
        this.noResults = false;
        this.addCustomer.open = false;
        this.$nextTick?.(() => { if (window.lucide) window.lucide.createIcons(); });
      } catch (error) {
        if (error.code === "similar_customer_name" && !confirmSimilarName) {
          const confirmed = await this.$store.clinicFeedback.confirm({
            title: "Similar customer found",
            message: error.message,
            confirmLabel: "Save Anyway",
          });
          if (confirmed) {
            this.addCustomer.saving = false;
            return this.saveCustomerRecord(true);
          }
        }
        this.addCustomer.error = error.errors ? Object.values(error.errors).flat().join(" ") : (error.message || "Customer record could not be saved.");
      } finally { this.addCustomer.saving = false; }
    },

    async loadAllRecords(append = false) {
      const rid = ++this._reqId;
      this.searching = true;
      this.noResults = false;
      const page = append ? this.recordsPage + 1 : 1;
      try {
        const res = await API.getClinicRecords(page);
        if (rid !== this._reqId) return;
        this.recordsPage = page;
        this.hasMoreRecords = !!res.has_more;
        this.searchRows = append ? this.searchRows.concat(res.rows || []) : (res.rows || []);
        this.noResults = this.searchRows.length === 0;
      } catch {
        if (rid !== this._reqId) return;
        if (!append) this.searchRows = [];
        this.noResults = false;
      } finally {
        if (rid === this._reqId) {
          this.searching = false;
          this.$nextTick?.(() => { if (window.lucide) window.lucide.createIcons(); });
        }
      }
    },

    _buildRows(res) {
      const rows = [];
      const rowsByOwner = new Map();
      const seenPetIds = new Set();

      for (const c of (res.customers || [])) {
        const pets = (c.pets || []).filter(p => !p.isArchived);
        pets.forEach(p => seenPetIds.add(String(p.id)));
        const row = { owner: c, pets };
        rowsByOwner.set(`${c.recordType}:${c.id}`, row);
        rows.push(row);
      }
      for (const p of (res.pets || [])) {
        if (seenPetIds.has(String(p.id))) continue;
        const key = `${p.ownerRecordType}:${p.ownerId}`;
        let row = rowsByOwner.get(key);
        if (!row) {
          row = {
            owner: {
              id: p.ownerId,
              recordType: p.ownerRecordType,
              fullName: p.ownerName,
              phone: null,
              email: null,
            },
            pets: [],
          };
          rowsByOwner.set(key, row);
          rows.push(row);
        }
        row.pets.push(p);
      }

      this.searchRows = rows;
      this.hasMoreRecords = false;
      this.noResults  = rows.length === 0;
    },

    async onSearchInput() {
      const q = this.searchQuery.trim();
      clearTimeout(this._timer);
      const rid = ++this._reqId;
      if (q.length === 0) {
        await this.loadAllRecords();
        return;
      }
      if (q.length < 2) {
        this.searchRows = [];
        this.noResults  = false;
        this.searching  = false;
        return;
      }
      this._timer = setTimeout(async () => {
        this.searching = true;
        try {
          const res = await API.searchWalkInCustomers(q);
          if (rid !== this._reqId) return;
          this._buildRows(res);
        } catch {
          if (rid !== this._reqId) return;
          this.searchRows = [];
          this.noResults  = false;
        } finally {
          if (rid === this._reqId) this.searching = false;
        }
      }, 350);
    },

    clearSearch() {
      clearTimeout(this._timer);
      this._reqId++;
      this.searchQuery = "";
      this.searchRows  = [];
      this.noResults   = false;
      this.searching   = false;
      this.loadAllRecords();
    },

    async openProfile(row, trigger = document.activeElement) {
      if (!row.pet?.id) return;
      const request = ++this._profileRequest;
      if (!this.profile.open) {
        this.profileReturnFocus = trigger;
        this.profilePageOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
      }
      this.detail = { record: null };
      this.profileVisit = null;
      this.profileActionError = '';
      this.profileEditing = false;
      this.profile = {
        open: true,
        loading: true,
        error: null,
        pet: row.pet,
        owner: row.owner,
        records: [],
      };
      this.$nextTick?.(() => {
        if (this.$refs.profileBody) this.$refs.profileBody.scrollTop = 0;
        this.$refs.profileClose?.focus();
        if (window.lucide) window.lucide.createIcons();
      });
      try {
        const [data, ownerData] = await Promise.all([
          API.adminGetPetProfile(row.pet.id),
          row.owner.recordType === 'unregistered'
            ? API.getUnregisteredCustomerDetails(row.owner.id)
            : API.getCustomerDetails(row.owner.id),
        ]);
        if (request !== this._profileRequest) return;
        const pet = ownerData.customer?.pets?.find(pet => String(pet.id) === String(row.pet.id));
        if (!pet) throw new Error('Patient information could not be found. Please reopen the profile.');
        this.profile.pet = pet;
        this.profile.owner = { ...row.owner, ...ownerData.customer };
        this.profile.records = data.medical_records || [];
      } catch (err) {
        if (request !== this._profileRequest) return;
        this.profile.error = err.message || "Failed to load pet profile.";
      } finally {
        if (request === this._profileRequest) {
          this.profile.loading = false;
          this.$nextTick?.(() => { if (window.lucide) window.lucide.createIcons(); });
        }
      }
    },

    closeProfile() {
      if (this.profileBusy || this.profileEditorSaving || this.profileEditorLoading) return;
      this._profileRequest++;
      this.profile.open = false;
      this.detail = { record: null };
      if (this.profilePageOverflow !== null) {
        document.body.style.overflow = this.profilePageOverflow;
        this.profilePageOverflow = null;
      }
      this.$nextTick?.(() => this.profileReturnFocus?.focus());
    },

    trapProfileFocus(event) {
      const controls = [...event.currentTarget.querySelectorAll('button, input, select, textarea, a[href], [tabindex="0"]')]
        .filter(element => !element.disabled && element.getClientRects().length);
      const first = controls[0], last = controls.at(-1);
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    },

    get patientName() { return this.profile.pet?.petName || this.profile.pet?.name || 'Patient'; },
    get patientConditions() { return String(this.profile.pet?.medicalConditions ?? this.profile.pet?.medical_conditions ?? '').trim(); },
    patientInformation() {
      const pet = this.profile.pet || {};
      const label = value => value ? String(value).replace(/_/g, ' ').replace(/\b\w/g, char => char.toUpperCase()) : '—';
      const status = value => value == null ? '—' : (value === true || value === 1 || value === '1' ? 'Yes' : 'No');
      const neutered = pet.isNeutered ?? pet.is_neutered;
      const deceased = pet.isDeceased ?? pet.is_deceased;
      return [
        {label: 'Species', value: label(pet.species)},
        {label: 'Breed', value: pet.breed || '—'},
        {label: 'Gender', value: label(pet.gender)},
        {label: 'Birthdate / Age', value: pet.birthdate ? `${this.fmtDate(pet.birthdate)} · ${this.petAge(pet)}` : '—'},
        {label: 'Weight', value: pet.weight != null && pet.weight !== '' ? `${pet.weight} kg` : '—'},
        {label: 'Size', value: pet.size ? `${label(pet.size)}${pet.sizeVerified ? ' · Clinic verified' : ''}` : '—'},
        {label: 'Color', value: pet.color || '—'},
        {label: 'Fur Type', value: label(pet.furType ?? pet.fur_type)},
        {label: 'Neutered / Spayed', value: status(neutered)},
        ...(status(neutered) === 'Yes' ? [{label: 'Neutered Date', value: this.fmtDate(pet.neuteredDate ?? pet.neutered_date)}] : []),
        {label: 'Deceased', value: status(deceased)},
        ...(status(deceased) === 'Yes' ? [{label: 'Deceased Date', value: this.fmtDate(pet.deceasedDate ?? pet.deceased_date)}] : []),
      ];
    },

    openPetPicker(row) {
      if (!row.pets?.length) return;
      this.petPicker = { open: true, owner: row.owner, pets: row.pets };
      this.$nextTick?.(() => { if (window.lucide) window.lucide.createIcons(); });
    },

    closePetPicker() {
      this.petPicker.open = false;
    },

    get currentClinicVisit() {
      return this.activeCases.find(item => String(item.pet?.id) === String(this.profile.pet?.id)) || this.profileVisit;
    },
    get profileActionLabel() { return this.currentClinicVisit ? 'Open Active Case' : 'Check In Patient'; },
    openProfileCase() {
      const visit = this.currentClinicVisit;
      if (!visit || this.profileBusy) return;
      this.closeProfile();
      this.closePetPicker();
      this.openCase(visit);
    },
    async checkInPatient() {
      if (!this.permissions.intake || !this.profile.pet?.id || this.profileBusy) return;
      if (this.currentClinicVisit?.status !== 'waiting_to_arrive' && this.currentClinicVisit) return;
      this.profileBusy = true;
      this.profileActionError = '';
      try {
        if (!await this.loadActiveCases()) throw new Error('Could not verify the current clinic visit. Please try again.');
        let visit = this.currentClinicVisit;
        if (visit && visit.status !== 'waiting_to_arrive') return;
        if (visit) {
          visit = (await API.clinicCheckIn(visit.id)).appointment;
        } else {
          try {
            visit = (await API.createClinicCase({ pet_id: this.profile.pet.id, case_type: 'consultation' })).case;
          } catch (error) {
            if (error.code !== 'active_case_exists' || !error.data?.case) throw error;
            this.profileVisit = error.data.case;
            await this.loadActiveCases();
            return;
          }
        }
        this.profileVisit = visit;
        await this.loadActiveCases();
      } catch (error) {
        this.profileActionError = error.message || 'Could not check in this patient.';
      } finally { this.profileBusy = false; }
    },
    async editPatient() {
      if (!this.permissions.access || this.profileEditorLoading || this.profileBusy) return;
      this.profileEditing = true;
      this.profileEditorLoading = true;
      this.profileActionError = '';
      try {
        await loadClinicPatientEditor();
        let panel = this.$refs.patientEditor.firstElementChild;
        if (!panel) {
          panel = document.createElement('div');
          panel.setAttribute('x-data', 'adminClinicPatientEditor()');
          panel.innerHTML = `<p x-show="petModal.saveError" role="alert" class="mb-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700" x-text="petModal.saveError"></p>
            <button type="button" @click="cancelEditPet()" :disabled="petModal.saving" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 disabled:opacity-50">Cancel</button>
            <div x-ref="petEditForm"></div>`;
          Alpine.mutateDom(() => { this.$refs.patientEditor.appendChild(panel); Alpine.initTree(panel); });
        }
        const editor = Alpine.$data(panel);
        editor.petModal.pet = this.profile.pet;
        editor.petModal.creating = false;
        editor.detailModal.customer = this.profile.owner;
        await editor.startEditPet();
        this.$nextTick?.(() => {
          this.$refs.profileBody.scrollTop = 0;
          panel.querySelector('#adminPetName')?.focus();
        });
      } catch (error) { this.profileActionError = error.message || 'Could not open patient editing.'; }
      finally { this.profileEditorLoading = false; }
    },
    cancelPatientEdit() {
      if (this.profileEditorLoading || this.profileEditorSaving) return;
      const panel = this.$refs.patientEditor.firstElementChild;
      if (panel) Alpine.$data(panel).cancelEditPet();
      else this.finishPatientEdit();
    },
    finishPatientEdit(pet) {
      if (pet) {
        this.profile.pet = pet;
        for (const row of this.searchRows) {
          const existing = row.pets?.find(item => String(item.id) === String(pet.id));
          if (existing) Object.assign(existing, pet);
        }
        const existing = this.petPicker.pets?.find(item => String(item.id) === String(pet.id));
        if (existing) Object.assign(existing, pet);
      }
      this.profileEditing = false;
      this.$nextTick?.(() => this.$refs.editPatient?.focus());
    },

    viewDetail(record, trigger = document.activeElement) {
      this.profileScrollPosition = this.$refs.profileBody?.scrollTop || 0;
      this.profileRecordTrigger = trigger?.querySelector?.('button') || trigger;
      this.detail = { record };
      this.detailAttachmentError = "";
      this.$nextTick?.(() => {
        if (this.$refs.profileBody) this.$refs.profileBody.scrollTop = 0;
        this.$refs.profileBack?.focus();
        if (window.lucide) window.lucide.createIcons();
      });
    },

    backToProfile() {
      this.detail = { record: null };
      this.$nextTick?.(() => {
        if (this.$refs.profileBody) this.$refs.profileBody.scrollTop = this.profileScrollPosition;
        this.profileRecordTrigger?.focus({ preventScroll: true });
      });
    },

    formatFileSize: formatClinicFileSize,

    async downloadDetailAttachment(attachment) {
      this.detailAttachmentBusyId = attachment.id;
      this.detailAttachmentError = "";
      try {
        await downloadClinicAttachment(this.detail.record.appointment_id, attachment);
      } catch (error) {
        this.detailAttachmentError = error.message || "Failed to download attachment.";
      } finally {
        this.detailAttachmentBusyId = null;
      }
    },

    petAge(pet) {
      if (!pet?.birthdate) return null;
      const yrs = Math.floor((Date.now() - new Date(pet.birthdate)) / (365.25 * 24 * 3600 * 1000));
      return yrs <= 0 ? "< 1 yr" : `${yrs} yr${yrs === 1 ? "" : "s"}`;
    },

    petSummary(pet) {
      if (!pet) return "—";
      return [pet.species, pet.breed].filter(Boolean).join(" · ") || "Pet";
    },

    ownerInitials(name) {
      return String(name || "?").trim().split(/\s+/).slice(0, 2).map(w => w[0]).join("").toUpperCase();
    },

    fmtDate(d) {
      if (!d) return "—";
      try { return new Date(d).toLocaleDateString("en-PH", { year: "numeric", month: "short", day: "numeric" }); }
      catch { return d; }
    },
  };
}

let clinicPatientEditorScript;
function loadClinicPatientEditor() {
  return clinicPatientEditorScript ??= new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = '../../scripts/components/admin-customers.js?v=customer-actions-20260927&pet-weight=pet-weight-size-20261011-v3&shared-editor=20261014';
    script.onload = resolve;
    script.onerror = () => { clinicPatientEditorScript = null; script.remove(); reject(new Error('Could not load patient editing. Please try again.')); };
    document.head.appendChild(script);
  });
}

function adminClinicPatientEditor() {
  const editor = adminCustomers();
  const save = editor.saveEditPet;
  const cancel = editor.cancelEditPet;
  return Object.assign(editor, {
    init() {},
    async saveEditPet() {
      if (!this.$store.clinicAccess.permissions.access) return;
      this.$dispatch('clinic-patient-edit-saving', true);
      try {
        await save.call(this);
        if (!this.petModal.editing && !this.petModal.saveError) this.$dispatch('clinic-patient-edit-finished', {pet: this.petModal.pet});
      } finally { this.$dispatch('clinic-patient-edit-saving', false); }
    },
    cancelEditPet() {
      if (this.petModal.saving) return;
      cancel.call(this);
      this.$dispatch('clinic-patient-edit-finished', {});
    },
  });
}
