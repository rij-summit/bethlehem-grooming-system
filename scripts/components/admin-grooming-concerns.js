const GROOMING_CONCERN_SEVERITIES = [
  { value: "low", label: "Low" },
  { value: "moderate", label: "Moderate" },
  { value: "urgent", label: "Urgent" },
];

const GROOMING_CONCERN_ACTIONS = [
  { value: "continue_with_observation", label: "Continue with observation" },
  { value: "pause_grooming", label: "Pause grooming" },
  { value: "stop_grooming", label: "Stop grooming" },
];

const TERMINAL_GROOMING_CONCERN_STATUSES = new Set(["resolved", "cancelled"]);

function emptyGroomingConcernForm(reportToken = "") {
  return {
    category: "",
    severity: "",
    internal_description: "",
    customer_message: "",
    recommended_grooming_action: "",
    acknowledgment_required: false,
    consent_required: false,
    internal_resolution_notes: "",
    report_token: reportToken,
  };
}

function emptyGroomingConcernTerminalDialog() {
  return {
    open: false,
    type: "",
    concern: null,
    internal_reason: "",
    customer_summary: "",
    busy: false,
    errors: {},
    error: "",
  };
}

function emptyGroomingConcernNotifyDialog() {
  return {
    open: false,
    concern: null,
    busy: false,
    error: "",
  };
}

function emptyGroomingConcernActionDialog() {
  return {
    open: false,
    type: "",
    concern: null,
    safety_override_reason: "",
    internal_resolution_notes: "",
    customer_resolution_summary: "",
    busy: false,
    errors: {},
    error: "",
  };
}

function adminGroomingConcernState() {
  return {
    medicalConcernCache: {},
    medicalConcernModal: {
      open: false,
      booking: null,
      pet: null,
      context: null,
      loading: false,
      error: "",
      notFound: false,
      concerns: [],
      view: "history",
      duplicateMessage: "",
    },
    medicalConcernFormModal: {
      mode: "create",
      concernId: null,
      customerFieldsEditable: true,
      recommendedActionEditable: true,
      internalNotesEditable: true,
      saving: false,
    },
    medicalConcernForm: emptyGroomingConcernForm(),
    medicalConcernFormErrors: {},
    medicalConcernFormErrorSummary: "",
    medicalConcernTerminal: emptyGroomingConcernTerminalDialog(),
    medicalConcernNotify: emptyGroomingConcernNotifyDialog(),
    medicalConcernActionDialog: emptyGroomingConcernActionDialog(),
    expandedMedicalConcernIds: {},
    medicalConcernDetailLoadingIds: {},
    medicalConcernToast: {
      show: false,
      ok: true,
      message: "",
    },
    medicalConcernToastTimer: null,
    medicalConcernSeverities: GROOMING_CONCERN_SEVERITIES,
    medicalConcernActions: GROOMING_CONCERN_ACTIONS,
    medicalConcernSelectOpen: {
      severity: false,
      recommended_grooming_action: false,
    },

    bookingPetIdentifier(pet) {
      return pet?.bookingPetId ?? pet?.booking_pet_id ?? pet?.id ?? null;
    },

    medicalConcernCacheKey(booking, pet) {
      const bookingId = booking?.id ?? booking?.bookingId ?? booking?.booking_id;
      const bookingPetId = this.bookingPetIdentifier(pet);
      return bookingId && bookingPetId ? `${bookingId}:${bookingPetId}` : "";
    },

    medicalConcernState(booking, pet) {
      const key = this.medicalConcernCacheKey(booking, pet);
      return this.medicalConcernCache[key] || {
        loading: false,
        error: "",
        notFound: false,
        context: null,
        concerns: [],
      };
    },

    isTerminalMedicalConcern(concern) {
      return TERMINAL_GROOMING_CONCERN_STATUSES.has(
        String(concern?.status || "").toLowerCase(),
      );
    },

    isActiveMedicalConcern(concern) {
      return !this.isTerminalMedicalConcern(concern);
    },

    medicalConcernSummary(booking, pet) {
      const concerns = this.medicalConcernState(booking, pet).concerns;
      const active = concerns.filter((concern) => this.isActiveMedicalConcern(concern));
      const severityRank = { low: 1, moderate: 2, urgent: 3 };
      const highest = active.reduce((current, concern) => {
        const severity = String(concern?.severity || "").toLowerCase();
        return (severityRank[severity] || 0) > (severityRank[current] || 0)
          ? severity
          : current;
      }, "");

      return {
        totalCount: concerns.length,
        activeCount: active.length,
        highestSeverity: highest,
      };
    },

    medicalConcernIndicatorLabel(booking, pet) {
      const summary = this.medicalConcernSummary(booking, pet);
      if (summary.activeCount > 0) {
        const severity = this.medicalConcernSeverityLabel(summary.highestSeverity);
        const countLabel = summary.activeCount === 1
          ? "1 active concern"
          : `${summary.activeCount} active concerns`;
        return severity ? `Medical Concern · ${countLabel} · ${severity}` : `Medical Concern · ${countLabel}`;
      }

      if (summary.totalCount > 0) {
        return `Medical Concern History · ${summary.totalCount}`;
      }

      return "";
    },

    medicalConcernIndicatorClass(booking, pet) {
      const summary = this.medicalConcernSummary(booking, pet);
      if (summary.activeCount === 0) return "is-history";
      if (summary.highestSeverity === "urgent") return "is-urgent";
      if (summary.highestSeverity === "moderate") return "is-moderate";
      return "is-low";
    },

    canReportMedicalConcern(booking, pet) {
      if (!booking?.id || !this.bookingPetIdentifier(pet)) return false;
      if (this.isPetGroomingFinished?.(pet)) return false;
      if (["stopped", "finished"].includes(this.petGroomingState?.(pet))) return false;

      const status = this.normalizeStatus?.(booking.status) || String(booking.status || "");
      return ["checked_in", "queued", "waiting", "in-progress"].includes(status);
    },

    async refreshMedicalConcernIndicators() {
      const contexts = [];
      const seen = new Set();

      for (const booking of [...(this.queuedList || []), ...(this.inProgressList || [])]) {
        for (const pet of booking?.pets || []) {
          const key = this.medicalConcernCacheKey(booking, pet);
          if (!key || seen.has(key)) continue;
          seen.add(key);
          contexts.push({ booking, pet });
        }
      }

      await Promise.allSettled(
        contexts.map(({ booking, pet }) =>
          this.loadMedicalConcerns(booking, pet, { updateModal: false }),
        ),
      );
    },

    async loadMedicalConcerns(
      booking = this.medicalConcernModal.booking,
      pet = this.medicalConcernModal.pet,
      { updateModal = true } = {},
    ) {
      const bookingId = booking?.id ?? booking?.bookingId ?? booking?.booking_id;
      const bookingPetId = this.bookingPetIdentifier(pet);
      const key = this.medicalConcernCacheKey(booking, pet);
      if (!bookingId || !bookingPetId || !key) return null;

      const previous = this.medicalConcernCache[key] || {};
      this.medicalConcernCache = {
        ...this.medicalConcernCache,
        [key]: {
          ...previous,
          loading: true,
          error: "",
          notFound: false,
        },
      };
      if (updateModal && this.currentMedicalConcernKey() === key) {
        this.medicalConcernModal.loading = true;
        this.medicalConcernModal.error = "";
        this.medicalConcernModal.notFound = false;
      }

      try {
        const response = await API.getAdminBookingPetMedicalConcerns(
          bookingId,
          bookingPetId,
        );
        const nextState = {
          loading: false,
          error: "",
          notFound: false,
          context: response.booking_pet || null,
          concerns: Array.isArray(response.concerns) ? response.concerns : [],
        };
        this.medicalConcernCache = {
          ...this.medicalConcernCache,
          [key]: nextState,
        };

        if (updateModal && this.currentMedicalConcernKey() === key) {
          this.applyMedicalConcernStateToModal(nextState);
        }

        this.refreshClinicReferralStatusesForConcerns?.(
          booking,
          pet,
          nextState.concerns,
        );

        return response;
      } catch (error) {
        const notFound = error.status === 404;
        const nextState = {
          ...previous,
          loading: false,
          error: notFound
            ? "The selected grooming pet record could not be found."
            : (error.message || "Medical concern history could not be loaded."),
          notFound,
          concerns: [],
        };
        this.medicalConcernCache = {
          ...this.medicalConcernCache,
          [key]: nextState,
        };

        if (updateModal && this.currentMedicalConcernKey() === key) {
          this.applyMedicalConcernStateToModal(nextState);
        }

        return null;
      }
    },

    applyMedicalConcernStateToModal(state) {
      this.medicalConcernModal.loading = Boolean(state.loading);
      this.medicalConcernModal.error = state.error || "";
      this.medicalConcernModal.notFound = Boolean(state.notFound);
      this.medicalConcernModal.context = state.context || null;
      this.medicalConcernModal.concerns = Array.isArray(state.concerns)
        ? state.concerns
        : [];
    },

    currentMedicalConcernKey() {
      return this.medicalConcernCacheKey(
        this.medicalConcernModal.booking,
        this.medicalConcernModal.pet,
      );
    },

    async openMedicalConcerns(booking, pet) {
      const key = this.medicalConcernCacheKey(booking, pet);
      if (!key) {
        this.showMedicalConcernToast(
          "This pet is missing the booking information needed to load concerns.",
          false,
        );
        return;
      }

      const cached = this.medicalConcernState(booking, pet);
      this.medicalConcernModal = {
        open: true,
        booking: { ...booking },
        pet: { ...pet },
        context: cached.context || null,
        loading: Boolean(cached.loading),
        error: cached.error || "",
        notFound: Boolean(cached.notFound),
        concerns: Array.isArray(cached.concerns) ? cached.concerns : [],
        view: "history",
        duplicateMessage: "",
      };
      this.medicalConcernFormErrors = {};
      this.medicalConcernFormErrorSummary = "";
      this.closeMedicalConcernSelects();
      this.medicalConcernTerminal = emptyGroomingConcernTerminalDialog();
      this.medicalConcernNotify = emptyGroomingConcernNotifyDialog();
      this.medicalConcernActionDialog = emptyGroomingConcernActionDialog();
      this.refreshIcons?.();
      this.$nextTick?.(() => this.$refs.medicalConcernDialog?.focus());
      await this.loadMedicalConcerns(booking, pet);
    },

    async openReportMedicalConcern(booking, pet) {
      await this.openMedicalConcerns(booking, pet);
      if (
        !this.medicalConcernModal.open
        || this.medicalConcernModal.loading
        || this.medicalConcernModal.error
        || this.medicalConcernModal.notFound
      ) {
        return;
      }
      this.openCreateMedicalConcern();
    },

    closeMedicalConcerns() {
      if (
        this.medicalConcernFormModal.saving
        || this.medicalConcernTerminal.busy
        || this.medicalConcernNotify.busy
        || this.medicalConcernActionDialog.busy
      ) {
        return;
      }

      this.medicalConcernModal.open = false;
      this.medicalConcernModal.view = "history";
      this.medicalConcernModal.duplicateMessage = "";
      this.medicalConcernFormErrors = {};
      this.medicalConcernFormErrorSummary = "";
      this.closeMedicalConcernSelects();
      this.medicalConcernTerminal = emptyGroomingConcernTerminalDialog();
      this.medicalConcernNotify = emptyGroomingConcernNotifyDialog();
      this.medicalConcernActionDialog = emptyGroomingConcernActionDialog();
    },

    openCreateMedicalConcern() {
      if (
        !this.canReportMedicalConcern(
          this.medicalConcernModal.booking,
          this.medicalConcernModal.pet,
        )
      ) {
        this.showMedicalConcernToast(
          "This pet is no longer eligible for a new grooming medical concern.",
          false,
        );
        return;
      }

      this.medicalConcernForm = emptyGroomingConcernForm(
        this.createMedicalConcernReportToken(),
      );
      this.medicalConcernFormModal = {
        mode: "create",
        concernId: null,
        customerFieldsEditable: true,
        recommendedActionEditable: true,
        internalNotesEditable: true,
        saving: false,
      };
      this.medicalConcernFormErrors = {};
      this.medicalConcernFormErrorSummary = "";
      this.medicalConcernModal.duplicateMessage = "";
      this.closeMedicalConcernSelects();
      this.medicalConcernModal.view = "form";
      this.refreshIcons?.();
    },

    openEditMedicalConcern(concern) {
      if (!this.medicalConcernActionAvailable(concern, "update")) return;

      this.medicalConcernForm = {
        category: concern.category || "",
        severity: concern.severity || "",
        internal_description: concern.internal_description || "",
        customer_message: concern.customer_message || "",
        recommended_grooming_action: concern.recommended_grooming_action || "",
        acknowledgment_required: Boolean(concern.acknowledgment_required),
        consent_required: Boolean(concern.consent_required),
        internal_resolution_notes: concern.internal_resolution_notes || "",
        report_token: "",
      };
      this.medicalConcernFormModal = {
        mode: "edit",
        concernId: concern.id,
        customerFieldsEditable: Boolean(concern.customer_visible_fields_editable),
        recommendedActionEditable: Boolean(concern.recommended_action_editable),
        internalNotesEditable: Boolean(concern.internal_resolution_notes_editable),
        saving: false,
      };
      this.medicalConcernFormErrors = {};
      this.medicalConcernFormErrorSummary = "";
      this.medicalConcernModal.duplicateMessage = "";
      this.closeMedicalConcernSelects();
      this.medicalConcernModal.view = "form";
      this.refreshIcons?.();
    },

    returnToMedicalConcernHistory() {
      if (this.medicalConcernFormModal.saving) return;
      this.medicalConcernModal.view = "history";
      this.medicalConcernFormErrors = {};
      this.medicalConcernFormErrorSummary = "";
      this.medicalConcernModal.duplicateMessage = "";
      this.closeMedicalConcernSelects();
      this.refreshIcons?.();
    },

    validateMedicalConcernForm() {
      const errors = {};
      const value = (field) => String(this.medicalConcernForm[field] ?? "").trim();
      const customerFieldsEditable = this.medicalConcernFormModal.customerFieldsEditable;

      if (
        this.medicalConcernFormModal.mode === "create"
        || customerFieldsEditable
      ) {
        if (!value("category")) {
          errors.category = "Concern category is required.";
        } else if (value("category").length > 50) {
          errors.category = "Concern category must not exceed 50 characters.";
        }
        if (!GROOMING_CONCERN_SEVERITIES.some((item) => item.value === value("severity"))) {
          errors.severity = "Select Low, Moderate, or Urgent severity.";
        }
        if (!value("customer_message")) {
          errors.customer_message = "A separate customer-visible message is required.";
        }
        if (!GROOMING_CONCERN_ACTIONS.some(
          (item) => item.value === value("recommended_grooming_action"),
        )) {
          errors.recommended_grooming_action = "Select a recommended grooming action.";
        }
      }

      if (!value("internal_description")) {
        errors.internal_description = "Internal staff observation is required.";
      }
      if (typeof this.medicalConcernForm.acknowledgment_required !== "boolean") {
        errors.acknowledgment_required = "Acknowledgment requirement must be Yes or No.";
      }
      if (typeof this.medicalConcernForm.consent_required !== "boolean") {
        errors.consent_required = "Consent requirement must be Yes or No.";
      }

      this.medicalConcernFormErrors = errors;
      this.medicalConcernFormErrorSummary = Object.values(errors)[0] || "";
      if (this.medicalConcernFormErrorSummary) {
        this.focusMedicalConcernValidationSummary();
        return false;
      }

      return true;
    },

    buildMedicalConcernPayload() {
      const text = (field) => String(this.medicalConcernForm[field] ?? "").trim();
      const payload = {
        internal_description: text("internal_description"),
      };
      const resolutionNotes = text("internal_resolution_notes");
      if (
        this.medicalConcernFormModal.internalNotesEditable
        && resolutionNotes
      ) {
        payload.internal_resolution_notes = resolutionNotes;
      }

      if (
        this.medicalConcernFormModal.mode === "create"
        || this.medicalConcernFormModal.customerFieldsEditable
      ) {
        Object.assign(payload, {
          category: text("category"),
          severity: text("severity"),
          customer_message: text("customer_message"),
          acknowledgment_required: Boolean(
            this.medicalConcernForm.acknowledgment_required,
          ),
          consent_required: Boolean(this.medicalConcernForm.consent_required),
        });
      }

      if (
        this.medicalConcernFormModal.mode === "create"
        || this.medicalConcernFormModal.recommendedActionEditable
      ) {
        payload.recommended_grooming_action = text("recommended_grooming_action");
      }

      if (this.medicalConcernFormModal.mode === "create") {
        delete payload.internal_resolution_notes;
        payload.report_token = this.medicalConcernForm.report_token;
      }

      return payload;
    },

    async saveMedicalConcern() {
      if (
        this.medicalConcernFormModal.saving
        || !this.validateMedicalConcernForm()
      ) {
        return;
      }

      const booking = this.medicalConcernModal.booking;
      const pet = this.medicalConcernModal.pet;
      const bookingId = booking?.id;
      const bookingPetId = this.bookingPetIdentifier(pet);
      if (!bookingId || !bookingPetId) return;

      this.medicalConcernFormModal.saving = true;
      this.medicalConcernFormErrors = {};
      this.medicalConcernFormErrorSummary = "";
      this.medicalConcernModal.duplicateMessage = "";

      try {
        const payload = this.buildMedicalConcernPayload();
        const editing = this.medicalConcernFormModal.mode === "edit";
        const response = editing
          ? await API.updateAdminBookingPetMedicalConcern(
              bookingId,
              bookingPetId,
              this.medicalConcernFormModal.concernId,
              payload,
            )
          : await API.createAdminBookingPetMedicalConcern(
              bookingId,
              bookingPetId,
              payload,
            );

        await this.loadMedicalConcerns(booking, pet);
        this.closeMedicalConcernSelects();
        this.medicalConcernModal.view = "history";
        this.showMedicalConcernToast(
          response.message || (editing
            ? "Medical concern updated."
            : "Medical concern reported."),
        );
      } catch (error) {
        const duplicateActiveCategory = error.status === 409
          && /active medical concern.*category|active.*category/i.test(error.message || "");

        if (duplicateActiveCategory) {
          this.medicalConcernModal.duplicateMessage = error.message;
          this.medicalConcernFormErrorSummary = error.message;
          await this.loadMedicalConcerns(booking, pet);
          this.focusMedicalConcernValidationSummary();
        } else {
          this.applyMedicalConcernBackendErrors(
            error,
            "The medical concern could not be saved.",
          );
        }

        if (error.status === 404 || error.status === 422) {
          await this.loadAdminBookings?.();
        }
      } finally {
        this.medicalConcernFormModal.saving = false;
      }
    },

    applyMedicalConcernBackendErrors(error, fallback) {
      const backendErrors = error.errors || {};
      this.medicalConcernFormErrors = Object.fromEntries(
        Object.entries(backendErrors).map(([field, messages]) => [
          field,
          Array.isArray(messages) ? messages.join(" ") : String(messages),
        ]),
      );
      this.medicalConcernFormErrorSummary = error.message || fallback;
      this.focusMedicalConcernValidationSummary();
    },

    medicalConcernFieldError(field) {
      return this.medicalConcernFormErrors[field] || "";
    },

    medicalConcernSelectOptions(field) {
      if (field === "severity") return this.medicalConcernSeverities;
      if (field === "recommended_grooming_action") return this.medicalConcernActions;
      return [];
    },

    medicalConcernSelectLabel(field, placeholder = "Select an option") {
      const value = String(this.medicalConcernForm[field] || "");
      return this.medicalConcernSelectOptions(field)
        .find((option) => option.value === value)?.label || placeholder;
    },

    medicalConcernSelectDisabled(field) {
      if (field === "severity") {
        return !this.medicalConcernFormModal.customerFieldsEditable;
      }
      if (field === "recommended_grooming_action") {
        return !this.medicalConcernFormModal.recommendedActionEditable;
      }
      return true;
    },

    openMedicalConcernSelect(field) {
      if (this.medicalConcernSelectDisabled(field)) return;
      this.closeMedicalConcernSelects();
      this.medicalConcernSelectOpen[field] = true;
    },

    toggleMedicalConcernSelect(field) {
      if (this.medicalConcernSelectDisabled(field)) return;
      const shouldOpen = !this.medicalConcernSelectOpen[field];
      this.closeMedicalConcernSelects();
      this.medicalConcernSelectOpen[field] = shouldOpen;
    },

    closeMedicalConcernSelects() {
      this.medicalConcernSelectOpen = {
        severity: false,
        recommended_grooming_action: false,
      };
    },

    selectMedicalConcernOption(field, value) {
      if (
        this.medicalConcernSelectDisabled(field)
        || !this.medicalConcernSelectOptions(field).some((option) => option.value === value)
      ) {
        return;
      }

      this.medicalConcernForm[field] = value;
      this.closeMedicalConcernSelects();

      if (this.medicalConcernFormErrors[field]) {
        const remainingErrors = { ...this.medicalConcernFormErrors };
        delete remainingErrors[field];
        this.medicalConcernFormErrors = remainingErrors;
        this.medicalConcernFormErrorSummary = Object.values(remainingErrors)[0] || "";
      }
    },

    focusMedicalConcernSelectOption(listbox, last = false) {
      const options = Array.from(listbox?.querySelectorAll('[role="option"]') || []);
      const target = last ? options.at(-1) : options[0];
      target?.focus();
    },

    moveMedicalConcernSelectFocus(currentOption, offset) {
      const listbox = currentOption?.closest('[role="listbox"]');
      const options = Array.from(listbox?.querySelectorAll('[role="option"]') || []);
      const currentIndex = options.indexOf(currentOption);
      if (currentIndex < 0 || options.length === 0) return;

      const nextIndex = (currentIndex + offset + options.length) % options.length;
      options[nextIndex]?.focus();
    },

    focusMedicalConcernValidationSummary() {
      this.$nextTick?.(() => this.$refs.medicalConcernValidationSummary?.focus());
    },

    medicalConcernActionAvailable(concern, action) {
      return Array.isArray(concern?.available_staff_actions)
        && concern.available_staff_actions.includes(action);
    },

    canEditMedicalConcern(concern) {
      return this.medicalConcernActionAvailable(concern, "update")
        && (
          Boolean(concern.customer_visible_fields_editable)
          || Boolean(concern.staff_internal_fields_editable)
      );
    },

    openMedicalConcernNotifyDialog(concern) {
      if (!this.medicalConcernActionAvailable(concern, "notify_customer")) {
        return;
      }

      this.medicalConcernNotify = {
        open: true,
        concern,
        busy: false,
        error: "",
      };
      this.refreshIcons?.();
      this.$nextTick?.(() => this.$refs.medicalConcernNotifyDialog?.focus());
    },

    closeMedicalConcernNotifyDialog() {
      if (this.medicalConcernNotify.busy) return;
      this.medicalConcernNotify = emptyGroomingConcernNotifyDialog();
    },

    async submitMedicalConcernNotification() {
      if (this.medicalConcernNotify.busy) return;

      const booking = this.medicalConcernModal.booking;
      const pet = this.medicalConcernModal.pet;
      const concern = this.medicalConcernNotify.concern;
      const bookingId = booking?.id;
      const bookingPetId = this.bookingPetIdentifier(pet);
      if (!bookingId || !bookingPetId || !concern?.id) return;

      this.medicalConcernNotify.busy = true;
      this.medicalConcernNotify.error = "";

      try {
        const response =
          await API.notifyCustomerAboutAdminBookingPetMedicalConcern(
            bookingId,
            bookingPetId,
            concern.id,
          );

        this.medicalConcernNotify = emptyGroomingConcernNotifyDialog();
        await this.loadMedicalConcerns(booking, pet);
        this.showMedicalConcernToast(
          response.message || "Medical concern sent to the customer.",
        );
      } catch (error) {
        this.medicalConcernNotify.error =
          error.message || "The concern could not be sent to the customer.";

        if (error.status === 404 || error.status === 409 || error.status === 422) {
          await this.loadMedicalConcerns(booking, pet);
        }
      } finally {
        this.medicalConcernNotify.busy = false;
      }
    },

    openMedicalConcernActionDialog(type, concern) {
      const actionName = type === "resume"
        ? "resume_grooming"
        : (type === "clinic_stop" ? "stop_for_clinic_transfer" : "apply_recommended_action");
      if (!this.medicalConcernActionAvailable(concern, actionName)) return;

      this.medicalConcernActionDialog = {
        ...emptyGroomingConcernActionDialog(),
        open: true,
        type,
        concern,
      };
      this.refreshIcons?.();
      this.$nextTick?.(() => this.$refs.medicalConcernActionDialog?.focus());
    },

    closeMedicalConcernActionDialog() {
      if (this.medicalConcernActionDialog.busy) return;
      this.medicalConcernActionDialog = emptyGroomingConcernActionDialog();
    },

    medicalConcernOperationalEffect(concern) {
      return {
        continue_with_observation:
          "This records that grooming may continue with observation. It does not start or resume grooming automatically.",
        pause_grooming:
          "The pet will remain in clinic holding but will no longer count as actively being groomed.",
        stop_grooming:
          "The pet will stop active grooming. It will not be marked normally finished, and payment still requires staff review.",
      }[concern?.recommended_grooming_action] || "The server will validate the current per-pet grooming state.";
    },

    clinicTransferStopEffect() {
      return "This explicitly ends grooming for this visit so the accepted clinic consultation can begin. The prior Pause audit is preserved in staff notes, no grooming finish time is created, and this does not authorize veterinary treatment.";
    },

    validateMedicalConcernActionDialog() {
      const dialog = this.medicalConcernActionDialog;
      const errors = {};
      if (
        dialog.type === "apply"
        && dialog.concern?.safety_override_required
        && !String(dialog.safety_override_reason || "").trim()
      ) {
        errors.safety_override_reason = "A safety override reason is required.";
      }
      if (dialog.type === "resume") {
        if (!String(dialog.internal_resolution_notes || "").trim()) {
          errors.internal_resolution_notes = "Internal resolution notes are required.";
        }
        if (!String(dialog.customer_resolution_summary || "").trim()) {
          errors.customer_resolution_summary =
            "A customer-safe resolution summary is required.";
        }
      }
      dialog.errors = errors;
      dialog.error = Object.values(errors)[0] || "";
      return Object.keys(errors).length === 0;
    },

    async submitMedicalConcernAction() {
      const dialog = this.medicalConcernActionDialog;
      if (dialog.busy || !this.validateMedicalConcernActionDialog()) return;

      const booking = this.medicalConcernModal.booking;
      const pet = this.medicalConcernModal.pet;
      const concern = dialog.concern;
      const bookingId = booking?.id;
      const bookingPetId = this.bookingPetIdentifier(pet);
      if (!bookingId || !bookingPetId || !concern?.id) return;

      dialog.busy = true;
      dialog.error = "";
      try {
        const response = dialog.type === "resume"
          ? await API.resumeAdminBookingPetGrooming(
              bookingId,
              bookingPetId,
              concern.id,
              {
                internal_resolution_notes:
                  String(dialog.internal_resolution_notes || "").trim(),
                customer_resolution_summary:
                  String(dialog.customer_resolution_summary || "").trim(),
              },
            )
          : await API.applyAdminBookingPetMedicalConcernAction(
              bookingId,
              bookingPetId,
              concern.id,
              dialog.type === "clinic_stop"
                ? { clinic_transfer_stop: true }
                : (concern.safety_override_required
                ? {
                    safety_override_reason:
                      String(dialog.safety_override_reason || "").trim(),
                  }
                : {}),
            );

        this.medicalConcernActionDialog = emptyGroomingConcernActionDialog();
        await this.loadMedicalConcerns(booking, pet);
        await this.loadAdminBookings?.();
        await this.loadClinicReferralStatus?.(
          booking,
          pet,
          concern,
          { updateModal: this.clinicReferralModal?.open },
        );
        this.showMedicalConcernToast(
          response.message || "Per-pet grooming state updated.",
        );
      } catch (error) {
        const backendErrors = error.errors || {};
        dialog.errors = {
          safety_override_reason: this.firstMedicalConcernError(
            backendErrors.safety_override_reason,
          ),
          internal_resolution_notes: this.firstMedicalConcernError(
            backendErrors.internal_resolution_notes,
          ),
          customer_resolution_summary: this.firstMedicalConcernError(
            backendErrors.customer_resolution_summary,
          ),
        };
        dialog.error = error.message || "The grooming action could not be applied.";
        if ([404, 409, 422].includes(error.status)) {
          await this.loadMedicalConcerns(booking, pet);
          await this.loadAdminBookings?.();
        }
      } finally {
        dialog.busy = false;
      }
    },

    async toggleMedicalConcernDetails(concern) {
      const id = String(concern?.id ?? "");
      if (!id) return;
      const opening = !this.expandedMedicalConcernIds[id];
      this.expandedMedicalConcernIds = {
        ...this.expandedMedicalConcernIds,
        [id]: opening,
      };
      this.refreshIcons?.();
      if (!opening || this.medicalConcernDetailLoadingIds[id]) return;

      const bookingId = this.medicalConcernModal.booking?.id;
      const bookingPetId = this.bookingPetIdentifier(this.medicalConcernModal.pet);
      this.medicalConcernDetailLoadingIds = {
        ...this.medicalConcernDetailLoadingIds,
        [id]: true,
      };

      try {
        const response = await API.getAdminBookingPetMedicalConcern(
          bookingId,
          bookingPetId,
          concern.id,
        );
        if (response.concern) {
          this.replaceMedicalConcernInCurrentHistory(response.concern);
        }
      } catch (error) {
        this.showMedicalConcernToast(
          error.message || "Medical concern details could not be refreshed.",
          false,
        );
      } finally {
        const next = { ...this.medicalConcernDetailLoadingIds };
        delete next[id];
        this.medicalConcernDetailLoadingIds = next;
      }
    },

    replaceMedicalConcernInCurrentHistory(updatedConcern) {
      const concerns = this.medicalConcernModal.concerns.map((concern) =>
        Number(concern.id) === Number(updatedConcern.id)
          ? updatedConcern
          : concern,
      );
      this.medicalConcernModal.concerns = concerns;
      const key = this.currentMedicalConcernKey();
      if (key && this.medicalConcernCache[key]) {
        this.medicalConcernCache = {
          ...this.medicalConcernCache,
          [key]: {
            ...this.medicalConcernCache[key],
            concerns,
          },
        };
      }
    },

    openMedicalConcernTerminalDialog(type, concern) {
      if (
        !["cancel", "resolve"].includes(type)
        || !this.medicalConcernActionAvailable(concern, type)
      ) {
        return;
      }

      this.medicalConcernTerminal = {
        open: true,
        type,
        concern,
        internal_reason: "",
        customer_summary: "",
        busy: false,
        errors: {},
        error: "",
      };
      this.refreshIcons?.();
      this.$nextTick?.(() => this.$refs.medicalConcernTerminalDialog?.focus());
    },

    closeMedicalConcernTerminalDialog() {
      if (this.medicalConcernTerminal.busy) return;
      this.medicalConcernTerminal = emptyGroomingConcernTerminalDialog();
    },

    validateMedicalConcernTerminal() {
      const errors = {};
      const internalReason = String(
        this.medicalConcernTerminal.internal_reason || "",
      ).trim();
      const customerSummary = String(
        this.medicalConcernTerminal.customer_summary || "",
      ).trim();

      if (!internalReason) {
        errors.internal_reason = this.medicalConcernTerminal.type === "cancel"
          ? "An internal cancellation reason is required."
          : "Internal resolution notes are required.";
      }
      if (
        this.medicalConcernTerminal.type === "resolve"
        && !customerSummary
      ) {
        errors.customer_summary = "A customer-safe resolution summary is required.";
      }

      this.medicalConcernTerminal.errors = errors;
      this.medicalConcernTerminal.error = Object.values(errors)[0] || "";
      return Object.keys(errors).length === 0;
    },

    async submitMedicalConcernTerminal() {
      if (
        this.medicalConcernTerminal.busy
        || !this.validateMedicalConcernTerminal()
      ) {
        return;
      }

      const booking = this.medicalConcernModal.booking;
      const pet = this.medicalConcernModal.pet;
      const concern = this.medicalConcernTerminal.concern;
      const bookingId = booking?.id;
      const bookingPetId = this.bookingPetIdentifier(pet);
      const internalReason = this.medicalConcernTerminal.internal_reason.trim();
      const customerSummary = this.medicalConcernTerminal.customer_summary.trim();
      this.medicalConcernTerminal.busy = true;
      this.medicalConcernTerminal.error = "";

      try {
        const response = this.medicalConcernTerminal.type === "cancel"
          ? await API.cancelAdminBookingPetMedicalConcern(
              bookingId,
              bookingPetId,
              concern.id,
              {
                internal_cancellation_reason: internalReason,
                customer_cancellation_summary: customerSummary || null,
              },
            )
          : await API.resolveAdminBookingPetMedicalConcern(
              bookingId,
              bookingPetId,
              concern.id,
              {
                internal_resolution_notes: internalReason,
                customer_resolution_summary: customerSummary,
              },
            );

        this.medicalConcernTerminal = emptyGroomingConcernTerminalDialog();
        await this.loadMedicalConcerns(booking, pet);
        this.showMedicalConcernToast(
          response.message || "Medical concern history updated.",
        );
      } catch (error) {
        const backendErrors = error.errors || {};
        this.medicalConcernTerminal.errors = {
          internal_reason: this.firstMedicalConcernError(
            backendErrors.internal_cancellation_reason
              || backendErrors.internal_resolution_notes,
          ),
          customer_summary: this.firstMedicalConcernError(
            backendErrors.customer_cancellation_summary
              || backendErrors.customer_resolution_summary,
          ),
        };
        this.medicalConcernTerminal.error =
          error.message || "The medical concern could not be closed.";
      } finally {
        this.medicalConcernTerminal.busy = false;
      }
    },

    firstMedicalConcernError(messages) {
      if (Array.isArray(messages)) return messages.join(" ");
      return messages ? String(messages) : "";
    },

    medicalConcernStatusLabel(status) {
      const labels = {
        open: "Open",
        awaiting_customer: "Awaiting customer",
        referred_to_clinic: "Referred to clinic",
        under_clinic_review: "Under clinic review",
        resolved: "Resolved",
        cancelled: "Cancelled",
      };
      return labels[status] || this.medicalConcernDisplayValue(status, "Unknown");
    },

    medicalConcernStatusClass(status) {
      return {
        open: "is-open",
        awaiting_customer: "is-awaiting",
        referred_to_clinic: "is-referred",
        under_clinic_review: "is-review",
        resolved: "is-resolved",
        cancelled: "is-cancelled",
      }[status] || "is-unknown";
    },

    medicalConcernSeverityLabel(severity) {
      return {
        low: "Low",
        moderate: "Moderate",
        urgent: "Urgent",
      }[severity] || "";
    },

    medicalConcernSeverityClass(severity) {
      return {
        low: "is-low",
        moderate: "is-moderate",
        urgent: "is-urgent",
      }[severity] || "is-unknown";
    },

    medicalConcernActionLabel(action) {
      return {
        continue_with_observation: "Continue with observation",
        pause_grooming: "Pause grooming",
        stop_grooming: "Stop grooming",
      }[action] || this.medicalConcernDisplayValue(action, "Not provided");
    },

    medicalConcernResponseLabel(status) {
      return {
        not_required: "Not required",
        pending: "Pending",
        acknowledged: "Acknowledged",
        approved: "Approved",
        declined: "Declined",
      }[status] || this.medicalConcernDisplayValue(status, "Unknown");
    },

    medicalConcernYesNo(value) {
      return value ? "Yes" : "No";
    },

    medicalConcernDisplayValue(value, fallback = "Not provided") {
      if (value === null || value === undefined || String(value).trim() === "") {
        return fallback;
      }
      return String(value)
        .replace(/_/g, " ")
        .replace(/\b\w/g, (character) => character.toUpperCase());
    },

    formatMedicalConcernDateTime(value) {
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

    createMedicalConcernReportToken() {
      if (window.crypto?.randomUUID) return window.crypto.randomUUID();
      if (window.crypto?.getRandomValues) {
        const bytes = window.crypto.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0"));
        return [
          hex.slice(0, 4).join(""),
          hex.slice(4, 6).join(""),
          hex.slice(6, 8).join(""),
          hex.slice(8, 10).join(""),
          hex.slice(10).join(""),
        ].join("-");
      }
      return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, (character) => {
        const random = Math.floor(Math.random() * 16);
        const value = character === "x" ? random : (random & 0x3) | 0x8;
        return value.toString(16);
      });
    },

    showMedicalConcernToast(message, ok = true) {
      clearTimeout(this.medicalConcernToastTimer);
      this.medicalConcernToast = {
        show: true,
        ok,
        message,
      };
      this.medicalConcernToastTimer = setTimeout(() => {
        this.medicalConcernToast.show = false;
      }, 4000);
      this.refreshIcons?.();
    },
  };
}
