// Staff clinic-referral interface state for the existing Schedules medical-concern modal.
// Depends on: api.js and admin-grooming-concerns.js

const TERMINAL_CLINIC_REFERRAL_STATUSES = new Set(["completed", "cancelled"]);

function emptyClinicReferralForm(requestToken = "") {
  return {
    urgency: "",
    referral_reason: "",
    customer_explanation: "",
    allow_intake_before_consent: false,
    emergency_without_consent_reason: "",
    request_token: requestToken,
  };
}

function emptyClinicReferralConfirmation() {
  return { open: false, busy: false, error: "" };
}

function emptyClinicReferralInPersonForm() {
  return {
    decision: "",
    decision_maker_name: "",
    signature_name: "",
    errors: {},
    busy: false,
    confirming: false,
    error: "",
  };
}

function adminClinicReferralState() {
  return {
    clinicReferralCache: {},
    clinicReferralModal: {
      open: false,
      booking: null,
      pet: null,
      concern: null,
      view: "status",
      loading: false,
      error: "",
      notFound: false,
      response: null,
      returnFocus: null,
    },
    clinicReferralForm: emptyClinicReferralForm(),
    clinicReferralFormErrors: {},
    clinicReferralFormErrorSummary: "",
    clinicReferralConfirmation: emptyClinicReferralConfirmation(),
    clinicReferralInPerson: emptyClinicReferralInPersonForm(),

    clinicReferralBookingId(booking) {
      return booking?.id ?? booking?.bookingId ?? booking?.booking_id ?? null;
    },

    clinicReferralBookingPetId(pet) {
      return this.bookingPetIdentifier?.(pet)
        ?? pet?.bookingPetId
        ?? pet?.booking_pet_id
        ?? null;
    },

    clinicReferralConcernId(concern) {
      return concern?.id ?? concern?.concern_id ?? null;
    },

    clinicReferralCacheKey(booking, pet, concern) {
      const bookingId = this.clinicReferralBookingId(booking);
      const bookingPetId = this.clinicReferralBookingPetId(pet);
      const concernId = this.clinicReferralConcernId(concern);
      return bookingId && bookingPetId && concernId
        ? `${bookingId}:${bookingPetId}:${concernId}`
        : "";
    },

    clinicReferralStateFor(booking, pet, concern) {
      const key = this.clinicReferralCacheKey(booking, pet, concern);
      return this.clinicReferralCache[key] || {
        loaded: false,
        loading: false,
        error: "",
        notFound: false,
        exists: false,
        can_create: false,
        blocked_reason: "",
        context: null,
        referral: null,
      };
    },

    clinicReferralIsTerminal(referral) {
      return TERMINAL_CLINIC_REFERRAL_STATUSES.has(
        String(referral?.status || "").toLowerCase(),
      );
    },

    clinicReferralOtherActive(booking, pet, concern) {
      const bookingId = this.clinicReferralBookingId(booking);
      const bookingPetId = this.clinicReferralBookingPetId(pet);
      const currentKey = this.clinicReferralCacheKey(booking, pet, concern);
      const prefix = `${bookingId}:${bookingPetId}:`;
      return Object.entries(this.clinicReferralCache).find(([key, state]) =>
        key !== currentKey
        && key.startsWith(prefix)
        && state?.exists
        && !this.clinicReferralIsTerminal(state.referral),
      )?.[1]?.referral || null;
    },

    clinicReferralEffectiveCanCreate(booking, pet, concern) {
      const state = this.clinicReferralStateFor(booking, pet, concern);
      return Boolean(
        state.can_create
        && !state.exists
        && !this.clinicReferralOtherActive(booking, pet, concern),
      );
    },

    clinicReferralCreationBlockedReason(booking, pet, concern) {
      if (this.clinicReferralOtherActive(booking, pet, concern)) {
        return "This pet already has an active clinic referral under another medical concern.";
      }
      return this.clinicReferralStateFor(booking, pet, concern).blocked_reason
        || "This concern is not currently eligible for referral.";
    },

    clinicReferralActionVisible(booking, pet, concern) {
      const state = this.clinicReferralStateFor(booking, pet, concern);
      if (state.exists) return true;
      if (this.isTerminalMedicalConcern?.(concern)) return false;
      if (this.isPetGroomingFinished?.(pet)) return false;
      const bookingStatus = this.normalizeStatus?.(booking?.status)
        || String(booking?.status || "").toLowerCase();
      return !["released", "archived", "cancelled", "no_show", "no-show"].includes(
        bookingStatus,
      );
    },

    clinicReferralActionLabel(booking, pet, concern) {
      const state = this.clinicReferralStateFor(booking, pet, concern);
      if (state.loading) return "Checking clinic referral...";
      if (state.exists) return "View Clinic Referral";
      if (state.loaded && !this.clinicReferralEffectiveCanCreate(booking, pet, concern)) {
        return "Clinic Referral Status";
      }
      return "Refer to Clinic";
    },

    clinicReferralActionStatus(booking, pet, concern) {
      const state = this.clinicReferralStateFor(booking, pet, concern);
      if (state.exists) return state.referral?.status_label || "Clinic referral recorded";
      if (state.loaded && !this.clinicReferralEffectiveCanCreate(booking, pet, concern)) {
        return this.clinicReferralCreationBlockedReason(booking, pet, concern);
      }
      return "";
    },

    async refreshClinicReferralStatusesForConcerns(booking, pet, concerns = []) {
      await Promise.allSettled(
        concerns.map((concern) => this.loadClinicReferralStatus(
          booking,
          pet,
          concern,
          { updateModal: false },
        )),
      );
    },

    async loadClinicReferralStatus(
      booking = this.clinicReferralModal.booking,
      pet = this.clinicReferralModal.pet,
      concern = this.clinicReferralModal.concern,
      { updateModal = true } = {},
    ) {
      const bookingId = this.clinicReferralBookingId(booking);
      const bookingPetId = this.clinicReferralBookingPetId(pet);
      const concernId = this.clinicReferralConcernId(concern);
      const key = this.clinicReferralCacheKey(booking, pet, concern);
      if (!bookingId || !bookingPetId || !concernId || !key) return null;

      const previous = this.clinicReferralStateFor(booking, pet, concern);
      this.clinicReferralCache = {
        ...this.clinicReferralCache,
        [key]: { ...previous, loading: true, error: "", notFound: false },
      };
      if (updateModal && this.currentClinicReferralKey() === key) {
        this.clinicReferralModal.loading = true;
        this.clinicReferralModal.error = "";
        this.clinicReferralModal.notFound = false;
      }

      try {
        const response = await API.getAdminBookingPetClinicReferral(
          bookingId,
          bookingPetId,
          concernId,
        );
        const next = {
          loaded: true,
          loading: false,
          error: "",
          notFound: false,
          exists: Boolean(response.exists),
          can_create: Boolean(response.can_create),
          blocked_reason: response.blocked_reason || "",
          context: response.context || null,
          referral: response.referral || null,
          financial_correction_review_required: Boolean(
            response.financial_correction_review_required
              ?? response.referral?.financial_correction_review_required,
          ),
        };
        this.clinicReferralCache = { ...this.clinicReferralCache, [key]: next };
        if (updateModal && this.currentClinicReferralKey() === key) {
          this.applyClinicReferralStateToModal(next);
        }
        return response;
      } catch (error) {
        const notFound = error?.status === 404;
        const next = {
          ...previous,
          loaded: true,
          loading: false,
          error: notFound
            ? "The selected booking pet or medical concern could not be found."
            : (error?.message || "Clinic referral status could not be loaded."),
          notFound,
        };
        this.clinicReferralCache = { ...this.clinicReferralCache, [key]: next };
        if (updateModal && this.currentClinicReferralKey() === key) {
          this.applyClinicReferralStateToModal(next);
        }
        return null;
      }
    },

    applyClinicReferralStateToModal(state) {
      this.clinicReferralModal.loading = Boolean(state.loading);
      this.clinicReferralModal.error = state.error || "";
      this.clinicReferralModal.notFound = Boolean(state.notFound);
      this.clinicReferralModal.response = state;
    },

    currentClinicReferralKey() {
      return this.clinicReferralCacheKey(
        this.clinicReferralModal.booking,
        this.clinicReferralModal.pet,
        this.clinicReferralModal.concern,
      );
    },

    async openClinicReferral(booking, pet, concern, returnFocus = null) {
      const key = this.clinicReferralCacheKey(booking, pet, concern);
      if (!key) {
        this.showMedicalConcernToast?.(
          "This pet is missing the context needed for a clinic referral.",
          false,
        );
        return;
      }

      const cached = this.clinicReferralStateFor(booking, pet, concern);
      this.clinicReferralModal = {
        open: true,
        booking: { ...booking },
        pet: { ...pet },
        concern: { ...concern },
        view: cached.exists ? "status" : "status",
        loading: Boolean(cached.loading || !cached.loaded),
        error: cached.error || "",
        notFound: Boolean(cached.notFound),
        response: cached.loaded ? cached : null,
        returnFocus: returnFocus || document.activeElement,
      };
      this.clinicReferralFormErrors = {};
      this.clinicReferralFormErrorSummary = "";
      this.clinicReferralConfirmation = emptyClinicReferralConfirmation();
      this.clinicReferralInPerson = emptyClinicReferralInPersonForm();
      this.refreshIcons?.();
      this.$nextTick?.(() => this.$refs.clinicReferralDialog?.focus());

      const response = await this.loadClinicReferralStatus(booking, pet, concern);
      await this.refreshClinicReferralStatusesForConcerns?.(
        booking,
        pet,
        this.medicalConcernState?.(booking, pet)?.concerns || [concern],
      );
      if (
        response
        && !response.exists
        && this.clinicReferralEffectiveCanCreate(booking, pet, concern)
      ) {
        this.startClinicReferralRequest();
      }
    },

    closeClinicReferral() {
      if (
        this.clinicReferralConfirmation.busy
        || this.clinicReferralInPerson.busy
      ) return;
      const returnFocus = this.clinicReferralModal.returnFocus;
      this.clinicReferralModal.open = false;
      this.clinicReferralConfirmation = emptyClinicReferralConfirmation();
      this.clinicReferralInPerson = emptyClinicReferralInPersonForm();
      this.$nextTick?.(() => returnFocus?.focus?.());
    },

    startClinicReferralRequest() {
      const state = this.clinicReferralModal.response;
      if (
        !state?.can_create
        || state?.exists
        || this.clinicReferralOtherActive(
          this.clinicReferralModal.booking,
          this.clinicReferralModal.pet,
          this.clinicReferralModal.concern,
        )
      ) return;
      this.clinicReferralForm = emptyClinicReferralForm(
        this.createClinicReferralRequestToken(),
      );
      this.clinicReferralFormErrors = {};
      this.clinicReferralFormErrorSummary = "";
      this.clinicReferralModal.view = "form";
      this.$nextTick?.(() => this.$refs.clinicReferralUrgencyRoutine?.focus());
    },

    createClinicReferralRequestToken() {
      if (window.crypto?.randomUUID) return window.crypto.randomUUID();
      return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, (value) => {
        const random = Math.floor(Math.random() * 16);
        const output = value === "x" ? random : ((random & 3) | 8);
        return output.toString(16);
      });
    },

    clinicReferralNeedsEmergencyReason() {
      return this.clinicReferralForm.urgency === "emergency"
        || (
          this.clinicReferralForm.urgency === "urgent"
          && this.clinicReferralForm.allow_intake_before_consent
        );
    },

    clinicReferralConsentSummary() {
      if (this.clinicReferralForm.urgency === "emergency") {
        return "Consent is still requested, while documented emergency intake may proceed before it is recorded.";
      }
      if (
        this.clinicReferralForm.urgency === "urgent"
        && this.clinicReferralForm.allow_intake_before_consent
      ) {
        return "Documented urgent intake before consent was requested.";
      }
      return "Customer consent is required before clinic acceptance.";
    },

    validateClinicReferralForm() {
      const form = this.clinicReferralForm;
      const errors = {};
      if (!form.urgency) errors.urgency = "Select a referral urgency.";
      if (!String(form.referral_reason || "").trim()) {
        errors.referral_reason = "Internal referral reason is required.";
      } else if (String(form.referral_reason).length > 5000) {
        errors.referral_reason = "Internal referral reason may not exceed 5,000 characters.";
      }
      if (!String(form.customer_explanation || "").trim()) {
        errors.customer_explanation = "Customer-friendly explanation is required.";
      } else if (String(form.customer_explanation).length > 2000) {
        errors.customer_explanation = "Customer-friendly explanation may not exceed 2,000 characters.";
      }
      if (
        this.clinicReferralNeedsEmergencyReason()
        && !String(form.emergency_without_consent_reason || "").trim()
      ) {
        errors.emergency_without_consent_reason =
          "An internal intake-before-consent reason is required.";
      }
      this.clinicReferralFormErrors = errors;
      this.clinicReferralFormErrorSummary = Object.values(errors)[0] || "";
      if (Object.keys(errors).length) {
        this.$nextTick?.(() => this.$refs.clinicReferralValidation?.focus());
      }
      return Object.keys(errors).length === 0;
    },

    openClinicReferralConfirmation() {
      if (!this.validateClinicReferralForm()) return;
      this.clinicReferralConfirmation = { open: true, busy: false, error: "" };
      this.$nextTick?.(() => this.$refs.clinicReferralConfirmationDialog?.focus());
    },

    closeClinicReferralConfirmation() {
      if (this.clinicReferralConfirmation.busy) return;
      this.clinicReferralConfirmation = emptyClinicReferralConfirmation();
      this.$nextTick?.(() => this.$refs.clinicReferralSubmitButton?.focus());
    },

    clinicReferralPayload() {
      const form = this.clinicReferralForm;
      const payload = {
        urgency: form.urgency,
        referral_reason: String(form.referral_reason || "").trim(),
        customer_explanation: String(form.customer_explanation || "").trim(),
        request_token: form.request_token,
      };
      if (this.clinicReferralNeedsEmergencyReason()) {
        payload.emergency_without_consent_reason = String(
          form.emergency_without_consent_reason || "",
        ).trim();
      }
      return payload;
    },

    async submitClinicReferral() {
      if (this.clinicReferralConfirmation.busy || !this.validateClinicReferralForm()) return;
      const booking = this.clinicReferralModal.booking;
      const pet = this.clinicReferralModal.pet;
      const concern = this.clinicReferralModal.concern;
      this.clinicReferralConfirmation.busy = true;
      this.clinicReferralConfirmation.error = "";

      try {
        const response = await API.createAdminBookingPetClinicReferral(
          this.clinicReferralBookingId(booking),
          this.clinicReferralBookingPetId(pet),
          this.clinicReferralConcernId(concern),
          this.clinicReferralPayload(),
        );
        this.clinicReferralConfirmation = emptyClinicReferralConfirmation();
        this.clinicReferralModal.view = "status";
        await this.loadClinicReferralStatus(booking, pet, concern);
        await this.loadAdminBookings?.();
        this.showMedicalConcernToast?.(
          response.message || (response.already_exists
            ? "The existing clinic referral was recovered."
            : "Clinic referral request created."),
        );
      } catch (error) {
        const backendErrors = error?.errors || {};
        this.clinicReferralFormErrors = {
          urgency: this.firstMedicalConcernError?.(backendErrors.urgency) || "",
          referral_reason: this.firstMedicalConcernError?.(backendErrors.referral_reason) || "",
          customer_explanation: this.firstMedicalConcernError?.(backendErrors.customer_explanation) || "",
          emergency_without_consent_reason:
            this.firstMedicalConcernError?.(backendErrors.emergency_without_consent_reason) || "",
        };
        this.clinicReferralConfirmation.error = error?.message
          || "The clinic referral could not be created.";
        if (error?.status === 409) {
          this.clinicReferralConfirmation = emptyClinicReferralConfirmation();
          await this.loadClinicReferralStatus(booking, pet, concern);
          this.clinicReferralModal.view = "status";
          this.showMedicalConcernToast?.(
            "A permanent referral already exists. Its current status was reloaded.",
            false,
          );
        }
      } finally {
        this.clinicReferralConfirmation.busy = false;
      }
    },

    canRecordClinicReferralInPersonConsent(referral) {
      return Boolean(
        referral
        && !referral.owner_account_linked
        && referral.consent_status !== "recorded"
        && !this.clinicReferralIsTerminal(referral),
      );
    },

    openClinicReferralInPersonConsent() {
      const referral = this.clinicReferralModal.response?.referral;
      if (!this.canRecordClinicReferralInPersonConsent(referral)) return;
      this.clinicReferralInPerson = emptyClinicReferralInPersonForm();
      this.clinicReferralModal.view = "in_person";
      this.$nextTick?.(() => this.$refs.clinicReferralInPersonDecision?.focus());
    },

    validateClinicReferralInPerson() {
      const form = this.clinicReferralInPerson;
      const errors = {};
      if (!["approved", "declined"].includes(form.decision)) {
        errors.decision = "Select Approved or Declined.";
      }
      if (!String(form.decision_maker_name || "").trim()) {
        errors.decision_maker_name = "Decision-maker name is required.";
      }
      if (!String(form.signature_name || "").trim()) {
        errors.signature_name = "Typed signature name is required.";
      }
      form.errors = errors;
      form.error = Object.values(errors)[0] || "";
      return Object.keys(errors).length === 0;
    },

    confirmClinicReferralInPersonConsent() {
      if (!this.validateClinicReferralInPerson()) return;
      this.clinicReferralInPerson.confirming = true;
      this.$nextTick?.(() => this.$refs.clinicReferralInPersonConfirm?.focus());
    },

    async submitClinicReferralInPersonConsent() {
      const form = this.clinicReferralInPerson;
      if (form.busy || !this.validateClinicReferralInPerson()) return;
      const booking = this.clinicReferralModal.booking;
      const pet = this.clinicReferralModal.pet;
      const concern = this.clinicReferralModal.concern;
      form.busy = true;
      form.error = "";
      try {
        const response = await API.recordAdminBookingPetClinicReferralInPersonConsent(
          this.clinicReferralBookingId(booking),
          this.clinicReferralBookingPetId(pet),
          this.clinicReferralConcernId(concern),
          {
            decision: form.decision,
            decision_maker_name: String(form.decision_maker_name).trim(),
            signature_name: String(form.signature_name).trim(),
          },
        );
        form.confirming = false;
        this.clinicReferralModal.view = "status";
        await this.loadClinicReferralStatus(booking, pet, concern);
        this.showMedicalConcernToast?.(
          response.message || "The permanent in-person response was recorded.",
        );
      } catch (error) {
        form.error = error?.message || "The in-person response could not be recorded.";
        if (error?.status === 409) {
          form.confirming = false;
          this.clinicReferralModal.view = "status";
          await this.loadClinicReferralStatus(booking, pet, concern);
          this.showMedicalConcernToast?.(
            "The original permanent response was preserved and reloaded.",
            false,
          );
        }
      } finally {
        form.busy = false;
      }
    },

    clinicReferralStatusLabel(referral) {
      return {
        pending_consent: "Pending Customer Consent",
        pending_clinic_acceptance: "Pending Clinic Acceptance",
        accepted: "Accepted by Clinic",
        under_clinic_review: "Under Clinic Review",
        completed: "Completed",
        cancelled: "Cancelled",
      }[referral?.status] || referral?.status_label || "Unknown";
    },

    clinicReferralUrgencyLabel(value) {
      return { routine: "Routine", urgent: "Urgent", emergency: "Emergency" }[value]
        || this.medicalConcernDisplayValue?.(value, "Not provided")
        || "Not provided";
    },

    clinicReferralConsentLabel(referral) {
      if (referral?.consent_status !== "recorded") return "Waiting for Response";
      if (referral?.consent_response_channel === "in_person_staff_captured") {
        return referral?.consent_decision === "approved"
          ? "Approved · Recorded In Person"
          : "Declined · Recorded In Person";
      }
      return referral?.consent_decision === "approved" ? "Approved" : "Declined";
    },

    clinicReferralDisplayValue(value, fallback = "Not provided") {
      return value === null || value === undefined || String(value).trim() === ""
        ? fallback
        : String(value);
    },

    clinicReferralFormatDateTime(value) {
      if (!value) return "Not provided";
      const date = new Date(value);
      return Number.isNaN(date.getTime())
        ? String(value)
        : date.toLocaleString("en-PH", {
            year: "numeric",
            month: "short",
            day: "numeric",
            hour: "numeric",
            minute: "2-digit",
          });
    },

    clinicReferralConcernReference(concern = this.clinicReferralModal.concern) {
      return concern?.public_id || concern?.publicId || `Concern #${concern?.id || "—"}`;
    },

    clinicReferralPetBreed(pet = this.clinicReferralModal.pet) {
      return pet?.breed || pet?.petBreed || pet?.pet_breed || "Not provided";
    },
  };
}
