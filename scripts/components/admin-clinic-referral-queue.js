function adminClinicReferralQueueState() {
  return {
    clinicReferrals: [],
    clinicReferralPendingCount: 0,
    clinicReferralLoading: false,
    clinicReferralError: "",
    clinicReferralFilters: { status: "", urgency: "", search: "" },
    clinicReferralModal: {
      open: false,
      loading: false,
      saving: false,
      confirming: false,
      error: "",
      referral: null,
      returnFocus: null,
    },

    initializeClinicReferralQueue() {
      window.__clinicReferralOpen = (publicId) => this.openClinicReferral(publicId);
    },

    async loadClinicReferrals() {
      this.clinicReferralLoading = true;
      this.clinicReferralError = "";
      try {
        const response = await API.getAdminClinicReferrals(this.clinicReferralFilters);
        this.clinicReferrals = response.referrals || [];
        this.clinicReferralPendingCount = Number(response.pending_count || 0);
      } catch (error) {
        this.clinicReferralError = error?.message || "Clinic referrals could not be loaded.";
      } finally {
        this.clinicReferralLoading = false;
        this.$nextTick?.(() => window.lucide?.createIcons());
      }
    },

    async applyClinicReferralFilters() {
      await this.loadClinicReferrals();
    },

    clinicReferralCardHtml(referral) {
      const pet = referral.pet || {};
      const urgencyClass = {
        emergency: "border-red-200 bg-red-50 text-red-800",
        urgent: "border-amber-200 bg-amber-50 text-amber-800",
        routine: "border-blue-200 bg-blue-50 text-blue-800",
      }[referral.urgency] || "border-slate-200 bg-slate-50 text-slate-700";
      const consent = referral.consent_decision
        ? referral.consent_decision.replaceAll("_", " ")
        : "Waiting for response";
      const blocked = referral.can_accept
        ? `<p class="mt-2 text-xs font-semibold text-emerald-700">Ready for clinic acceptance</p>`
        : `<p class="mt-2 text-xs font-semibold text-amber-800">${escapeHtml(referral.acceptance_blocked_reasons?.[0] || "Acceptance is currently blocked.")}</p>`;

      return `<article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <h3 class="truncate text-base font-bold text-[#2f4b66]">${escapeHtml(pet.name || "Pet")}</h3>
              <span class="rounded-full border px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide ${urgencyClass}">${escapeHtml(referral.urgency_label || referral.urgency)}</span>
            </div>
            <p class="mt-1 text-xs text-slate-500">${escapeHtml([pet.species, pet.breed, pet.size].filter(Boolean).join(" · ") || "Pet details not provided")}</p>
          </div>
          <span class="rounded-full bg-slate-100 px-2 py-1 text-[10px] font-bold text-slate-700">${escapeHtml(referral.status_label)}</span>
        </div>
        <dl class="mt-4 grid grid-cols-2 gap-3 text-xs">
          <div><dt class="font-semibold text-slate-400">Owner / presenter</dt><dd class="mt-1 font-semibold text-slate-700">${escapeHtml(referral.owner_name)}</dd></div>
          <div><dt class="font-semibold text-slate-400">Booking</dt><dd class="mt-1 font-semibold text-slate-700">${escapeHtml(referral.booking_reference)}</dd></div>
          <div><dt class="font-semibold text-slate-400">Concern severity</dt><dd class="mt-1 capitalize text-slate-700">${escapeHtml(referral.concern?.severity || "Not provided")}</dd></div>
          <div><dt class="font-semibold text-slate-400">Consent</dt><dd class="mt-1 capitalize text-slate-700">${escapeHtml(consent)}</dd></div>
          <div><dt class="font-semibold text-slate-400">Grooming state</dt><dd class="mt-1 text-slate-700">${escapeHtml(referral.grooming_state_label)}</dd></div>
          <div><dt class="font-semibold text-slate-400">Applied action</dt><dd class="mt-1 capitalize text-slate-700">${escapeHtml((referral.concern?.applied_grooming_action || "Not applied").replaceAll("_", " "))}</dd></div>
        </dl>
        <div class="mt-3 rounded-xl border border-slate-100 bg-slate-50 p-3">
          <p class="text-xs font-semibold text-slate-500">Customer-friendly complaint</p>
          <p class="mt-1 text-sm leading-5 text-slate-700">${escapeHtml(referral.customer_explanation)}</p>
        </div>
        ${blocked}
        <button type="button" onclick="window.__clinicReferralOpen('${escapeHtml(referral.public_id)}')" class="mt-4 inline-flex min-h-10 w-full items-center justify-center rounded-xl bg-[#315b7e] px-4 py-2 text-sm font-semibold text-white transition hover:bg-[#274864]">View Referral</button>
      </article>`;
    },

    async openClinicReferral(publicId) {
      this.clinicReferralModal = {
        open: true,
        loading: true,
        saving: false,
        confirming: false,
        error: "",
        referral: null,
        returnFocus: document.activeElement,
      };
      this.$nextTick?.(() => this.$refs.clinicReferralQueueDialog?.focus());
      await this.reloadClinicReferralDetail(publicId);
    },

    async reloadClinicReferralDetail(publicId = this.clinicReferralModal.referral?.public_id) {
      if (!publicId) return;
      this.clinicReferralModal.loading = true;
      this.clinicReferralModal.error = "";
      try {
        const response = await API.getAdminClinicReferral(publicId);
        this.clinicReferralModal.referral = response.referral;
      } catch (error) {
        this.clinicReferralModal.error = error?.status === 404
          ? "The clinic referral could not be found."
          : (error?.message || "Clinic referral details could not be loaded.");
      } finally {
        this.clinicReferralModal.loading = false;
        this.$nextTick?.(() => window.lucide?.createIcons());
      }
    },

    closeClinicReferralQueueModal() {
      if (this.clinicReferralModal.saving) return;
      const returnFocus = this.clinicReferralModal.returnFocus;
      this.clinicReferralModal.open = false;
      this.clinicReferralModal.confirming = false;
      this.$nextTick?.(() => returnFocus?.focus?.());
    },

    showClinicReferralAcceptConfirmation() {
      if (!this.clinicReferralModal.referral?.can_accept) return;
      this.clinicReferralModal.confirming = true;
      this.$nextTick?.(() => this.$refs.clinicReferralConfirmButton?.focus());
    },

    async acceptClinicReferral() {
      const publicId = this.clinicReferralModal.referral?.public_id;
      if (!publicId || this.clinicReferralModal.saving) return;
      this.clinicReferralModal.saving = true;
      this.clinicReferralModal.error = "";
      try {
        const response = await API.acceptAdminClinicReferral(publicId);
        this.clinicReferralModal.referral = response.referral;
        this.clinicReferralModal.confirming = false;
        await Promise.all([this.loadClinicReferrals(), this.loadQueue()]);
      } catch (error) {
        this.clinicReferralModal.error = error?.status === 409
          ? (error.message || "The referral could not be accepted because its state changed.")
          : (error?.message || "Clinic referral acceptance failed.");
        this.clinicReferralModal.confirming = false;
        if (error?.status === 409) await this.reloadClinicReferralDetail(publicId);
      } finally {
        this.clinicReferralModal.saving = false;
        this.$nextTick?.(() => window.lucide?.createIcons());
      }
    },

    clinicReferralDateTime(value) {
      if (!value) return "Not provided";
      const date = new Date(value);
      return Number.isNaN(date.getTime())
        ? String(value)
        : date.toLocaleString("en-PH", { dateStyle: "medium", timeStyle: "short" });
    },

    clinicReferralValue(value, fallback = "Not provided") {
      return value === null || value === undefined || value === "" ? fallback : value;
    },

    clinicReferralConsentLabel(referral) {
      if (referral?.consent_decision === "approved") return "Approved";
      if (referral?.consent_decision === "declined") return "Declined";
      return "Waiting for Response";
    },
  };
}

window.adminClinicReferralQueueState = adminClinicReferralQueueState;
