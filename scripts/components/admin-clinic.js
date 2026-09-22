const adminClinicPetFieldModulesReady = Promise.all([
  import("./breed-combobox.js"),
  import("./breed-coat-catalogue.js"),
  import("./breed-coat-combobox.js"),
  import("./fixed-option-combobox.js"),
  import("./pet-weight-size.js"),
]).then(([breedCombobox, breedCoatCatalogue, breedCoatCombobox, fixedOptionCombobox, petWeightSize]) => ({
  ...breedCombobox,
  ...breedCoatCatalogue,
  ...breedCoatCombobox,
  ...fixedOptionCombobox,
  ...petWeightSize,
}));

let adminClinicPetFields = null;

function escapeHtml(str) {
  return String(str ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

function formatTime(iso) {
  if (!iso) return "—";
  try {
    return new Date(iso).toLocaleTimeString("en-PH", { hour: "2-digit", minute: "2-digit", hour12: true });
  } catch {
    return "—";
  }
}

const STATUS_LABEL = {
  waiting_to_arrive: "Waiting to Arrive",
  checked_in:        "In Queue",
  in_consultation:   "In Consultation",
  for_payment:       "For Payment",
  completed:         "Completed",
  cancelled:         "Cancelled",
};

const STATUS_BADGE = {
  waiting_to_arrive: "bg-slate-100 text-slate-600",
  checked_in:        "bg-blue-100 text-blue-700",
  in_consultation:   "bg-amber-100 text-amber-700",
  for_payment:       "bg-purple-100 text-purple-700",
  completed:         "bg-emerald-100 text-emerald-700",
  cancelled:         "bg-red-100 text-red-700",
};

function buildClinicActionButtons(appt) {
  const id = appt.id;
  const s  = appt.status;

  const btn = (label, action, cls) =>
    `<button type="button" onclick="window.__clinicAction('${action}',${id})" class="${cls} rounded-xl px-3 py-1.5 text-xs font-semibold transition">${escapeHtml(label)}</button>`;

  const primary = "bg-[#315b7e] text-white hover:bg-[#274864]";
  const danger  = "border border-red-200 bg-red-50 text-red-600 hover:bg-red-100";
  const outline = "border border-slate-200 bg-white text-slate-600 hover:bg-slate-50";

  const parts = [];
  if (s === "waiting_to_arrive") { parts.push(btn("Check In", "check-in", primary)); parts.push(btn("Cancel", "cancel", danger)); }
  if (s === "checked_in")        { parts.push(btn("Start Consultation", "start-consultation", primary)); parts.push(btn("Record", "record", outline)); parts.push(btn("Cancel", "cancel", danger)); }
  if (s === "in_consultation")   { parts.push(btn("Finish Consultation", "finish-consultation", primary)); parts.push(btn("Record", "record", outline)); }
  if (s === "for_payment")       { parts.push(btn("Process Payment", "pay", primary)); parts.push(btn("Record", "record", outline)); }
  if (s === "completed")         { parts.push(btn("View Record", "record", outline)); }
  if (appt.pet?.id)              { parts.push(btn("Vaccinations", "vaccinations", outline)); }
  return parts.join("");
}

function buildClinicCard(appt) {
  const pet     = appt.pet || {};
  const typeBadge = appt.appointment_type === "walk_in"
    ? `<span class="rounded-full bg-orange-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-orange-600">Walk-in</span>`
    : `<span class="rounded-full bg-teal-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-teal-600">Pre-reg</span>`;
  const statusBadge = `<span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide ${STATUS_BADGE[appt.status] || ''}">${STATUS_LABEL[appt.status] || appt.status}</span>`;
  const deletedAccountBadge = appt.status === "completed" && appt.ownerAccountDeleted
    ? `<span class="inline-flex items-center gap-1 rounded-full border border-slate-300 bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-600">Account deleted</span>`
    : "";
  const queueLabel = appt.queue_number
    ? `<span class="text-xs font-bold text-[#315b7e]">#${appt.queue_number}</span>`
    : `<span class="text-[10px] font-semibold text-slate-400">Queue at check-in</span>`;
  const timeLabel = appt.time_window?.window_label
    ? `<p class="mt-0.5 text-xs font-semibold text-[#315b7e]">${escapeHtml(appt.time_window.window_label)}</p>`
    : "";

  let timeline = "";
  if (appt.checked_in_at)            timeline += `<p class="text-xs text-slate-400">Checked in: <span class="font-medium text-slate-600">${formatTime(appt.checked_in_at)}</span></p>`;
  if (appt.consultation_started_at)  timeline += `<p class="text-xs text-slate-400">Consult started: <span class="font-medium text-slate-600">${formatTime(appt.consultation_started_at)}</span></p>`;
  if (appt.consultation_finished_at) timeline += `<p class="text-xs text-slate-400">Consult ended: <span class="font-medium text-slate-600">${formatTime(appt.consultation_finished_at)}</span></p>`;

  const petSection = pet.name
    ? `<div class="mb-3 rounded-xl border border-slate-100 bg-slate-50 px-3 py-2">
         <p class="text-xs font-semibold text-slate-500">Patient</p>
         <p class="text-sm font-semibold text-[#2f4b66]">${escapeHtml(pet.name)}</p>
         <p class="text-xs text-slate-400">${escapeHtml(pet.species || "")}${pet.breed ? " · " + escapeHtml(pet.breed) : ""}${pet.weight ? " · " + pet.weight + " kg" : ""}</p>
       </div>` : "";

  const complaintSection = appt.chief_complaint
    ? `<div class="mb-3 rounded-xl border border-slate-100 bg-slate-50 px-3 py-2">
         <p class="text-xs font-semibold text-slate-500">Chief Complaint</p>
         <p class="text-sm text-slate-700">${escapeHtml(appt.chief_complaint)}</p>
       </div>` : "";

  return `<article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
    <div class="mb-3 flex items-start justify-between gap-2">
      <div>
        <div class="flex flex-wrap items-center gap-2">
          <span class="text-base font-bold text-[#2f4b66]">${escapeHtml(appt.ownerName)}</span>
          ${typeBadge}
          ${deletedAccountBadge}
        </div>
        <p class="mt-0.5 text-xs text-slate-400">${escapeHtml(appt.appointment_reference)}</p>
        ${timeLabel}
      </div>
      <div class="flex shrink-0 flex-col items-end gap-1">
        ${statusBadge}
        ${queueLabel}
      </div>
    </div>
    ${petSection}${complaintSection}
    ${timeline ? `<div class="mb-3 space-y-0.5">${timeline}</div>` : ""}
    <div class="flex flex-wrap gap-2">${buildClinicActionButtons(appt)}</div>
  </article>`;
}

// ── Main component ────────────────────────────────────────────────────────────

function adminClinic() {
  return {
    loading: true,
    error:   "",
    activeTab: "queued",

    incoming:       [],
    queued:         [],
    inConsultation: [],
    forPayment:     [],
    completed:      [],

    tabs: [
      { key: "incoming",     label: "Incoming" },
      { key: "queued",       label: "In Queue" },
      { key: "consultation", label: "In Consultation" },
      { key: "payment",      label: "For Payment" },
      { key: "completed",    label: "Completed" },
    ],

    todayLabel: new Date().toLocaleDateString("en-PH", { weekday: "long", month: "long", day: "numeric" }),

    init() {
      this.checkAuth();
      this.loadQueue();
      this.bindGlobalActions();
    },

    checkAuth() {
      const token = API.getAdminToken?.();
      const role  = API.getUserRole?.();
      if (!token || (role !== "admin" && role !== "staff")) {
        API.redirectToSignIn?.();
      }
    },

    async loadQueue() {
      this.loading = true;
      this.error   = "";
      try {
        const data = await API.getClinicAppointments();
        this.incoming       = data.incoming        || [];
        this.queued         = data.queued          || [];
        this.inConsultation = data.in_consultation || [];
        this.forPayment     = data.for_payment     || [];
        this.completed      = data.completed       || [];
      } catch (e) {
        this.error = e.message || "Failed to load clinic queue.";
      } finally {
        this.loading = false;
      }
    },

    tabCount(key) {
      return {
        incoming:     this.incoming.length,
        queued:       this.queued.length,
        consultation: this.inConsultation.length,
        payment:      this.forPayment.length,
        completed:    this.completed.length,
      }[key] || 0;
    },

    cardHtml(appt) {
      return buildClinicCard(appt);
    },

    listFor(key) {
      return {
        incoming:     this.incoming,
        queued:       this.queued,
        consultation: this.inConsultation,
        payment:      this.forPayment,
        completed:    this.completed,
      }[key] || [];
    },

    emptyLabel(key) {
      return {
        incoming:     "No incoming appointments right now.",
        queued:       "Queue is empty.",
        consultation: "No active consultations.",
        payment:      "No appointments awaiting payment.",
        completed:    "No completed visits today.",
      }[key] || "No appointments.";
    },

    bindGlobalActions() {
      const self = this;
      window.__clinicAction = async (action, id) => {
        const appt = self.findAppt(id);
        if (action === "record") {
          window.dispatchEvent(new CustomEvent("clinic-open-modal", { detail: { appt } }));
          return;
        }
        if (action === "vaccinations") {
          window.dispatchEvent(new CustomEvent("clinic-open-modal", {
            detail: { appt, section: "vaccinations" },
          }));
          return;
        }
        if (action === "pay") {
          window.dispatchEvent(new CustomEvent("clinic-open-pay-modal", { detail: { appt } }));
          return;
        }
        if (action === "cancel" && !confirm("Cancel this appointment?")) return;
        try {
          if (action === "check-in")           await API.clinicCheckIn(id);
          if (action === "start-consultation")  await API.clinicStartConsultation(id);
          if (action === "finish-consultation") await API.clinicFinishConsultation(id);
          if (action === "cancel")              await API.clinicCancel(id);
          await self.loadQueue();
          if (window.lucide) window.lucide.createIcons();
        } catch (e) {
          alert(e.message || "Action failed. Please try again.");
        }
      };

      window.__clinicReload = async () => {
        await self.loadQueue();
        if (window.lucide) window.lucide.createIcons();
      };
    },

    findAppt(id) {
      return [
        ...this.incoming, ...this.queued,
        ...this.inConsultation, ...this.forPayment, ...this.completed,
      ].find((a) => a.id === id) || null;
    },
  };
}

// ── Customer Search ───────────────────────────────────────────────────────────

function adminClinicSearch() {
  return {
    searchQuery: "",
    searchResults: [],
    petSearchResults: [],
    noResults: false,
    searching: false,
    _timer: null,
    _searchRequestId: 0,

    panelCustomer: null,
    showPanel: false,

    queueModal: { open: false, busy: false, error: "" },
    queueForm: {},

    async init() {
      await this.initializeQueuePetFields();
    },

    onSearchInput() {
      const q = this.searchQuery.trim();
      if (q.length < 2) {
        clearTimeout(this._timer);
        this._searchRequestId += 1;
        this.searchResults = [];
        this.petSearchResults = [];
        this.noResults = false;
        return;
      }
      clearTimeout(this._timer);
      this._timer = setTimeout(async () => {
        const requestId = ++this._searchRequestId;
        this.searching = true;
        try {
          const res = await API.searchWalkInCustomers(q);
          if (requestId !== this._searchRequestId) return;
          this.searchResults = res.customers || [];
          this.petSearchResults = res.pets || [];
          this.noResults = this.searchResults.length === 0
            && this.petSearchResults.length === 0;
          this.$nextTick?.(() => this.refreshIcons());
        } catch {
          if (requestId !== this._searchRequestId) return;
          this.searchResults = [];
          this.petSearchResults = [];
          this.noResults     = false;
        } finally {
          if (requestId === this._searchRequestId) this.searching = false;
        }
      }, 350);
    },

    async pickCustomer(c) {
      try {
        const res = c.recordType === "unregistered"
          ? await API.getUnregisteredCustomerDetails(c.id)
          : await API.getCustomerDetails(c.id);
        this.panelCustomer = {
          ...(res.customer || c),
          recordType: c.recordType || res.customer?.recordType || "registered",
        };
        this.showPanel     = true;
        this.clearSearch();
        this.$nextTick(() => this.refreshIcons());
        return this.panelCustomer;
      } catch (err) {
        alert(err.message || "Failed to load customer details.");
        return null;
      }
    },

    async pickPet(result) {
      const customer = await this.pickCustomer({
        id: result.ownerId,
        recordType: result.ownerRecordType,
        fullName: result.ownerName,
      });
      if (!customer) return;

      const pet = (customer.pets || []).find(
        candidate => String(candidate.id) === String(result.id),
      );
      if (pet) await this.openQueueModal(pet);
    },

    clearSearch() {
      clearTimeout(this._timer);
      this._searchRequestId += 1;
      this.searchQuery   = "";
      this.searchResults = [];
      this.petSearchResults = [];
      this.noResults     = false;
      this.searching     = false;
    },

    normalizePetSpecies(value) {
      const species = String(value || "").trim().toLowerCase();
      if (species === "dog") return "Dog";
      if (species === "cat") return "Cat";
      return "";
    },

    capitalizeFirstLetter(value) {
      const text = String(value || "").trim();
      return text.replace(/\p{L}/u, letter => letter.toLocaleUpperCase());
    },

    capitalizeWords(value) {
      const text = String(value || "").trim();
      return text.replace(/(^|[\s/-])(\p{L})/gu, (_, separator, letter) => (
        `${separator}${letter.toLocaleUpperCase()}`
      ));
    },

    petResultSummary(pet) {
      return [
        this.capitalizeFirstLetter(pet?.petName),
        this.normalizePetSpecies(pet?.species) || this.capitalizeFirstLetter(pet?.species),
        this.capitalizeWords(pet?.breed),
      ].filter(Boolean).join(" · ");
    },

    refreshIcons() {
      if (window.lucide) window.lucide.createIcons();
    },

    async initializeQueuePetFields() {
      if (adminClinicPetFields) return true;

      try {
        const tools = await adminClinicPetFieldModulesReady;
        const elements = {
          species: document.getElementById("clinicQueuePetSpecies"),
          breed: document.getElementById("clinicQueuePetBreed"),
          gender: document.getElementById("clinicQueuePetGender"),
          size: document.getElementById("clinicQueuePetSize"),
          furType: document.getElementById("clinicQueuePetFurType"),
          weight: document.getElementById("clinicQueuePetWeight"),
        };
        const controls = {
          species: tools.createFixedOptionCombobox({
            root: document.getElementById("clinicQueuePetSpeciesCombobox"),
            input: elements.species,
            listbox: document.getElementById("clinicQueuePetSpeciesOptions"),
            toggleButton: document.getElementById("clinicQueuePetSpeciesDropdownButton"),
            placeholder: "Select species",
            options: [
              { value: "Dog", label: "Dog" },
              { value: "Cat", label: "Cat" },
            ],
          }),
          gender: tools.createFixedOptionCombobox({
            root: document.getElementById("clinicQueuePetGenderCombobox"),
            input: elements.gender,
            listbox: document.getElementById("clinicQueuePetGenderOptions"),
            toggleButton: document.getElementById("clinicQueuePetGenderDropdownButton"),
            placeholder: "Select gender",
            options: [
              { value: "male", label: "Male" },
              { value: "female", label: "Female" },
            ],
            displaySelectedLabel: true,
          }),
          breed: tools.createBreedCombobox({
            root: document.getElementById("clinicQueuePetBreedCombobox"),
            input: elements.breed,
            listbox: document.getElementById("clinicQueuePetBreedOptions"),
            toggleButton: document.getElementById("clinicQueuePetBreedDropdownButton"),
            errorElement: document.getElementById("clinicQueuePetBreedError"),
            getPetType: () => elements.species.value,
          }),
          furType: tools.createBreedCoatCombobox({
            root: document.getElementById("clinicQueuePetFurTypeCombobox"),
            breedInput: elements.breed,
            petTypeInput: elements.species,
            input: elements.furType,
            listbox: document.getElementById("clinicQueuePetFurTypeOptions"),
            toggleButton: document.getElementById("clinicQueuePetFurTypeDropdownButton"),
            errorElement: document.getElementById("clinicQueuePetFurTypeError"),
          }),
          size: tools.createFixedOptionCombobox({
            root: document.getElementById("clinicQueuePetSizeCombobox"),
            input: elements.size,
            listbox: document.getElementById("clinicQueuePetSizeOptions"),
            toggleButton: document.getElementById("clinicQueuePetSizeDropdownButton"),
            placeholder: "Select size",
            options: [],
            displaySelectedLabel: true,
          }),
        };

        adminClinicPetFields = { tools, elements, controls };
        tools.initializeWeightField(elements.weight);
        controls.size.setDisabled(true);

        elements.species.addEventListener("change", () => {
          this.queueForm.species = controls.species.getValue();
          if (elements.weight.dataset.weightFieldMode === "range") {
            tools.resetWeightFieldForEntry(elements.weight);
          }
          this.syncQueuePetWeightAndSize({ clearManualSize: true });
          this.validateQueuePetWeightOnCommit();
        });
        elements.gender.addEventListener("change", () => {
          this.queueForm.gender = controls.gender.getValue();
        });
        elements.breed.addEventListener("change", () => {
          this.queueForm.breed = elements.breed.value;
        });
        elements.furType.addEventListener("change", () => {
          this.queueForm.fur_type = elements.furType.value;
        });
        elements.weight.addEventListener("input", () => this.syncQueuePetWeightAndSize());
        elements.weight.addEventListener("change", () => this.validateQueuePetWeightOnCommit());
        elements.weight.addEventListener("focus", () => tools.resetWeightFieldForEntry(elements.weight));
        elements.size.addEventListener("change", () => this.handleManualQueuePetSizeChange());

        return true;
      } catch (error) {
        console.error("Clinic pet fields could not be initialized.", error);
        return false;
      }
    },

    async openQueueModal(pet = null) {
      this.queueForm = {
        pet_id:          pet?.id || null,
        pet_name:        pet?.petName || "",
        species:         pet?.species || "",
        breed:           pet?.breed   || "",
        gender:          pet?.gender || "",
        birthdate:       pet?.birthdate || "",
        is_neutered:     Boolean(pet?.isNeutered),
        neutered_date:   pet?.neuteredDate || "",
        size:            pet?.size || "",
        fur_type:        pet?.furType || "",
        weight:          pet?.weight ?? "",
        color:           pet?.color || "",
        medical_conditions: pet?.medicalConditions || "",
        chief_complaint: "",
      };
      this.queueModal = { open: true, busy: false, error: "" };

      if (!await this.initializeQueuePetFields()) {
        this.queueModal.error = "Pet controls could not be loaded. Please refresh and try again.";
        return;
      }

      const { tools, elements, controls } = adminClinicPetFields;
      const species = this.normalizePetSpecies(pet?.species);
      controls.species.setValue(species);
      controls.gender.setValue(String(pet?.gender || "").toLowerCase());
      controls.breed.reset();
      controls.furType.reset();
      controls.size.reset();
      elements.breed.value = pet?.breed || "";
      tools.initializeWeightField(elements.weight);
      elements.weight.value = pet?.weight ?? "";
      this.syncQueuePetWeightAndSize({ clearManualSize: true });
      if (!tools.getSizeForWeight(species, tools.getEnteredWeight(elements.weight))) {
        this.setStoredQueuePetSize(pet?.size);
      }
      await tools.breedCoatCatalogueReady;
      controls.furType.update();
      elements.furType.value = pet?.furType || "";
      controls.furType.update();
      this.$nextTick(() => this.refreshIcons());
    },

    setStoredQueuePetSize(storedSize) {
      if (!adminClinicPetFields) return;
      const { tools, elements, controls } = adminClinicPetFields;
      const normalizedStoredSize = tools.normalizePetSize(storedSize);
      const matchingOption = tools.getSizeOptions(elements.species.value).find(
        ({ value }) => tools.normalizePetSize(value) === normalizedStoredSize,
      );
      controls.size.setValue(matchingOption?.value || "");
    },

    syncQueuePetWeightAndSize({ clearManualSize = false } = {}) {
      if (!adminClinicPetFields) return;
      const { tools, elements, controls } = adminClinicPetFields;
      const weight = tools.getEnteredWeight(elements.weight);
      const options = tools.getSizeOptions(elements.species.value);
      controls.size.setOptions(options, { preserveValue: !clearManualSize });
      const computedSize = tools.getSizeForWeight(elements.species.value, weight);
      if (computedSize) controls.size.setValue(computedSize);
      controls.size.setDisabled(options.length === 0);
    },

    validateQueuePetWeightOnCommit() {
      if (!adminClinicPetFields) return false;
      const { tools, elements, controls } = adminClinicPetFields;
      const weight = tools.getEnteredWeight(elements.weight);
      if (!weight) {
        if (elements.weight.dataset.weightFieldMode !== "error") {
          tools.showWeightRangeInField(
            elements.weight,
            elements.species.value,
            controls.size.getValue(),
          );
        }
        return true;
      }
      const message = tools.getWeightValidationMessage(elements.species.value, weight);
      if (message) {
        tools.showWeightValidationInField(elements.weight, message);
        return false;
      }
      return true;
    },

    handleManualQueuePetSizeChange() {
      if (!adminClinicPetFields) return;
      const { tools, elements, controls } = adminClinicPetFields;
      if (!tools.getEnteredWeight(elements.weight)
        && elements.weight.dataset.weightFieldMode !== "error") {
        tools.showWeightRangeInField(
          elements.weight,
          elements.species.value,
          controls.size.getValue(),
        );
      }
    },

    buildQueuePetPayload() {
      if (!adminClinicPetFields) return null;
      const { tools, elements, controls } = adminClinicPetFields;
      const form = this.queueForm;
      const isNeutered = Boolean(form.is_neutered);
      return {
        pet_id: form.pet_id || null,
        pet_name: String(form.pet_name || "").trim(),
        species: controls.species.getValue(),
        breed: elements.breed.value.trim(),
        gender: controls.gender.getValue(),
        birthdate: form.birthdate || null,
        is_neutered: isNeutered,
        neutered_date: isNeutered ? (form.neutered_date || null) : null,
        size: tools.normalizePetSize(controls.size.getValue()) || null,
        fur_type: elements.furType.value || null,
        weight: tools.getEnteredWeight(elements.weight) || null,
        color: String(form.color || "").trim() || null,
        medical_conditions: String(form.medical_conditions || "").trim() || null,
        chief_complaint: String(form.chief_complaint || "").trim(),
      };
    },

    validateQueuePetPayload(payload) {
      if (!payload) return "Pet controls could not be loaded. Please refresh and try again.";
      if (!payload.pet_name) return "Pet name is required.";
      if (!payload.species) return "Species is required.";
      if (!payload.breed) return "Breed is required.";
      if (!payload.chief_complaint) return "Chief complaint is required.";

      if (!payload.pet_id) {
        const duplicatePet = (this.panelCustomer?.pets || []).some(
          pet => String(pet.petName || "").trim().toLowerCase() === payload.pet_name.toLowerCase(),
        );
        if (duplicatePet) return "This owner already has a pet with this name.";
      }

      const { tools, elements, controls } = adminClinicPetFields;
      const breedMessage = controls.breed.getValidationMessage();
      if (breedMessage) {
        controls.breed.showValidation();
        return breedMessage;
      }
      const furTypeMessage = controls.furType.getValidationMessage();
      if (furTypeMessage) {
        controls.furType.showValidation();
        return furTypeMessage;
      }
      const weightMessage = tools.getWeightFieldValidationMessage(elements.weight)
        || tools.getWeightValidationMessage(payload.species, payload.weight);
      if (weightMessage) {
        tools.showWeightValidationInField(elements.weight, weightMessage);
        return weightMessage;
      }
      const allowedSizes = tools.getSizeOptions(payload.species).map(
        ({ value }) => tools.normalizePetSize(value),
      );
      if (payload.size && !allowedSizes.includes(payload.size)) {
        return `${payload.species} size must be one of: ${allowedSizes.join(", ")}.`;
      }
      return "";
    },

    async submitQueue() {
      this.queueModal.error = "";
      const petPayload = this.buildQueuePetPayload();
      const validationMessage = this.validateQueuePetPayload(petPayload);
      if (validationMessage) {
        this.queueModal.error = validationMessage;
        return;
      }

      const c = this.panelCustomer;
      if (!c) return;

      this.queueModal.busy = true;
      try {
        await API.submitClinicWalkIn({
          fname:           c.firstName,
          lname:           c.lastName,
          mname:           c.middleName || undefined,
          email:           c.email  || undefined,
          phone:           c.phone,
          owner_record_type: c.recordType || "registered",
          customer_user_id: c.recordType === "registered" ? c.id : undefined,
          unregistered_customer_id: c.recordType === "unregistered" ? c.id : undefined,
          ...petPayload,
          clinic_quick_entry: true,
          terms_agreed:    true,
        });
        this.queueModal.open = false;
        this.showPanel       = false;
        window.__clinicReload?.();
      } catch (err) {
        this.queueModal.error = err.errors
          ? Object.values(err.errors).flat().join(" ")
          : (err.message || "Failed to add to queue.");
      } finally {
        this.queueModal.busy = false;
      }
    },
  };
}

// ── Medical Record Modal ──────────────────────────────────────────────────────

function adminClinicModal() {
  return {
    open:       false,
    saving:     false,
    modalError: "",
    title:      "Medical Record",
    apptId:     null,
    currentAppt: null,
    activeSection: "medical",
    attachments: [],
    attachmentFile: null,
    attachmentLabel: "",
    attachmentBusy: false,
    attachmentBusyId: null,
    attachmentError: "",
    form: {
      chief_complaint: "", diagnosis: "", findings: "", treatment_given: "",
      vet_notes: "", follow_up_date: "", follow_up_notes: "",
      weight_kg: "", temperature_c: "", heart_rate_bpm: "",
      respiratory_rate_bpm: "", body_condition_score: "",
      medications: [],
    },

    init() {
      window.addEventListener("clinic-open-modal", (e) => this.openModal(e.detail));
    },

    openModal({ appt, section = "medical" } = {}) {
      if (!appt) return;
      this.apptId     = appt.id;
      this.currentAppt = appt;
      this.activeSection = "medical";
      this.modalError = "";
      this.title      = `Patient Record — ${appt.ownerName}${appt.pet?.name ? " / " + appt.pet.name : ""}`;
      const r = appt.record  || {};
      const v = appt.vitals  || {};
      this.attachments = (r.attachments || []).map((attachment) => ({ ...attachment }));
      this.attachmentFile = null;
      this.attachmentLabel = "";
      this.attachmentBusy = false;
      this.attachmentBusyId = null;
      this.attachmentError = "";
      this.form = {
        chief_complaint:      r.chief_complaint      || appt.chief_complaint || "",
        diagnosis:            r.diagnosis            || "",
        findings:             r.findings             || "",
        treatment_given:      r.treatment_given      || "",
        vet_notes:            r.vet_notes            || "",
        follow_up_date:       r.follow_up_date       || "",
        follow_up_notes:      r.follow_up_notes      || "",
        weight_kg:            v.weight_kg            ?? "",
        temperature_c:        v.temperature_c        ?? "",
        heart_rate_bpm:       v.heart_rate_bpm       ?? "",
        respiratory_rate_bpm: v.respiratory_rate_bpm ?? "",
        body_condition_score: v.body_condition_score ?? "",
        medications:          (r.medications || []).map((m) => ({ ...m })),
      };
      this.open = true;
      this.selectSection(section);
    },

    selectSection(section) {
      this.activeSection = section === "vaccinations" ? "vaccinations" : "medical";

      if (this.activeSection === "vaccinations" && this.currentAppt) {
        window.dispatchEvent(new CustomEvent("clinic-vaccinations-open", {
          detail: { appt: this.currentAppt },
        }));
      }
    },

    closeModal() {
      if (this.saving) return;
      this.open = false;
      this.activeSection = "medical";
    },

    selectAttachment(event) {
      this.attachmentFile = event.target.files?.[0] || null;
      this.attachmentError = "";
    },

    formatFileSize(bytes) {
      const size = Number(bytes);
      if (!Number.isFinite(size) || size < 0) return "Size unavailable";
      if (size < 1024) return `${size} B`;
      if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`;
      return `${(size / (1024 * 1024)).toFixed(1)} MB`;
    },

    async uploadAttachment() {
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
        this.attachmentFile = null;
        this.attachmentLabel = "";
        if (this.$refs.attachmentFile) this.$refs.attachmentFile.value = "";
      } catch (error) {
        this.attachmentError = error.errors
          ? Object.values(error.errors).flat().join(" ")
          : (error.message || "Failed to upload attachment.");
      } finally {
        this.attachmentBusy = false;
      }
    },

    async downloadAttachment(attachment) {
      this.attachmentBusyId = attachment.id;
      this.attachmentError = "";
      try {
        const result = await API.clinicDownloadAttachment(this.apptId, attachment.id);
        const objectUrl = URL.createObjectURL(result.blob);
        const link = document.createElement("a");
        link.href = objectUrl;
        link.download = result.fileName || attachment.file_name || "clinic-attachment";
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
      } catch (error) {
        this.attachmentError = error.message || "Failed to download attachment.";
      } finally {
        this.attachmentBusyId = null;
      }
    },

    async deleteAttachment(attachment) {
      if (!window.confirm(`Delete ${attachment.file_name}? This cannot be undone.`)) return;

      this.attachmentBusyId = attachment.id;
      this.attachmentError = "";
      try {
        await API.clinicDeleteAttachment(this.apptId, attachment.id);
        this.attachments = this.attachments.filter((item) => item.id !== attachment.id);
      } catch (error) {
        this.attachmentError = error.message || "Failed to delete attachment.";
      } finally {
        this.attachmentBusyId = null;
      }
    },

    addMedication() {
      this.form.medications.push({ drug_name: "", dosage: "", frequency: "", duration: "", instructions: "" });
    },

    removeMedication(idx) {
      this.form.medications.splice(idx, 1);
    },

    async saveRecord(finishCase = false) {
      this.saving     = true;
      this.modalError = "";
      try {
        const payload = {
          chief_complaint:      this.form.chief_complaint      || null,
          diagnosis:            this.form.diagnosis             || null,
          findings:             this.form.findings              || null,
          treatment_given:      this.form.treatment_given       || null,
          vet_notes:            this.form.vet_notes             || null,
          follow_up_date:       this.form.follow_up_date        || null,
          follow_up_notes:      this.form.follow_up_notes       || null,
          weight_kg:            this.form.weight_kg             || null,
          temperature_c:        this.form.temperature_c         || null,
          heart_rate_bpm:       this.form.heart_rate_bpm        || null,
          respiratory_rate_bpm: this.form.respiratory_rate_bpm  || null,
          body_condition_score: this.form.body_condition_score   || null,
          medications:          this.form.medications.filter((m) => m.drug_name?.trim()),
          finish_case:          finishCase,
        };
        await API.clinicSaveRecord(this.apptId, payload);
        this.open = false;
        window.__clinicReload?.();
        window.dispatchEvent(new CustomEvent("clinic-case-updated"));
      } catch (e) {
        this.modalError = e.message || "Failed to save record.";
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

    // Read-only consultation detail
    detail: { open: false, record: null },

    init() {
      const token = API.getAdminToken?.();
      const role  = API.getUserRole?.();
      if (!token || (role !== "admin" && role !== "staff")) {
        API.redirectToSignIn?.();
        return;
      }
      this.loadAllRecords();
      this.loadActiveCases();
      window.addEventListener("clinic-case-updated", () => this.loadActiveCases());
    },

    async loadActiveCases() {
      this.activeCasesLoading = true;
      try { this.activeCases = (await API.getActiveClinicCases()).cases || []; }
      catch { this.activeCases = []; }
      finally { this.activeCasesLoading = false; }
    },

    caseLabel(item) { return ({ online_request: "Online request", consultation: "Consultation", vaccination: "Vaccination" })[item.case_type] || "Clinic case"; },
    caseStatus(item) { return item.status === "waiting_to_arrive" ? "Waiting for Arrival" : "In Progress"; },
    caseOpened(item) { return item.created_at ? new Date(item.created_at).toLocaleString("en-PH", { dateStyle: "medium", timeStyle: "short" }) : "—"; },
    async cancelCase(item) {
      if (!window.confirm(`Cancel the case for ${item.pet?.name || "this patient"}?`)) return;
      try { await API.clinicCancel(item.id); await this.loadActiveCases(); }
      catch (error) { alert(error.message || "Could not cancel this case."); }
    },
    openCase(item) { window.dispatchEvent(new CustomEvent("clinic-open-modal", { detail: { appt: item, section: item.case_type === "vaccination" ? "vaccinations" : "medical" } })); },
    async startCase(item) {
      try { const response = await API.startClinicCase(item.id); await this.loadActiveCases(); this.openCase(response.case); }
      catch (error) { alert(error.message || "Could not start this case."); }
    },
    openAddCustomer() {
      this.addCustomer = { open: true, saving: false, error: "", form: { first_name: "", last_name: "", middle_name: "", phone: "", email: "", pet_name: "", species: "", breed: "" } };
    },
    closeAddCustomer() { if (!this.addCustomer.saving) this.addCustomer.open = false; },
    async saveCustomerRecord(confirmSimilarName = false) {
      this.addCustomer.saving = true; this.addCustomer.error = "";
      try {
        const form = this.addCustomer.form;
        const owner = await API.createUnregisteredCustomer({ first_name: form.first_name, last_name: form.last_name, middle_name: form.middle_name || null, phone: form.phone, email: form.email || null, confirm_similar_name: confirmSimilarName });
        const petResponse = await API.adminAddCustomerPet("unregistered", owner.customer.id, { pet_name: form.pet_name, species: form.species, breed: form.breed || null });
        const pet = petResponse.pet;
        this.searchRows.unshift({ owner: owner.customer, pet: { id: pet.pet_id, petName: pet.pet_name, species: pet.species, breed: pet.breed } });
        this.noResults = false;
        this.addCustomer.open = false;
        this.$nextTick?.(() => { if (window.lucide) window.lucide.createIcons(); });
      } catch (error) {
        if (error.code === "similar_customer_name" && !confirmSimilarName && window.confirm(error.message)) {
          this.addCustomer.saving = false;
          return this.saveCustomerRecord(true);
        }
        this.addCustomer.error = error.errors ? Object.values(error.errors).flat().join(" ") : (error.message || "Customer record could not be saved.");
      } finally { this.addCustomer.saving = false; }
    },

    async loadAllRecords(append = false) {
      this.searching = true;
      this.noResults = false;
      const page = append ? this.recordsPage + 1 : 1;
      try {
        const res = await API.getClinicRecords(page);
        this.recordsPage = page;
        this.hasMoreRecords = !!res.has_more;
        this.searchRows = append ? this.searchRows.concat(res.rows || []) : (res.rows || []);
        this.noResults = this.searchRows.length === 0;
      } catch {
        if (!append) this.searchRows = [];
        this.noResults = false;
      } finally {
        this.searching = false;
        this.$nextTick?.(() => { if (window.lucide) window.lucide.createIcons(); });
      }
    },

    _buildRows(res) {
      const rows = [];
      const seenPetIds = new Set();

      for (const c of (res.customers || [])) {
        const pets = (c.pets || []).filter(p => !p.isArchived);
        if (pets.length) {
          for (const p of pets) {
            seenPetIds.add(String(p.id));
            rows.push({ owner: c, pet: p });
          }
        } else {
          rows.push({ owner: c, pet: null });
        }
      }
      for (const p of (res.pets || [])) {
        if (!seenPetIds.has(String(p.id))) {
          rows.push({
            owner: {
              id: p.ownerId,
              recordType: p.ownerRecordType,
              fullName: p.ownerName,
              phone: null,
              email: null,
            },
            pet: p,
          });
        }
      }

      this.searchRows = rows;
      this.hasMoreRecords = false;
      this.noResults  = rows.length === 0;
    },

    async onSearchInput() {
      const q = this.searchQuery.trim();
      if (q.length === 0) {
        clearTimeout(this._timer);
        this._reqId++;
        await this.loadAllRecords();
        return;
      }
      if (q.length < 2) {
        clearTimeout(this._timer);
        this._reqId++;
        this.searchRows = [];
        this.noResults  = false;
        return;
      }
      clearTimeout(this._timer);
      this._timer = setTimeout(async () => {
        const rid = ++this._reqId;
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

    async openProfile(row) {
      if (!row.pet?.id) return;
      this.profile = {
        open: true,
        loading: true,
        error: null,
        pet: row.pet,
        owner: row.owner,
        records: [],
      };
      this.$nextTick?.(() => { if (window.lucide) window.lucide.createIcons(); });
      try {
        const data = await API.adminGetPetProfile(row.pet.id);
        this.profile.records = data.medical_records || [];
      } catch (err) {
        this.profile.error = err.message || "Failed to load pet profile.";
      } finally {
        this.profile.loading = false;
        this.$nextTick?.(() => { if (window.lucide) window.lucide.createIcons(); });
      }
    },

    closeProfile() {
      this.profile.open = false;
    },

    async openVaccinations() {
      const { pet, owner } = this.profile;
      try {
        const response = await API.createClinicCase({ pet_id: pet.id, case_type: "vaccination" });
        await this.loadActiveCases(); this.closeProfile(); this.openCase(response.case);
      } catch (error) { alert(error.message || "Could not open a vaccination case."); }
    },

    async newConsultation() {
      const { pet, owner } = this.profile;
      if (!pet?.id || !owner) return;

      try {
        const response = await API.createClinicCase({ pet_id: pet.id, case_type: "consultation" });
        await this.loadActiveCases();
        this.closeProfile();
        this.openCase(response.case);
        return;
      } catch (error) {
        alert(error.message || "Could not open a consultation case.");
        return;
      }

      // Build the owner draft in exactly the same format that walk-in-owner-step.js
      // saveOwnerDraft() produces, so the pet step can read it without modification.
      const middleInitial = owner.middleName
        ? String(owner.middleName).trim().replace(/\.+$/, "").toUpperCase().charAt(0)
        : "";
      const ownerDraft = {
        firstName:              owner.firstName  || "",
        lastName:               owner.lastName   || "",
        middleInitial,
        phone:                  owner.phone      || "",
        email:                  owner.email      || "",
        fullName:               owner.fullName   || "",
        bookingType:            "walk_in",
        appointmentType:        "clinic",
        ownerRecordType:        owner.recordType || "registered",
        customerUserId:         owner.recordType === "registered"   ? owner.id : null,
        unregisteredCustomerId: owner.recordType === "unregistered" ? owner.id : null,
        existingPets:           Array.isArray(owner.pets) ? owner.pets : [],
        // Flag so the pet step Back button returns to clinic, not Step 1
        directEntry:            "clinic",
      };

      // Clear any stale walk-in continuation data before setting the new draft
      ["walkInPetStep", "walkInConsentStep", "walkInReviewStep", "walkInBookingConfirmation"]
        .forEach(k => sessionStorage.removeItem(k));

      sessionStorage.setItem("walkInOwnerStep", JSON.stringify(ownerDraft));
      // Tell the pet step which pet to pre-select
      sessionStorage.setItem("clinicPreselectedPetId", String(pet.id));

      // Skip Step 1 entirely — go straight to pet details
      window.location.href = "./walk-in-pet-details.html";
    },

    viewDetail(record) {
      this.detail = { open: true, record };
      this.$nextTick?.(() => { if (window.lucide) window.lucide.createIcons(); });
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

// ── Payment Modal ─────────────────────────────────────────────────────────────

function adminClinicPayModal() {
  return {
    open:        false,
    paying:      false,
    payError:    "",
    apptId:      null,
    patientName: "",
    amount:      "",
    method:      "cash",

    init() {
      window.addEventListener("clinic-open-pay-modal", (e) => this.openModal(e.detail));
    },

    openModal({ appt } = {}) {
      if (!appt) return;
      this.apptId      = appt.id;
      this.patientName = `${appt.ownerName}${appt.pet?.name ? " / " + appt.pet.name : ""}`;
      this.amount      = appt.total_amount || "";
      this.method      = "cash";
      this.payError    = "";
      this.open        = true;
    },

    async submitPayment() {
      if (!this.amount && this.amount !== 0) {
        this.payError = "Please enter the total amount.";
        return;
      }
      this.paying   = true;
      this.payError = "";
      try {
        await API.clinicMarkPaid(this.apptId, {
          total_amount:   parseFloat(this.amount) || 0,
          payment_method: this.method,
        });
        this.open = false;
        window.__clinicReload?.();
      } catch (e) {
        this.payError = e.message || "Payment failed.";
      } finally {
        this.paying = false;
      }
    },
  };
}
