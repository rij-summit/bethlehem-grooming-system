const STOPPED_PAYMENT_REVIEW_DECISIONS = [
  {
    value: "full_charge",
    label: "Full Charge",
    description: "Charge the pet's full original grooming subtotal.",
  },
  {
    value: "partial_charge",
    label: "Partial Charge",
    description: "Charge a reduced amount because only part of the grooming service was completed.",
  },
  {
    value: "no_charge",
    label: "No Charge",
    description: "Do not charge this pet for grooming.",
  },
];

function emptyStoppedPaymentReviewForm() {
  return {
    decision: "",
    final_pet_charge: "",
    internal_reason: "",
    customer_explanation: "",
  };
}

function emptyStoppedPaymentReviewConfirmation() {
  return {
    open: false,
    busy: false,
    error: "",
  };
}

function adminStoppedPaymentReviewState() {
  return {
    stoppedPaymentReviewCache: {},
    stoppedPaymentReviewModal: {
      open: false,
      booking: null,
      pet: null,
      loading: false,
      loaded: false,
      error: "",
      notFound: false,
      review: null,
    },
    stoppedPaymentReviewForm: emptyStoppedPaymentReviewForm(),
    stoppedPaymentReviewErrors: {},
    stoppedPaymentReviewErrorSummary: "",
    stoppedPaymentReviewConfirmation: emptyStoppedPaymentReviewConfirmation(),
    stoppedPaymentReviewReturnFocus: null,
    stoppedPaymentReviewToast: {
      show: false,
      ok: true,
      message: "",
    },
    stoppedPaymentReviewToastTimer: null,
    stoppedPaymentReviewDecisions: STOPPED_PAYMENT_REVIEW_DECISIONS,

    stoppedPaymentReviewCacheKey(booking, pet) {
      const bookingId = booking?.id ?? booking?.bookingId ?? booking?.booking_id;
      const bookingPetId = this.bookingPetIdentifier?.(pet)
        ?? pet?.bookingPetId
        ?? pet?.booking_pet_id
        ?? pet?.id;

      return bookingId && bookingPetId ? `${bookingId}:${bookingPetId}` : "";
    },

    stoppedPaymentReviewState(booking, pet) {
      const key = this.stoppedPaymentReviewCacheKey(booking, pet);

      return this.stoppedPaymentReviewCache[key] || {
        loading: false,
        loaded: false,
        error: "",
        notFound: false,
        review: null,
      };
    },

    isStoppedPaymentReviewPet(pet) {
      return this.petGroomingState?.(pet) === "stopped";
    },

    stoppedPaymentReviewStatus(booking, pet) {
      const state = this.stoppedPaymentReviewState(booking, pet);
      const review = state.review;

      if (state.loading && !state.loaded) return "loading";
      if (state.error) return "error";
      if (review?.review_status === "completed") return "completed";
      if (review?.review_status === "pending" && !review?.review_creation_allowed) {
        return "ineligible";
      }
      return "pending";
    },

    stoppedPaymentReviewCardLabel(booking, pet) {
      return {
        loading: "Loading payment review...",
        completed: "Payment review completed",
        ineligible: "Payment review unavailable",
        error: "Payment review could not be loaded",
        pending: "Payment review required",
      }[this.stoppedPaymentReviewStatus(booking, pet)];
    },

    stoppedPaymentReviewActionLabel(booking, pet) {
      const status = this.stoppedPaymentReviewStatus(booking, pet);
      if (status === "loading") return "Loading review...";
      if (status === "completed") return "View Payment Review";
      if (status === "error") return "Retry Payment Review";
      return "Review Payment";
    },

    stoppedPaymentReviewCardDetail(booking, pet) {
      const review = this.stoppedPaymentReviewState(booking, pet).review;
      if (review?.review_status !== "completed") return "";

      return `${review.decision_label || this.stoppedPaymentReviewDecisionLabel(review.decision)} · ${this.formatStoppedPaymentMoney(review.final_pet_charge)}`;
    },

    async refreshStoppedPaymentReviewStatuses() {
      const contexts = [];
      const seen = new Set();

      for (const booking of [...(this.queuedList || []), ...(this.inProgressList || [])]) {
        for (const pet of booking?.pets || []) {
          if (!this.isStoppedPaymentReviewPet(pet)) continue;

          const key = this.stoppedPaymentReviewCacheKey(booking, pet);
          if (!key || seen.has(key)) continue;
          seen.add(key);
          contexts.push({ booking, pet });
        }
      }

      await Promise.allSettled(
        contexts.map(({ booking, pet }) =>
          this.loadStoppedPaymentReview(booking, pet, { updateModal: false }),
        ),
      );
    },

    async loadStoppedPaymentReview(
      booking = this.stoppedPaymentReviewModal.booking,
      pet = this.stoppedPaymentReviewModal.pet,
      { updateModal = true } = {},
    ) {
      const bookingId = booking?.id ?? booking?.bookingId ?? booking?.booking_id;
      const bookingPetId = this.bookingPetIdentifier?.(pet)
        ?? pet?.bookingPetId
        ?? pet?.booking_pet_id
        ?? pet?.id;
      const key = this.stoppedPaymentReviewCacheKey(booking, pet);
      if (!bookingId || !bookingPetId || !key) return null;

      const previous = this.stoppedPaymentReviewCache[key] || {};
      const loadingState = {
        ...previous,
        loading: true,
        error: "",
        notFound: false,
      };
      this.stoppedPaymentReviewCache = {
        ...this.stoppedPaymentReviewCache,
        [key]: loadingState,
      };
      if (updateModal && this.currentStoppedPaymentReviewKey() === key) {
        this.applyStoppedPaymentReviewStateToModal(loadingState);
      }

      try {
        const response = await API.getAdminStoppedPaymentReview(
          bookingId,
          bookingPetId,
        );
        const nextState = {
          loading: false,
          loaded: true,
          error: "",
          notFound: false,
          review: response?.review || null,
        };
        this.stoppedPaymentReviewCache = {
          ...this.stoppedPaymentReviewCache,
          [key]: nextState,
        };

        if (updateModal && this.currentStoppedPaymentReviewKey() === key) {
          this.applyStoppedPaymentReviewStateToModal(nextState);
        }

        return response;
      } catch (error) {
        const notFound = error.status === 404;
        const nextState = {
          ...previous,
          loading: false,
          loaded: false,
          error: notFound
            ? "The selected grooming pet record could not be found."
            : (error.message || "Payment review status could not be loaded."),
          notFound,
          review: null,
        };
        this.stoppedPaymentReviewCache = {
          ...this.stoppedPaymentReviewCache,
          [key]: nextState,
        };

        if (updateModal && this.currentStoppedPaymentReviewKey() === key) {
          this.applyStoppedPaymentReviewStateToModal(nextState);
        }

        return null;
      }
    },

    applyStoppedPaymentReviewStateToModal(state) {
      this.stoppedPaymentReviewModal.loading = Boolean(state.loading);
      this.stoppedPaymentReviewModal.loaded = Boolean(state.loaded);
      this.stoppedPaymentReviewModal.error = state.error || "";
      this.stoppedPaymentReviewModal.notFound = Boolean(state.notFound);
      this.stoppedPaymentReviewModal.review = state.review || null;
    },

    currentStoppedPaymentReviewKey() {
      return this.stoppedPaymentReviewCacheKey(
        this.stoppedPaymentReviewModal.booking,
        this.stoppedPaymentReviewModal.pet,
      );
    },

    async openStoppedPaymentReview(booking, pet, returnFocus = null) {
      if (!this.isStoppedPaymentReviewPet(pet)) return;

      const key = this.stoppedPaymentReviewCacheKey(booking, pet);
      if (!key) {
        this.showStoppedPaymentReviewToast(
          "This pet is missing the booking information needed to load its payment review.",
          false,
        );
        return;
      }

      const cached = this.stoppedPaymentReviewState(booking, pet);
      this.stoppedPaymentReviewReturnFocus = returnFocus;
      this.stoppedPaymentReviewModal = {
        open: true,
        booking: { ...booking },
        pet: { ...pet },
        loading: Boolean(cached.loading),
        loaded: Boolean(cached.loaded),
        error: cached.error || "",
        notFound: Boolean(cached.notFound),
        review: cached.review || null,
      };
      this.stoppedPaymentReviewForm = emptyStoppedPaymentReviewForm();
      this.stoppedPaymentReviewErrors = {};
      this.stoppedPaymentReviewErrorSummary = "";
      this.stoppedPaymentReviewConfirmation = emptyStoppedPaymentReviewConfirmation();
      this.refreshIcons?.();
      this.$nextTick?.(() => this.$refs.stoppedPaymentReviewDialog?.focus());

      await this.loadStoppedPaymentReview(booking, pet);
    },

    closeStoppedPaymentReview() {
      if (this.stoppedPaymentReviewConfirmation.busy) return;

      this.stoppedPaymentReviewModal.open = false;
      this.stoppedPaymentReviewConfirmation = emptyStoppedPaymentReviewConfirmation();
      this.stoppedPaymentReviewErrors = {};
      this.stoppedPaymentReviewErrorSummary = "";
      const returnFocus = this.stoppedPaymentReviewReturnFocus;
      this.stoppedPaymentReviewReturnFocus = null;
      this.$nextTick?.(() => returnFocus?.focus?.());
    },

    stoppedPaymentReviewDecisionLabel(value) {
      return STOPPED_PAYMENT_REVIEW_DECISIONS.find(
        (decision) => decision.value === value,
      )?.label || "Not selected";
    },

    selectStoppedPaymentReviewDecision(decision) {
      if (!STOPPED_PAYMENT_REVIEW_DECISIONS.some((item) => item.value === decision)) {
        return;
      }

      this.stoppedPaymentReviewForm.decision = decision;
      this.stoppedPaymentReviewForm.final_pet_charge = "";
      this.stoppedPaymentReviewErrors = {
        ...this.stoppedPaymentReviewErrors,
        decision: "",
        final_pet_charge: "",
      };
    },

    stoppedPaymentReviewOriginalCents() {
      return this.stoppedPaymentMoneyToCents(
        this.stoppedPaymentReviewModal.review?.original_pet_subtotal,
      );
    },

    stoppedPaymentReviewFinalCents() {
      const decision = this.stoppedPaymentReviewForm.decision;
      if (decision === "full_charge") return this.stoppedPaymentReviewOriginalCents();
      if (decision === "no_charge") return 0;
      if (decision !== "partial_charge") return null;

      const value = String(this.stoppedPaymentReviewForm.final_pet_charge || "").trim();
      if (!/^\d+(?:\.\d{1,2})?$/.test(value)) return null;
      return this.stoppedPaymentMoneyToCents(value);
    },

    stoppedPaymentReviewAdjustmentCents() {
      const finalCents = this.stoppedPaymentReviewFinalCents();
      if (finalCents === null) return null;
      return this.stoppedPaymentReviewOriginalCents() - finalCents;
    },

    stoppedPaymentReviewFinalChargeDisplay() {
      const cents = this.stoppedPaymentReviewFinalCents();
      return cents === null ? "Select a decision" : this.formatStoppedPaymentCents(cents);
    },

    stoppedPaymentReviewAdjustmentDisplay() {
      const cents = this.stoppedPaymentReviewAdjustmentCents();
      return cents === null ? "Select a decision" : this.formatStoppedPaymentCents(cents);
    },

    validateStoppedPaymentReviewForm() {
      const errors = {};
      const form = this.stoppedPaymentReviewForm;
      const decision = String(form.decision || "");
      const originalCents = this.stoppedPaymentReviewOriginalCents();
      const internalReason = String(form.internal_reason || "").trim();
      const customerExplanation = String(form.customer_explanation || "").trim();

      if (!STOPPED_PAYMENT_REVIEW_DECISIONS.some((item) => item.value === decision)) {
        errors.decision = "Select Full Charge, Partial Charge, or No Charge.";
      } else if (decision === "full_charge" && originalCents === 0) {
        errors.decision = "A zero-subtotal pet must use No Charge.";
      }

      if (decision === "partial_charge") {
        const amount = String(form.final_pet_charge || "").trim();
        if (!amount) {
          errors.final_pet_charge = "Final charge is required for Partial Charge.";
        } else if (!/^\d+(?:\.\d{1,2})?$/.test(amount)) {
          errors.final_pet_charge = "Final charge must be a valid amount with no more than two decimal places.";
        } else {
          const finalCents = this.stoppedPaymentMoneyToCents(amount);
          if (finalCents <= 0) {
            errors.final_pet_charge = "Partial Charge must be greater than zero.";
          } else if (finalCents >= originalCents) {
            errors.final_pet_charge = "Partial Charge must be less than the original pet subtotal.";
          }
        }
      }

      if (!internalReason) {
        errors.internal_reason = "Internal staff reason is required.";
      } else if (internalReason.length > 5000) {
        errors.internal_reason = "Internal staff reason must not exceed 5,000 characters.";
      }

      if (!customerExplanation) {
        errors.customer_explanation = "Customer-friendly explanation is required.";
      } else if (customerExplanation.length > 2000) {
        errors.customer_explanation = "Customer-friendly explanation must not exceed 2,000 characters.";
      }

      this.stoppedPaymentReviewErrors = errors;
      this.stoppedPaymentReviewErrorSummary = Object.values(errors).filter(Boolean).join(" ");

      if (Object.keys(errors).length > 0) {
        this.$nextTick?.(() => this.$refs.stoppedPaymentReviewValidationSummary?.focus());
        return false;
      }

      return true;
    },

    stoppedPaymentReviewFieldError(field) {
      return this.stoppedPaymentReviewErrors[field] || "";
    },

    buildStoppedPaymentReviewPayload() {
      const payload = {
        decision: this.stoppedPaymentReviewForm.decision,
        internal_reason: String(this.stoppedPaymentReviewForm.internal_reason || "").trim(),
        customer_explanation: String(this.stoppedPaymentReviewForm.customer_explanation || "").trim(),
      };

      if (payload.decision === "partial_charge") {
        payload.final_pet_charge = this.stoppedPaymentCentsToMoney(
          this.stoppedPaymentReviewFinalCents(),
        );
      }

      return payload;
    },

    openStoppedPaymentReviewConfirmation() {
      if (!this.stoppedPaymentReviewModal.review?.review_creation_allowed) return;
      if (!this.validateStoppedPaymentReviewForm()) return;

      this.stoppedPaymentReviewConfirmation = {
        open: true,
        busy: false,
        error: "",
      };
      this.refreshIcons?.();
      this.$nextTick?.(() => this.$refs.stoppedPaymentReviewConfirmationDialog?.focus());
    },

    closeStoppedPaymentReviewConfirmation() {
      if (this.stoppedPaymentReviewConfirmation.busy) return;
      this.stoppedPaymentReviewConfirmation = emptyStoppedPaymentReviewConfirmation();
      this.$nextTick?.(() => this.$refs.stoppedPaymentReviewCompleteButton?.focus());
    },

    async submitStoppedPaymentReview() {
      if (this.stoppedPaymentReviewConfirmation.busy) return;
      if (!this.validateStoppedPaymentReviewForm()) {
        this.stoppedPaymentReviewConfirmation = emptyStoppedPaymentReviewConfirmation();
        return;
      }

      const booking = this.stoppedPaymentReviewModal.booking;
      const pet = this.stoppedPaymentReviewModal.pet;
      const review = this.stoppedPaymentReviewModal.review;
      const bookingId = booking?.id ?? booking?.bookingId ?? booking?.booking_id;
      const bookingPetId = this.bookingPetIdentifier?.(pet)
        ?? pet?.bookingPetId
        ?? pet?.booking_pet_id
        ?? pet?.id;
      const concernId = review?.concern_id;

      if (!bookingId || !bookingPetId || !concernId) {
        this.stoppedPaymentReviewConfirmation.error =
          "The selected pet's applied Stop Grooming concern is unavailable. Refresh and try again.";
        return;
      }

      this.stoppedPaymentReviewConfirmation.busy = true;
      this.stoppedPaymentReviewConfirmation.error = "";

      try {
        const response = await API.createAdminStoppedPaymentReview(
          bookingId,
          bookingPetId,
          concernId,
          this.buildStoppedPaymentReviewPayload(),
        );
        const exactRetry = Boolean(response?.review?.already_reviewed);
        await this.loadStoppedPaymentReview(booking, pet);
        await this.loadAdminBookings?.();
        this.stoppedPaymentReviewConfirmation = emptyStoppedPaymentReviewConfirmation();
        this.showStoppedPaymentReviewToast(
          exactRetry
            ? "The existing completed payment review was recovered."
            : (response?.message || "Payment review completed for the selected pet."),
          true,
        );
        this.$nextTick?.(() => this.$refs.stoppedPaymentReviewDialog?.focus());
      } catch (error) {
        if (error.status === 409) {
          await this.loadStoppedPaymentReview(booking, pet);
          this.stoppedPaymentReviewConfirmation = emptyStoppedPaymentReviewConfirmation();
          this.showStoppedPaymentReviewToast(
            `${error.message || "A different permanent review already exists."} The completed review has been reloaded.`,
            false,
          );
          return;
        }

        this.applyStoppedPaymentReviewBackendErrors(error);
        this.stoppedPaymentReviewConfirmation = emptyStoppedPaymentReviewConfirmation();
        this.$nextTick?.(() => this.$refs.stoppedPaymentReviewValidationSummary?.focus());
      } finally {
        this.stoppedPaymentReviewConfirmation.busy = false;
      }
    },

    applyStoppedPaymentReviewBackendErrors(error) {
      const fields = {};
      for (const [field, messages] of Object.entries(error?.errors || {})) {
        fields[field] = Array.isArray(messages) ? messages[0] : String(messages || "");
      }

      this.stoppedPaymentReviewErrors = fields;
      this.stoppedPaymentReviewErrorSummary = Object.values(fields).filter(Boolean).join(" ")
        || error?.message
        || "The payment review could not be completed.";
    },

    stoppedPaymentMoneyToCents(value) {
      const text = String(value ?? "0").trim();
      const negative = text.startsWith("-");
      const unsigned = text.replace(/^[+-]/, "");
      const [whole = "0", fraction = ""] = unsigned.split(".");
      const cents = (Number.parseInt(whole || "0", 10) * 100)
        + Number.parseInt(`${fraction}00`.slice(0, 2), 10);
      return negative ? -cents : cents;
    },

    stoppedPaymentCentsToMoney(cents) {
      const amount = Number.isFinite(Number(cents)) ? Number(cents) : 0;
      const sign = amount < 0 ? "-" : "";
      const absolute = Math.abs(Math.trunc(amount));
      return `${sign}${Math.floor(absolute / 100)}.${String(absolute % 100).padStart(2, "0")}`;
    },

    formatStoppedPaymentCents(cents) {
      return `₱${this.stoppedPaymentCentsToMoney(cents).replace(/\B(?=(\d{3})+(?!\d))/g, ",")}`;
    },

    formatStoppedPaymentMoney(value) {
      return this.formatStoppedPaymentCents(this.stoppedPaymentMoneyToCents(value));
    },

    formatStoppedPaymentDateTime(value) {
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

    showStoppedPaymentReviewToast(message, ok = true) {
      clearTimeout(this.stoppedPaymentReviewToastTimer);
      this.stoppedPaymentReviewToast = { show: true, ok, message };
      this.stoppedPaymentReviewToastTimer = setTimeout(() => {
        this.stoppedPaymentReviewToast.show = false;
      }, 4500);
      this.refreshIcons?.();
    },
  };
}
