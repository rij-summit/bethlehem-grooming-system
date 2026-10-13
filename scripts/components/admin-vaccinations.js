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
    inventory_quantity: 1,
    request_key: null,
    vaccine_name: "",
    product_name: "",
    manufacturer: "",
    batch_number: "",
    product_expiry_date: "",
    administered_date: vaccinationToday(),
    next_due_date: "",
    dose_amount: "",
    dose_unit: "",
    route: "",
    administration_site: "",
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
    treatmentType: "vaccine",
    medicineQuery: "", medicineResults: [], medicineLoading: false, medicineError: "",
    selectedMedicine: null,
    _medicineTimer: null, _medicineRequest: 0,
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
      window.addEventListener('clinic-cases-loaded', (event) => {
        const current = event.detail?.find(item => item.id === this.appointment?.id);
        if (current) this.appointment = {...this.appointment, status: current.status, weight_kg: current.vitals?.weight_kg,
          confinement: current.confinement, paid: current.paid, archived_at: current.archived_at};
      });
      window.addEventListener('clinic-case-patch', (event) => {
        if (event.detail.id === this.appointment?.id) this.appointment = {...this.appointment, ...event.detail};
      });
    },

    openForAppointment(appt) {
      if (!appt?.pet?.id) {
        this.pet = null;
        this.appointment = appt || null;
        this.records = [];
        this.loading = false;
        this.petNotFound = true;
        this.error = "This appointment does not have a patient record that can receive treatment history.";
        return;
      }

      const changedPet = Number(this.pet?.id) !== Number(appt.pet.id);
      this.pet = { ...appt.pet };
      this.appointment = {
        id: appt.id,
        appointment_reference: appt.appointment_reference,
        status: appt.status,
        weight_kg: appt.vitals?.weight_kg,
        confinement: appt.confinement,
        paid: appt.paid,
        archived_at: appt.archived_at,
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
      this.clearMedicine();
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
          : (error.message || "Treatment history could not be loaded.");
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
        if (this.formModal.open && this.recordKind === "inventory" && this.form.inventory_item_id) {
          this.restoreVaccineSelection();
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
      if (this.appointment?.paid || this.appointment?.archived_at) return false;
      return !this.appointment?.id
        || ["checked_in", "in_consultation"].includes(this.appointment.status)
        || (API.getUserRole?.() === "admin" && this.appointment.status === "for_payment");
    },

    openCreateForm() {
      if (!this.canEditClinical) return;
      if (!this.pet?.id || !this.caseOngoing()) return;

      this.form = emptyVaccinationDraftForm();
      this.treatmentType = "vaccine";
      this.clearMedicine();
      this.recordKind = "inventory";
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
        inventory_quantity: record.inventory_quantity == null ? 1 : Number.isInteger(Number(record.inventory_quantity)) ? Number(record.inventory_quantity) : record.inventory_quantity,
        request_key: record.request_key ?? null,
        inventory_item_id: record.inventory_item_id ? String(record.inventory_item_id) : "",
        vaccine_name: record.vaccine_name || "",
        product_name: record.product_name || "",
        manufacturer: record.manufacturer || "",
        batch_number: record.batch_number || "",
        product_expiry_date: record.product_expiry_date || "",
        administered_date: record.administered_date || "",
        next_due_date: record.next_due_date || "",
        dose_amount: record.dose_amount ?? "",
        dose_unit: record.dose_unit || "",
        route: record.route || "",
        administration_site: record.administration_site || "",
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
      this.treatmentType = record.treatment_type || "vaccine";
      this.clearMedicine();
      this.recordKind = record.inventory_item_id && record.administered_date === vaccinationToday() ? "inventory" : "historical";
      this.selectedVaccine = null;
      this.vaccineQuery = "";
      if (!this.optionsLoading && !this.optionsError && this.recordKind === "inventory") this.restoreVaccineSelection();

      this.formErrors = {};
      this.formErrorSummary = "";
      this.refreshIcons();
    },

    restoreVaccineSelection() {
      if (this.treatmentType === "medicine") {
        this.restoreMedicineSelection();
        return;
      }
      const vaccine = this.vaccineOptions.find(
        (item) => Number(item.item_id) === Number(this.form.inventory_item_id),
      );
      if (vaccine && this.vaccineSelectable(vaccine)) {
        this.selectVaccine(vaccine, true);
      } else {
        this.formModal.unavailableVaccineName = this.form.vaccine_name;
      }
    },

    closeForm() {
      if (this.formModal.saving) return;
      this.formModal.open = false;
      this.clearMedicine();
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

    get selectedProduct() { return this.treatmentType === "medicine" ? this.selectedMedicine : this.selectedVaccine; },
    get productQuery() { return this.treatmentType === "medicine" ? this.medicineQuery : this.vaccineQuery; },
    set productQuery(value) { if (this.treatmentType === "medicine") this.medicineQuery = value; else this.vaccineQuery = value; },
    get productResults() { return (this.treatmentType === "medicine" ? this.medicineResults : this.filteredVaccines()).slice().sort((a, b) => Number(this.productSelectable(b)) - Number(this.productSelectable(a))); },
    get productLoading() { return this.treatmentType === "medicine" ? this.medicineLoading : this.optionsLoading; },
    get productSearchError() { return this.treatmentType === "medicine" ? this.medicineError : this.optionsError; },
    searchTreatmentProducts() { if (this.treatmentType === "medicine") this.searchMedicines(); },
    selectProduct(item) { if (this.treatmentType === "medicine") this.selectMedicine(item); else this.selectVaccine(item); },
    clearProduct() { this.clearVaccine(); this.clearMedicine(); this.form.inventory_quantity = 1; },

    productSelectable(item) {
      return item?.is_active !== false && Number(item?.available_quantity) >= 1;
    },
    vaccineSelectable(item) { return this.productSelectable(item); },

    inventoryUnitLabel(item, quantity = 1) {
      const unit = String(item?.unit || "unit").toLowerCase();
      if (unit === "pc") return Number(quantity) === 1 ? "piece" : "pieces";
      if (Number(quantity) === 1 || /s$/.test(unit)) return unit;
      return unit === "box" ? "boxes" : `${unit}s`;
    },

    productAvailabilityLabel(item) {
      if (Number(item?.quantity_on_hand) <= 0) return "Out of stock";
      if (item?.stock_status === "expired") return "Expired stock";
      if (item?.stock_status === "legacy_unit") return "Legacy unit · review Inventory";
      if (Number(item?.available_quantity) < 1) return "Unavailable stock";
      const quantity = Number(item.available_quantity);
      const label = `${quantity} ${this.inventoryUnitLabel(item, quantity)} available`;
      return item.stock_status === "low_stock" ? `Low stock · ${label}` : label;
    },
    vaccineAvailabilityLabel(item) { return this.productAvailabilityLabel(item); },

    changeInventoryQuantity(delta) {
      const quantity = Number(this.form.inventory_quantity);
      const next = (Number.isInteger(quantity) ? quantity : 1) + delta;
      if (next >= 1 && next <= Number(this.selectedProduct?.available_quantity)) this.form.inventory_quantity = next;
    },

    selectVaccine(item, restoring = false) {
      if (!this.vaccineSelectable(item)) return;
      this.selectedVaccine = item;
      this.form.inventory_item_id = String(item.item_id);
      if (!restoring) this.form.inventory_quantity = 1;
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

    changeTreatmentType(type) {
      if (this.formModal.saving || type === this.treatmentType) return;
      this.treatmentType = type;
      this.clearVaccine();
      this.clearMedicine();
      for (const field of ["vaccine_name", "product_name", "manufacturer", "batch_number", "product_expiry_date", "next_due_date", "dose_amount", "dose_unit", "inventory_quantity"]) this.form[field] = "";
      this.formErrors = {};
      this.formErrorSummary = "";
    },

    changeSource() {
      this.clearVaccine();
      this.clearMedicine();
      this.form.inventory_quantity = 1;
      if (this.recordKind === "inventory") this.form.administered_date = vaccinationToday();
      this.formErrors = {};
      this.formErrorSummary = "";
    },

    clearMedicine() {
      clearTimeout(this._medicineTimer);
      ++this._medicineRequest;
      this.selectedMedicine = null;
      this.medicineQuery = "";
      this.medicineResults = [];
      this.medicineError = "";
      this.medicineLoading = false;
    },

    get medicineQuote() {
      return this.selectedMedicine ? clinicMedicinePrice(this.selectedMedicine, this.appointment?.id ? this.appointment.weight_kg : this.pet?.weight) : null;
    },
    medicineMoney(value) { return "₱" + Number(value).toFixed(2); },
    medicineRange: clinicPriceRange,

    searchMedicines() {
      const query = this.medicineQuery;
      this.clearMedicine();
      this.medicineQuery = query;
      this.form.inventory_item_id = "";
      if (query.trim().length < 2) return;
      const request = this._medicineRequest;
      this._medicineTimer = setTimeout(() => this.loadMedicineResults(query.trim(), request), 300);
    },

    async loadMedicineResults(query, request, restoreId = null) {
      this.medicineLoading = true;
      try {
        const response = await API.clinicMedicineOptions(query);
        if (request !== this._medicineRequest || !this.formModal.open) return;
        this.medicineResults = response.data || [];
        if (restoreId) {
          const item = this.medicineResults.find(item => Number(item.item_id) === Number(restoreId));
          if (item) this.selectMedicine(item, true);
          else this.medicineError = "This medicine is no longer available. Select another medicine.";
        } else if (!this.medicineResults.length) this.medicineError = "No matching Medicine products.";
      } catch (error) {
        if (request === this._medicineRequest) this.medicineError = error.message || "Unable to load medicines.";
      } finally {
        if (request === this._medicineRequest) this.medicineLoading = false;
      }
    },

    restoreMedicineSelection() {
      this.clearMedicine();
      return this.loadMedicineResults(this.form.vaccine_name, this._medicineRequest, this.form.inventory_item_id);
    },

    selectMedicine(item, restoring = false) {
      if (!this.productSelectable(item)) return;
      this.selectedMedicine = item;
      this.medicineQuery = item.item_name;
      this.medicineResults = [];
      this.medicineError = "";
      this.form.inventory_item_id = String(item.item_id);
      if (!restoring) {
        this.form.inventory_quantity = 1;
      }
      delete this.formErrors.inventory_item_id;
    },

    validateForm(finalizing = false) {
      const errors = {};
      const value = (field) => String(this.form[field] ?? "").trim();
      const maxLength = (field, max, label) => {
        if (value(field).length > max) {
          errors[field] = `${label} must not exceed ${max} characters.`;
        }
      };

      const usesInventory = this.recordKind === "inventory";
      const medicine = this.treatmentType === "medicine";
      if (usesInventory) {
        const quantity = value("inventory_quantity");
        if (quantity && (!/^\d+$/.test(quantity) || Number(quantity) < 1 || Number(quantity) > 99999999)) {
          errors.inventory_quantity = "Use a whole number of physical inventory units, at least 1.";
        } else if (this.selectedProduct && Number(quantity) > Number(this.selectedProduct.available_quantity)) {
          errors.inventory_quantity = "Quantity used exceeds available unexpired stock.";
        } else if (finalizing && !quantity) errors.inventory_quantity = "required";
        if (finalizing && (!this.selectedProduct || !this.productSelectable(this.selectedProduct))) errors.inventory_item_id = "required";
        if (medicine && this.selectedMedicine && this.medicineQuote?.error) errors.inventory_item_id = this.medicineQuote.error;
        if (finalizing && medicine && !this.formModal.clinicAppointmentId) errors.clinic_appointment_id = "An ongoing Clinic case is required for a medicine treatment.";
      }

      const dose = Number(value("dose_amount"));
      if (value("dose_amount") && (!Number.isFinite(dose) || dose <= 0)) {
        errors.dose_amount = "Dose amount must be greater than zero.";
      } else if (dose > 99999.999) {
        errors.dose_amount = "Dose amount must not exceed 99,999.999.";
      }

      if (Boolean(value("dose_amount")) !== Boolean(value("dose_unit"))) {
        errors[value("dose_amount") ? "dose_unit" : "dose_amount"] = "Enter both Dose Amount and Dose Unit, or leave both blank.";
      }
      maxLength("dose_unit", 30, "Dose unit");
      maxLength("administration_site", 100, "Administration site");

      if (
        value("route")
        && !this.administrationRoutes.some((route) => route.value === value("route"))
      ) {
        errors.route = "Choose a supported administration route.";
      }

      if (!medicine && usesInventory && value("next_due_date") && value("next_due_date") < vaccinationToday()) {
        errors.next_due_date = "Next vaccination date cannot be before today.";
      }
      if (!usesInventory) {
        if (finalizing && !value("vaccine_name")) errors.vaccine_name = "required";
        if ((finalizing && !value("administered_date")) || (value("administered_date") && (!/^\d{4}-\d{2}-\d{2}$/.test(value("administered_date")) || Number.isNaN(new Date(value("administered_date")).getTime()) || value("administered_date") > vaccinationToday()))) {
          errors.administered_date = "Enter the actual administration date, on or before today.";
        }
        for (const field of medicine ? [] : ["next_due_date", "product_expiry_date"]) {
          if (value(field) && value(field) < value("administered_date")) errors[field] = "This date must be on or after the administration date.";
        }
      }

      this.formErrors = errors;
      this.formErrorSummary = Object.keys(errors).length ? "Complete the required fields marked *." : "";

      if (this.formErrorSummary) {
        this.focusValidationSummary();
        return false;
      }

      return true;
    },

    buildPayload() {
      if (this.treatmentType === "medicine" && !this.form.request_key) this.form.request_key = crypto.randomUUID();
      const nullableText = (field) => {
        const text = String(this.form[field] ?? "").trim();
        return text || null;
      };
      const nullableId = (field) => {
        const raw = String(this.form[field] ?? "").trim();
        return raw ? Number(raw) : null;
      };

      return {
        treatment_type: this.treatmentType,
        ...(this.form.request_key ? { request_key: this.form.request_key } : {}),
        ...(this.treatmentType === "medicine" && this.recordKind === "inventory" ? {
          expected_price: this.medicineQuote?.price ?? null,
          expected_weight_kg: this.medicineQuote?.pet_weight_kg ?? null,
        } : {}),
        inventory_quantity: this.recordKind === "inventory" ? nullableText("inventory_quantity") : null,
        inventory_item_id: this.recordKind === "inventory" ? nullableId("inventory_item_id") : null,
        ...(this.recordKind === "historical" ? {
          vaccine_name: nullableText("vaccine_name"),
          administered_date: nullableText("administered_date"),
          product_name: nullableText("product_name"),
          manufacturer: nullableText("manufacturer"),
          batch_number: nullableText("batch_number"),
          product_expiry_date: nullableText("product_expiry_date"),
        } : {}),
        next_due_date: this.treatmentType === "vaccine" ? nullableText("next_due_date") : null,
        dose_amount: nullableText("dose_amount"),
        dose_unit: nullableText("dose_unit"),
        route: nullableText("route"),
        administration_site: nullableText("administration_site"),
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
            ? "Treatment draft updated."
            : "Treatment draft created."),
        );
      } catch (error) {
        this.applyBackendValidation(error, "Treatment draft could not be saved.");
      } finally {
        this.formModal.saving = false;
      }
    },

    async saveAndFinishCase() {
      if (this.appointment?.confinement && !this.appointment.confinement.discharged_at) {
        this.formErrorSummary = 'Discharge the confined patient before sending this case to Billing.';
        return;
      }
      if (!this.canEditClinical) return;
      if (this.recordKind !== "inventory") return;
      if (this.appointment?.status !== "in_consultation" || this.formModal.saving || !this.validateForm(true)) return;
      if (!(await this.confirmDeduction(this.treatmentType === "medicine" ? this.selectedMedicine : this.selectedVaccine, "Deduct and Finish Case", this.treatmentType === "medicine"))) return;

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
        this.showToast("Treatment saved and stock deducted. Case is For Payment.");
      } catch (error) {
        this.loadVaccinations();
        this.applyBackendValidation(error, "The treatment could not be finalized. The case remains active.");
      } finally {
        this.formModal.saving = false;
      }
    },

    confirmDeduction(vaccine, confirmLabel, medicine = false, quantity = this.form.inventory_quantity) {
      const name = vaccine?.item_name || "the selected vaccine";
      const unit = vaccine?.unit || "unit";
      const batch = vaccine?.next_batch?.batch_number;
      return this.$store.clinicFeedback.confirm({
        title: "Deduct from clinic inventory?",
        message: `${quantity} ${unit} of ${name}${batch ? ` (batch ${batch})` : ""} will be deducted from clinic inventory.\n\n`
          + (medicine ? `A medicine charge of ${this.medicineMoney(this.medicineQuote?.price)} will be added to this case.\n\n` : "")
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
      this.formErrorSummary = Object.keys(this.formErrors).length ? "Complete the required fields marked *." : (error.message || fallback);
      this.focusValidationSummary();
    },

    fieldError(field) {
      const message = this.formErrors[field] || "";
      return message === "required" || /(?:field is required|is required\.)/i.test(message) ? "" : message;
    },

    focusValidationSummary() {
      this.$nextTick(() => {
        const firstInvalid = Array.from(this.$refs.treatmentForm?.querySelectorAll('[aria-invalid="true"]') || []).find(field => field.getClientRects().length);
        firstInvalid?.scrollIntoView({ block: "center", behavior: "smooth" });
        (firstInvalid || this.$refs.vaccinationValidationSummary)?.focus();
      });
    },

    async publishRecord(record) {
      if (!this.canEditClinical) return;
      if (record?.state !== "draft" || this.actionBusyId) return;

      const usesInventory = Boolean(record.inventory_item_id);
      if (!record.vaccine_name || !record.administered_date
        || (usesInventory && (record.administered_date !== vaccinationToday() || !Number.isInteger(Number(record.inventory_quantity)) || Number(record.inventory_quantity) < 1))) {
        this.openEditForm(record);
        this.formErrors = !record.vaccine_name ? { vaccine_name: "required" }
          : !record.administered_date ? { administered_date: "required" }
          : record.administered_date !== vaccinationToday() ? { administered_date: "Save this as a historical or external record to preserve its date without deducting current stock." }
          : { inventory_quantity: "Enter a whole number of physical inventory units, at least 1." };
        this.formErrorSummary = "Complete the required fields marked *.";
        this.focusValidationSummary();
        return;
      }

      if (record.treatment_type === "medicine" && usesInventory) {
        const confirmed = await this.$store.clinicFeedback.confirm({
          title: "Publish medicine treatment?",
          message: `${record.inventory_quantity} ${this.inventoryUnitLabel({unit: record.inventory_unit}, record.inventory_quantity)} of ${record.treatment_name || record.vaccine_name} will be deducted and a medicine charge of ${this.medicineMoney(record.expected_price)} added to this case. The record can no longer be edited.`,
          confirmLabel: "Charge, Deduct and Publish",
        });
        if (!confirmed) return;
        await this.publishDraft(record, true);
        return;
      }
      const vaccine = this.vaccineOptions.find(
        (item) => Number(item.item_id) === Number(record.inventory_item_id),
      ) || { item_name: record.vaccine_name };
      const confirmed = usesInventory
        ? await this.confirmDeduction(vaccine, "Deduct and Publish", false, record.inventory_quantity)
        : await this.$store.clinicFeedback.confirm({ title: "Publish historical or external record?", message: "No current Inventory stock will be deducted. The record will be published and can no longer be edited.", confirmLabel: "Publish Record" });
      if (!confirmed) return;

      await this.publishDraft(record, usesInventory);
    },

    async publishDraft(record, usesInventory) {
      this.actionBusyId = record.id;
      try {
        const response = await API.publishAdminPetVaccination(this.pet.id, record.id, { consumeInventory: usesInventory });
        await this.loadVaccinations();
        this.loadOptions();
        window.dispatchEvent(new CustomEvent("clinic-case-updated"));
        this.showToast(response.message || "Treatment record published.");
      } catch (error) {
        this.showToast(error.message || "Treatment record could not be published.", false);
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
        this.showToast(response.message || "Treatment record voided.");
      } catch (error) {
        const reasonErrors = error.errors?.void_reason;
        this.voidModal.error = (Array.isArray(reasonErrors)
          ? reasonErrors.join(" ")
          : reasonErrors)
          || error.message
          || "Treatment record could not be voided.";
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
        this.detailModal.error = error.message || "Treatment details could not be loaded.";
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

    typeLabel(record) { return record?.treatment_type === "medicine" ? "Medicine" : "Vaccine"; },

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
