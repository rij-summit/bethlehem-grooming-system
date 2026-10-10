const VACCINATION_ADMINISTRATION_ROUTES = [
  { value: "subcutaneous", label: "Subcutaneous" },
  { value: "intramuscular", label: "Intramuscular" },
  { value: "intranasal", label: "Intranasal" },
  { value: "oral", label: "Oral" },
  { value: "other", label: "Other" },
];

// Current Inventory use and historical documentation have separate stock behavior.
function vaccinationToday() {
  return clinicLocalDate(new Date());
}

function emptyVaccinationDraftForm() {
  return {
    inventory_item_id: "",
    vaccine_name: "",
    product_name: "",
    manufacturer: "",
    batch_number: "",
    product_expiry_date: "",
    administered_by_name: "",
    administered_date: vaccinationToday(),
    next_due_date: "",
    dose_amount: "",
    dose_unit: "",
    route: "",
    administration_site: "",
    administered_by_user_id: "",
    notes: "",
  };
}

function adminClinicVaccinations() {
  return {
    pet: null,
    appointment: null,
    records: [],
    loading: false,
    error: "",
    petNotFound: false,
    actionBusyId: null,
    administrationRoutes: VACCINATION_ADMINISTRATION_ROUTES,

    vaccineOptions: [],
    veterinarians: [],
    currentUserId: null,
    canManageStaff: false,
    optionsLoading: false,
    optionsError: "",

    formModal: {
      open: false,
      mode: "create",
      recordId: null,
      saving: false,
      clinicAppointmentId: null,
      unavailableVaccineName: "",
    },
    vaccineQuery: "",
    selectedVaccine: null,
    recordKind: "inventory",
    form: emptyVaccinationDraftForm(),
    formErrors: {},
    formErrorSummary: "",

    detailModal: {
      open: false,
      loading: false,
      error: "",
      recordId: null,
      record: null,
    },

    voidModal: {
      open: false,
      saving: false,
      error: "",
      reason: "",
      record: null,
    },


    init() {
      window.addEventListener("clinic-vaccinations-open", (event) => {
        this.openForAppointment(event.detail?.appt);
      });
    },

    openForAppointment(appt) {
      if (!appt?.pet?.id) {
        this.pet = null;
        this.appointment = appt || null;
        this.records = [];
        this.loading = false;
        this.petNotFound = true;
        this.error = "This appointment does not have a patient record that can receive vaccination history.";
        return;
      }

      const changedPet = Number(this.pet?.id) !== Number(appt.pet.id);
      this.pet = { ...appt.pet };
      this.appointment = {
        id: appt.id,
        appointment_reference: appt.appointment_reference,
        status: appt.status,
      };
      this.petNotFound = false;
      this.error = "";

      if (changedPet) {
        this.records = [];
        this.closeChildDialogs();
      }

      this.loadVaccinations();
      if (this.canEditClinical) this.loadOptions();
    },

    closeChildDialogs() {
      this.formModal.open = false;
      this.detailModal.open = false;
      this.voidModal.open = false;
    },

    async loadVaccinations() {
      if (!this.pet?.id) return;

      const requestedPetId = Number(this.pet.id);
      this.loading = true;
      this.error = "";
      this.petNotFound = false;

      try {
        const response = await API.getAdminPetVaccinations(requestedPetId);
        if (Number(this.pet?.id) !== requestedPetId) return;
        this.pet = {
          ...this.pet,
          id: response.pet?.pet_id ?? this.pet.id,
          name: response.pet?.pet_name ?? this.pet.name,
          species: response.pet?.species ?? this.pet.species,
        };
        this.records = Array.isArray(response.vaccinations)
          ? response.vaccinations
          : [];
      } catch (error) {
        if (Number(this.pet?.id) !== requestedPetId) return;
        this.records = [];
        this.petNotFound = error.status === 404;
        this.error = this.petNotFound
          ? "The selected pet could not be found."
          : (error.message || "Vaccination history could not be loaded.");
      } finally {
        if (Number(this.pet?.id) === requestedPetId) {
          this.loading = false;
        }
      }
    },

    async loadOptions() {
      if (this.optionsLoading) return;
      this.optionsLoading = true;
      this.optionsError = "";

      try {
        const response = await API.getAdminVaccinationOptions();
        this.vaccineOptions = Array.isArray(response.vaccines) ? response.vaccines : [];
        this.veterinarians = Array.isArray(response.veterinarians) ? response.veterinarians : [];
        this.currentUserId = response.current_user_id ?? null;
        this.canManageStaff = Boolean(response.can_manage_staff);
        if (this.formModal.open && this.recordKind === "inventory" && this.form.inventory_item_id) {
          this.restoreVaccineSelection();
        }
        if (this.formModal.open && this.recordKind === "inventory" && this.formModal.mode === "create" && !this.form.administered_by_user_id) {
          this.form.administered_by_user_id = this.currentUserId ? String(this.currentUserId) : "";
        }
      } catch (error) {
        this.optionsError = error.message || "Clinic vaccine inventory could not be loaded.";
      } finally {
        this.optionsLoading = false;
      }
    },

    // New vaccinations can only be added while the linked case is ongoing.
    get canEditClinical() { return !!this.$store.clinicAccess.permissions.clinical; },

    caseOngoing() {
      return !this.appointment?.id
        || ["checked_in", "in_consultation"].includes(this.appointment.status)
        || (API.getUserRole?.() === "admin" && this.appointment.status === "for_payment");
    },

    openCreateForm() {
      if (!this.canEditClinical) return;
      if (!this.pet?.id || !this.caseOngoing()) return;

      this.form = emptyVaccinationDraftForm();
      this.recordKind = "inventory";
      this.form.administered_by_user_id = this.currentUserId ? String(this.currentUserId) : "";
      this.formModal = {
        open: true,
        mode: "create",
        recordId: null,
        saving: false,
        clinicAppointmentId: this.appointment?.id ?? null,
        unavailableVaccineName: "",
      };
      this.clearVaccine();
      this.formErrors = {};
      this.formErrorSummary = "";
      this.refreshIcons();
    },

    openEditForm(record) {
      if (!this.canEditClinical) return;
      if (record?.state !== "draft") return;

      this.form = {
        inventory_item_id: record.inventory_item_id ? String(record.inventory_item_id) : "",
        vaccine_name: record.vaccine_name || "",
        product_name: record.product_name || "",
        manufacturer: record.manufacturer || "",
        batch_number: record.batch_number || "",
        product_expiry_date: record.product_expiry_date || "",
        administered_by_name: record.administered_by_name || "",
        administered_date: record.administered_date || "",
        next_due_date: record.next_due_date || "",
        dose_amount: record.dose_amount ?? "",
        dose_unit: record.dose_unit || "",
        route: record.route || "",
        administration_site: record.administration_site || "",
        administered_by_user_id: record.administered_by_user_id
          ? String(record.administered_by_user_id)
          : "",
        notes: record.notes || "",
      };
      this.formModal = {
        open: true,
        mode: "edit",
        recordId: record.id,
        saving: false,
        clinicAppointmentId: record.clinic_appointment_id ?? null,
        unavailableVaccineName: "",
      };
      this.recordKind = record.inventory_item_id && record.administered_date === vaccinationToday() ? "inventory" : "historical";
      this.selectedVaccine = null;
      this.vaccineQuery = "";
      if (!this.optionsLoading && !this.optionsError && this.recordKind === "inventory") this.restoreVaccineSelection();

      this.formErrors = {};
      this.formErrorSummary = "";
      this.refreshIcons();
    },

    restoreVaccineSelection() {
      const vaccine = this.vaccineOptions.find(
        (item) => Number(item.item_id) === Number(this.form.inventory_item_id),
      );
      if (vaccine && this.vaccineSelectable(vaccine)) {
        this.selectVaccine(vaccine);
      } else {
        this.formModal.unavailableVaccineName = this.form.vaccine_name;
      }
    },

    closeForm() {
      if (this.formModal.saving) return;
      this.formModal.open = false;
      this.formErrors = {};
      this.formErrorSummary = "";
    },

    filteredVaccines() {
      const query = this.vaccineQuery.trim().toLowerCase();
      if (!query) return [];
      return this.vaccineOptions.filter(
        (item) => String(item.item_name || "").toLowerCase().includes(query),
      );
    },

    vaccineSelectable(item) {
      return item?.deduction_supported === true && Number(item?.available_quantity) >= 1 && Boolean(item?.next_batch);
    },

    vaccineAvailabilityLabel(item) {
      if (item?.deduction_supported === false) return "Single-dose unit required";
      if (this.vaccineSelectable(item)) {
        const quantity = Math.floor(Number(item.available_quantity));
        return `${quantity}${item.unit ? ` ${item.unit}` : ""} available`;
      }
      return Number(item?.available_quantity) > 0 ? "No unexpired stock" : "Out of stock";
    },

    selectVaccine(item) {
      if (!this.vaccineSelectable(item)) return;
      this.selectedVaccine = item;
      this.form.inventory_item_id = String(item.item_id);
      this.formModal.unavailableVaccineName = "";
      delete this.formErrors.inventory_item_id;
      this.refreshIcons();
    },

    clearVaccine() {
      this.selectedVaccine = null;
      this.form.inventory_item_id = "";
      this.vaccineQuery = "";
      this.refreshIcons();
    },

    providerOptionLabel(provider) {
      return provider.is_current ? `${provider.name} (You)` : provider.name;
    },

    validateForm() {
      const errors = {};
      const value = (field) => String(this.form[field] ?? "").trim();
      const maxLength = (field, max, label) => {
        if (value(field).length > max) {
          errors[field] = `${label} must not exceed ${max} characters.`;
        }
      };

      const usesInventory = this.recordKind === "inventory";
      if (usesInventory && !value("inventory_item_id")) {
        errors.inventory_item_id = "Select a vaccine from clinic inventory.";
      }
      if (usesInventory && !value("administered_by_user_id")) {
        errors.administered_by_user_id = "Select who administered the vaccine.";
      }

      const dose = Number(value("dose_amount"));
      if (usesInventory && !value("dose_amount")) {
        errors.dose_amount = "Dose amount is required.";
      } else if (value("dose_amount") && (!Number.isFinite(dose) || dose <= 0)) {
        errors.dose_amount = "Dose amount must be greater than zero.";
      } else if (dose > 99999.999) {
        errors.dose_amount = "Dose amount must not exceed 99,999.999.";
      }

      if (usesInventory && !value("dose_unit")) {
        errors.dose_unit = "Dose unit is required.";
      }
      maxLength("dose_unit", 30, "Dose unit");
      maxLength("administration_site", 100, "Administration site");

      if (
        value("route")
        && !this.administrationRoutes.some((route) => route.value === value("route"))
      ) {
        errors.route = "Choose a supported administration route.";
      }

      if (usesInventory && value("next_due_date") && value("next_due_date") < vaccinationToday()) {
        errors.next_due_date = "Next vaccination date cannot be before today.";
      }
      if (!usesInventory) {
        if (!value("vaccine_name")) errors.vaccine_name = "Vaccine name is required.";
        if (!value("administered_date") || value("administered_date") > vaccinationToday()) {
          errors.administered_date = "Enter the actual administration date, on or before today.";
        }
        for (const field of ["next_due_date", "product_expiry_date"]) {
          if (value(field) && value(field) < value("administered_date")) errors[field] = "This date must be on or after the administration date.";
        }
      }

      this.formErrors = errors;
      this.formErrorSummary = Object.values(errors)[0] || "";

      if (this.formErrorSummary) {
        this.focusValidationSummary();
        return false;
      }

      return true;
    },

    buildPayload() {
      const nullableText = (field) => {
        const text = String(this.form[field] ?? "").trim();
        return text || null;
      };
      const nullableId = (field) => {
        const raw = String(this.form[field] ?? "").trim();
        return raw ? Number(raw) : null;
      };

      return {
        inventory_item_id: this.recordKind === "inventory" ? nullableId("inventory_item_id") : null,
        ...(this.recordKind === "historical" ? {
          vaccine_name: nullableText("vaccine_name"),
          administered_date: nullableText("administered_date"),
          product_name: nullableText("product_name"),
          manufacturer: nullableText("manufacturer"),
          batch_number: nullableText("batch_number"),
          product_expiry_date: nullableText("product_expiry_date"),
          administered_by_name: nullableText("administered_by_name"),
        } : {}),
        next_due_date: nullableText("next_due_date"),
        dose_amount: nullableText("dose_amount"),
        dose_unit: nullableText("dose_unit"),
        route: nullableText("route"),
        administration_site: nullableText("administration_site"),
        administered_by_user_id: nullableId("administered_by_user_id"),
        clinic_appointment_id: this.formModal.clinicAppointmentId
          ? Number(this.formModal.clinicAppointmentId)
          : null,
        notes: nullableText("notes"),
      };
    },

    async saveDraft() {
      if (!this.canEditClinical) return;
      if (this.formModal.saving || !this.validateForm()) return;

      this.formModal.saving = true;
      this.formErrors = {};
      this.formErrorSummary = "";

      try {
        const payload = this.buildPayload();
        const editing = this.formModal.mode === "edit";
        const response = editing
          ? await API.updateAdminPetVaccination(
              this.pet.id,
              this.formModal.recordId,
              payload,
            )
          : await API.createAdminPetVaccination(this.pet.id, payload);

        this.formModal.open = false;
        await this.loadVaccinations();
        this.showToast(
          response.message || (editing
            ? "Vaccination draft updated."
            : "Vaccination draft created."),
        );
      } catch (error) {
        this.applyBackendValidation(error, "Vaccination draft could not be saved.");
      } finally {
        this.formModal.saving = false;
      }
    },

    async saveAndFinishCase() {
      if (!this.canEditClinical) return;
      if (this.recordKind !== "inventory") return;
      if (this.appointment?.status !== "in_consultation" || this.formModal.saving || !this.validateForm()) return;
      if (!(await this.confirmDeduction(this.selectedVaccine, "Deduct and Finish Case"))) return;

      this.formModal.saving = true;
      try {
        const payload = this.buildPayload();
        const editing = this.formModal.mode === "edit";
        const saved = await (editing
          ? API.updateAdminPetVaccination(this.pet.id, this.formModal.recordId, payload)
          : API.createAdminPetVaccination(this.pet.id, payload));
        // A retry after a failed finalize must update this draft, not create another.
        this.formModal.mode = "edit";
        this.formModal.recordId = saved.vaccination?.id ?? this.formModal.recordId;

        await API.publishAdminPetVaccination(this.pet.id, this.formModal.recordId, { finishCase: true, consumeInventory: true });
        this.formModal.open = false;
        this.appointment.status = "for_payment";
        await this.loadVaccinations();
        this.loadOptions();
        window.dispatchEvent(new CustomEvent("clinic-case-updated"));
        this.showToast("Vaccination saved and stock deducted. Case is For Payment.");
      } catch (error) {
        this.loadVaccinations();
        this.applyBackendValidation(error, "The vaccination could not be finalized. The case remains active.");
      } finally {
        this.formModal.saving = false;
      }
    },

    confirmDeduction(vaccine, confirmLabel) {
      const name = vaccine?.item_name || "the selected vaccine";
      const unit = vaccine?.unit || "unit";
      const batch = vaccine?.next_batch?.batch_number;
      return this.$store.clinicFeedback.confirm({
        title: "Deduct from clinic inventory?",
        message: `1 ${unit} of ${name}${batch ? ` (batch ${batch})` : ""} will be deducted from clinic inventory.\n\n`
          + "The record will be published and can no longer be edited.",
        confirmLabel,
      });
    },

    applyBackendValidation(error, fallback) {
      const backendErrors = error.errors || {};
      this.formErrors = Object.fromEntries(
        Object.entries(backendErrors).map(([field, messages]) => [
          field,
          Array.isArray(messages) ? messages.join(" ") : String(messages),
        ]),
      );
      this.formErrorSummary = error.message || fallback;
      this.focusValidationSummary();
    },

    fieldError(field) {
      return this.formErrors[field] || "";
    },

    focusValidationSummary() {
      this.$nextTick(() => this.$refs.vaccinationValidationSummary?.focus());
    },

    async publishRecord(record) {
      if (!this.canEditClinical) return;
      if (record?.state !== "draft" || this.actionBusyId) return;

      const usesInventory = Boolean(record.inventory_item_id);
      const missingDose = record.dose_amount === null || record.dose_amount === undefined
        || !String(record.dose_unit || "").trim();
      if ((usesInventory && (record.administered_date !== vaccinationToday() || !record.administered_by_user_id || missingDose))
        || (!usesInventory && !record.administered_by_user_id && !record.administered_by_name)) {
        this.openEditForm(record);
        if (!record.administered_by_user_id) {
          this.formErrors = { administered_by_user_id: "Select who administered the vaccine before publishing." };
        } else if (record.administered_date !== vaccinationToday()) {
          this.formErrors = { administered_date: "Save this as a historical or external record to preserve its date without deducting current stock." };
        } else {
          this.formErrors = { dose_amount: "Add the dose amount and unit before publishing." };
        }
        this.formErrorSummary = Object.values(this.formErrors)[0];
        this.focusValidationSummary();
        this.showToast("Complete the vaccination details before publication.", false);
        return;
      }

      const vaccine = this.vaccineOptions.find(
        (item) => Number(item.item_id) === Number(record.inventory_item_id),
      ) || { item_name: record.vaccine_name };
      const confirmed = usesInventory
        ? await this.confirmDeduction(vaccine, "Deduct and Publish")
        : await this.$store.clinicFeedback.confirm({ title: "Publish historical or external record?", message: "No current Inventory stock will be deducted. The record will be published and can no longer be edited.", confirmLabel: "Publish Record" });
      if (!confirmed) return;

      this.actionBusyId = record.id;
      try {
        const response = await API.publishAdminPetVaccination(this.pet.id, record.id, { consumeInventory: usesInventory });
        await this.loadVaccinations();
        this.loadOptions();
        window.dispatchEvent(new CustomEvent("clinic-case-updated"));
        this.showToast(response.message || "Vaccination record published.");
      } catch (error) {
        this.showToast(error.message || "Vaccination record could not be published.", false);
      } finally {
        this.actionBusyId = null;
      }
    },

    openVoidDialog(record) {
      if (!this.canEditClinical) return;
      if (record?.state !== "published" || this.actionBusyId) return;
      this.voidModal = {
        open: true,
        saving: false,
        error: "",
        reason: "",
        record,
      };
      this.refreshIcons();
    },

    closeVoidDialog() {
      if (this.voidModal.saving) return;
      this.voidModal.open = false;
      this.voidModal.error = "";
      this.voidModal.reason = "";
      this.voidModal.record = null;
    },

    async submitVoid() {
      if (!this.canEditClinical) return;
      const reason = String(this.voidModal.reason || "").trim();
      if (!reason) {
        this.voidModal.error = "A reason is required to void this published record.";
        return;
      }
      if (reason.length > 500) {
        this.voidModal.error = "The void reason must not exceed 500 characters.";
        return;
      }
      if (this.voidModal.saving) return;

      const record = this.voidModal.record;
      this.voidModal.saving = true;
      this.voidModal.error = "";
      this.actionBusyId = record.id;

      try {
        const response = await API.voidAdminPetVaccination(
          this.pet.id,
          record.id,
          reason,
        );
        this.voidModal.open = false;
        await this.loadVaccinations();
        this.showToast(response.message || "Vaccination record voided.");
      } catch (error) {
        const reasonErrors = error.errors?.void_reason;
        this.voidModal.error = (Array.isArray(reasonErrors)
          ? reasonErrors.join(" ")
          : reasonErrors)
          || error.message
          || "Vaccination record could not be voided.";
      } finally {
        this.voidModal.saving = false;
        this.actionBusyId = null;
      }
    },

    async openDetails(record) {
      this.detailModal = {
        open: true,
        loading: true,
        error: "",
        recordId: record.id,
        record: null,
      };
      this.refreshIcons();

      try {
        const response = await API.getAdminPetVaccination(this.pet.id, record.id);
        this.detailModal.record = response.vaccination || null;
      } catch (error) {
        this.detailModal.error = error.message || "Vaccination details could not be loaded.";
      } finally {
        this.detailModal.loading = false;
      }
    },

    retryDetails() {
      const fallback = this.records.find(
        (record) => Number(record.id) === Number(this.detailModal.recordId),
      );
      if (fallback) this.openDetails(fallback);
    },

    closeDetails() {
      this.detailModal.open = false;
      this.detailModal.record = null;
      this.detailModal.error = "";
    },

    stateLabel(state) {
      return {
        draft: "Draft",
        published: "Published",
        voided: "Voided",
      }[state] || "Unknown state";
    },

    stateBadgeClass(state) {
      return {
        draft: "border-amber-200 bg-amber-50 text-amber-800",
        published: "border-emerald-200 bg-emerald-50 text-emerald-700",
        voided: "border-red-200 bg-red-50 text-red-700",
      }[state] || "border-slate-200 bg-slate-50 text-slate-600";
    },

    dueLabel(status) {
      return {
        current: "Current",
        due_soon: "Due soon",
        overdue: "Overdue",
        unknown: "Unknown",
      }[status] || "Unknown";
    },

    dueBadgeClass(status) {
      return {
        current: "border-emerald-200 bg-emerald-50 text-emerald-700",
        due_soon: "border-amber-200 bg-amber-50 text-amber-800",
        overdue: "border-red-200 bg-red-50 text-red-700",
        unknown: "border-slate-200 bg-slate-50 text-slate-600",
      }[status] || "border-slate-200 bg-slate-50 text-slate-600";
    },

    formatDate(value) {
      if (!value) return "Not provided";
      const date = new Date(`${String(value).slice(0, 10)}T00:00:00`);
      if (Number.isNaN(date.getTime())) return "Not provided";
      return date.toLocaleDateString("en-PH", {
        year: "numeric",
        month: "short",
        day: "numeric",
      });
    },

    formatDateTime(value) {
      if (!value) return "Not provided";
      const date = new Date(value);
      if (Number.isNaN(date.getTime())) return "Not provided";
      return date.toLocaleString("en-PH", {
        year: "numeric",
        month: "short",
        day: "numeric",
        hour: "numeric",
        minute: "2-digit",
      });
    },

    displayValue(value) {
      return value === null || value === undefined || String(value).trim() === ""
        ? "Not provided"
        : String(value);
    },

    doseLabel(record) {
      if (record?.dose_amount === null || record?.dose_amount === undefined || record?.dose_amount === "") {
        return "Not provided";
      }
      return `${record.dose_amount}${record.dose_unit ? ` ${record.dose_unit}` : ""}`;
    },

    providerLabel(record) {
      return record?.administered_by_name
        || record?.administered_by_user_name
        || "Not provided";
    },

    showToast(message, ok = true) {
      this.$store.clinicFeedback.notify(message, ok);
    },

    refreshIcons() {
      this.$nextTick(() => {
        if (window.lucide) window.lucide.createIcons();
      });
    },
  };
}
