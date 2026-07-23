const VACCINATION_ADMINISTRATION_ROUTES = [
  { value: "subcutaneous", label: "Subcutaneous" },
  { value: "intramuscular", label: "Intramuscular" },
  { value: "intranasal", label: "Intranasal" },
  { value: "oral", label: "Oral" },
  { value: "other", label: "Other" },
];

function emptyVaccinationDraftForm() {
  return {
    vaccine_name: "",
    product_name: "",
    manufacturer: "",
    batch_number: "",
    administered_date: "",
    next_due_date: "",
    product_expiry_date: "",
    dose_amount: "",
    dose_unit: "",
    route: "",
    administration_site: "",
    administered_by_name: "",
    clinic_appointment_id: "",
    inventory_item_id: "",
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

    vaccineItems: [],
    inventoryLoading: false,
    inventoryError: "",
    inventoryLoaded: false,

    formModal: {
      open: false,
      mode: "create",
      recordId: null,
      saving: false,
      existingAppointment: null,
      existingInventoryItem: null,
      linkedProviderAccountName: "",
    },
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

    toast: {
      show: false,
      message: "",
      ok: true,
    },
    toastTimer: null,

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
      };
      this.petNotFound = false;
      this.error = "";

      if (changedPet) {
        this.records = [];
        this.closeChildDialogs();
      }

      this.loadVaccinations();
      if (!this.inventoryLoaded && !this.inventoryLoading) {
        this.loadVaccineItems();
      }
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

    async loadVaccineItems() {
      this.inventoryLoading = true;
      this.inventoryError = "";

      try {
        const items = [];
        let page = 1;
        let lastPage = 1;

        do {
          const response = await API.getAdminInventoryItems({
            category: "vaccine",
            page,
          });
          const pageItems = Array.isArray(response.data)
            ? response.data.filter((item) => item.category === "vaccine")
            : [];
          items.push(...pageItems);
          lastPage = Math.max(1, Number(response.last_page) || 1);
          page += 1;
        } while (page <= lastPage);

        this.vaccineItems = items;
        this.inventoryLoaded = true;
      } catch (error) {
        this.vaccineItems = [];
        this.inventoryError = error.message || "Vaccine inventory items could not be loaded.";
      } finally {
        this.inventoryLoading = false;
      }
    },

    openCreateForm() {
      if (!this.pet?.id) return;

      this.form = emptyVaccinationDraftForm();
      this.form.clinic_appointment_id = this.appointment?.id
        ? String(this.appointment.id)
        : "";
      this.formModal = {
        open: true,
        mode: "create",
        recordId: null,
        saving: false,
        existingAppointment: null,
        existingInventoryItem: null,
        linkedProviderAccountName: "",
      };
      this.formErrors = {};
      this.formErrorSummary = "";
      this.refreshIcons();
    },

    openEditForm(record) {
      if (record?.state !== "draft") return;

      this.form = {
        vaccine_name: record.vaccine_name || "",
        product_name: record.product_name || "",
        manufacturer: record.manufacturer || "",
        batch_number: record.batch_number || "",
        administered_date: record.administered_date || "",
        next_due_date: record.next_due_date || "",
        product_expiry_date: record.product_expiry_date || "",
        dose_amount: record.dose_amount ?? "",
        dose_unit: record.dose_unit || "",
        route: record.route || "",
        administration_site: record.administration_site || "",
        administered_by_name: record.administered_by_name || "",
        clinic_appointment_id: record.clinic_appointment_id
          ? String(record.clinic_appointment_id)
          : "",
        inventory_item_id: record.inventory_item_id
          ? String(record.inventory_item_id)
          : "",
        notes: record.notes || "",
      };
      this.formModal = {
        open: true,
        mode: "edit",
        recordId: record.id,
        saving: false,
        existingAppointment: record.clinic_appointment_id
          ? {
              id: record.clinic_appointment_id,
              label: record.clinic_appointment_reference || `Appointment #${record.clinic_appointment_id}`,
            }
          : null,
        existingInventoryItem: record.inventory_item_id
          ? {
              id: record.inventory_item_id,
              label: record.inventory_item_name || `Inventory item #${record.inventory_item_id}`,
            }
          : null,
        linkedProviderAccountName: record.administered_by_user_name || "",
      };
      this.formErrors = {};
      this.formErrorSummary = "";
      this.refreshIcons();
    },

    closeForm() {
      if (this.formModal.saving) return;
      this.formModal.open = false;
      this.formErrors = {};
      this.formErrorSummary = "";
    },

    appointmentOptions() {
      const options = [];
      if (this.appointment?.id) {
        options.push({
          id: this.appointment.id,
          label: this.appointment.appointment_reference || `Appointment #${this.appointment.id}`,
        });
      }

      const existing = this.formModal.existingAppointment;
      if (existing && !options.some((option) => Number(option.id) === Number(existing.id))) {
        options.push(existing);
      }

      return options;
    },

    inventoryOptions() {
      const options = this.vaccineItems.map((item) => ({
        id: item.item_id,
        label: item.item_name,
        quantity: item.quantity_on_hand,
        unit: item.unit,
      }));
      const existing = this.formModal.existingInventoryItem;

      if (existing && !options.some((option) => Number(option.id) === Number(existing.id))) {
        options.push(existing);
      }

      return options;
    },

    inventoryOptionLabel(item) {
      if (item.quantity === undefined || item.quantity === null) return item.label;
      const quantity = Number(item.quantity);
      const displayQuantity = Number.isFinite(quantity)
        ? quantity.toLocaleString("en-PH", { maximumFractionDigits: 3 })
        : item.quantity;
      return `${item.label} · ${displayQuantity}${item.unit ? ` ${item.unit}` : ""} on hand`;
    },

    validateForm() {
      const errors = {};
      const value = (field) => String(this.form[field] ?? "").trim();
      const maxLength = (field, max, label) => {
        if (value(field).length > max) {
          errors[field] = `${label} must not exceed ${max} characters.`;
        }
      };

      if (!value("vaccine_name")) {
        errors.vaccine_name = "Vaccine name is required.";
      }
      if (!value("administered_date")) {
        errors.administered_date = "Administration date is required.";
      }

      maxLength("vaccine_name", 150, "Vaccine name");
      maxLength("product_name", 150, "Product name");
      maxLength("manufacturer", 150, "Manufacturer");
      maxLength("batch_number", 100, "Batch number");
      maxLength("dose_unit", 30, "Dose unit");
      maxLength("administration_site", 100, "Administration site");
      maxLength("administered_by_name", 200, "Provider name");

      if (value("dose_amount")) {
        const dose = Number(value("dose_amount"));
        if (!Number.isFinite(dose) || dose <= 0) {
          errors.dose_amount = "Dose amount must be greater than zero.";
        }
      }

      if (
        value("route")
        && !this.administrationRoutes.some((route) => route.value === value("route"))
      ) {
        errors.route = "Choose a supported administration route.";
      }

      if (
        value("administered_date")
        && value("next_due_date")
        && value("next_due_date") < value("administered_date")
      ) {
        errors.next_due_date = "Next due date cannot be before the administration date.";
      }

      if (
        value("administered_date")
        && value("product_expiry_date")
        && value("product_expiry_date") < value("administered_date")
      ) {
        errors.product_expiry_date = "Product expiration date cannot be before the administration date.";
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
        vaccine_name: nullableText("vaccine_name"),
        product_name: nullableText("product_name"),
        manufacturer: nullableText("manufacturer"),
        batch_number: nullableText("batch_number"),
        administered_date: nullableText("administered_date"),
        next_due_date: nullableText("next_due_date"),
        product_expiry_date: nullableText("product_expiry_date"),
        dose_amount: nullableText("dose_amount"),
        dose_unit: nullableText("dose_unit"),
        route: nullableText("route"),
        administration_site: nullableText("administration_site"),
        administered_by_name: nullableText("administered_by_name"),
        clinic_appointment_id: nullableId("clinic_appointment_id"),
        inventory_item_id: nullableId("inventory_item_id"),
        notes: nullableText("notes"),
      };
    },

    async saveDraft() {
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
      if (record?.state !== "draft" || this.actionBusyId) return;

      const hasProvider = Boolean(
        record.administered_by_user_id
        || String(record.administered_by_name || "").trim(),
      );
      if (!hasProvider) {
        this.openEditForm(record);
        this.formErrors = {
          administered_by_name: "Add a provider name before publishing this record.",
        };
        this.formErrorSummary = this.formErrors.administered_by_name;
        this.focusValidationSummary();
        this.showToast("Provider information is required before publication.", false);
        return;
      }

      const confirmed = window.confirm(
        `Publish the ${record.vaccine_name} vaccination record?\n\n`
        + "Published records may become eligible for future customer visibility and their clinical facts cannot be edited normally afterward.",
      );
      if (!confirmed) return;

      this.actionBusyId = record.id;
      try {
        const response = await API.publishAdminPetVaccination(this.pet.id, record.id);
        await this.loadVaccinations();
        this.showToast(response.message || "Vaccination record published.");
      } catch (error) {
        this.showToast(error.message || "Vaccination record could not be published.", false);
      } finally {
        this.actionBusyId = null;
      }
    },

    openVoidDialog(record) {
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
      clearTimeout(this.toastTimer);
      this.toast = { show: true, message, ok };
      this.toastTimer = setTimeout(() => {
        this.toast.show = false;
      }, 3500);
      this.refreshIcons();
    },

    refreshIcons() {
      this.$nextTick(() => {
        if (window.lucide) window.lucide.createIcons();
      });
    },
  };
}
