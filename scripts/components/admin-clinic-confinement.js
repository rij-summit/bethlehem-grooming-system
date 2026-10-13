function clinicConfinementDuration(admittedAt, end = Date.now()) {
  if (!admittedAt) return '—';
  const minutes = Math.max(0, Math.floor((new Date(end).getTime() - new Date(admittedAt).getTime()) / 60000));
  const days = Math.floor(minutes / 1440), hours = Math.floor(minutes % 1440 / 60);
  return `${days ? days + 'd ' : ''}${hours}h ${minutes % 60}m`;
}

function clinicConfinementWorkspace() {
  const {money, methodLabel, sizeLabel, cataloguePrice} = clinicCaseWorkspace();
  return {
    money, methodLabel, sizeLabel, cataloguePrice,
    confinementCase: null, confinementCaseId: null, episode: null, loading: false, error: '', busy: false,
    options: [], optionsPage: 0, optionsLastPage: 1, optionsLoading: false,
    admissionForm: null, admissionPending: null, selectedService: null, pricing: null, pricingLoading: false,
    monitoringOpen: false, monitoringForm: null, monitoringPending: null,
    observations: [], observationPage: 0, observationLastPage: 1, observationsLoading: false,
    dischargeForm: null, dischargePending: null, dischargePreview: null, priceError: '',
    _generation: 0, _pricingRequest: 0, _visible: false,
    get clinicalConfinement() {
      const appt = this.confinementCase;
      return !!this.$store.clinicAccess.permissions.clinical && appt?.status === 'in_consultation' && !appt.paid && !appt.archived_at;
    },
    get canAdmitConfinement() { return this.clinicalConfinement && !this.episode && !this.confinementCase?.confinement && !this.loading; },
    get activeConfinement() { return !!this.episode && !this.episode.discharged_at; },
    get canCareForConfinement() { return this.clinicalConfinement && this.activeConfinement; },
    get confinementService() { return this.episode?.case_service?.service_snapshot || this.episode?.case_service?.clinic_service; },
    get confinementStoragePrefix() { return `clinic-confinement:${this.$store.clinicAccess.user?.user_id}:${this.confinementCaseId}:`; },
    formatConfinementTime(value) { return value ? new Date(value).toLocaleString('en-PH', {dateStyle: 'medium', timeStyle: 'short'}) : '—'; },
    duration() { return clinicConfinementDuration(this.episode?.admitted_at, this.episode?.discharged_at || this.$store.clinicConfinementClock?.now || Date.now()); },
    syncConfinement(open, appt, section) {
      if (!open) { if (this.confinementCaseId != null) ++this._generation; this.confinementCaseId = null; this._visible = false; return; }
      if (section !== 'confinement' || !appt?.id) { this._visible = false; return; }
      this.confinementCase = appt;
      if (this.confinementCaseId === appt.id && this._visible) return;
      if (this.confinementCaseId !== appt.id) {
        ++this._generation; ++this._pricingRequest;
        this.confinementCaseId = appt.id; this.episode = null; this.error = '';
        this.admissionForm = null; this.admissionPending = null; this.monitoringForm = null; this.monitoringPending = null;
        this.monitoringOpen = false; this.dischargeForm = null; this.dischargePending = null;
        this.selectedService = null; this.pricing = null; this.observations = [];
      }
      this._visible = true;
      this.loadConfinement();
    },
    retain(kind, value) {
      try { sessionStorage.setItem(this.confinementStoragePrefix + kind, JSON.stringify(value)); return true; }
      catch { this.error = 'The request could not be retained for a safe retry. Enable browser session storage before submitting.'; return false; }
    },
    restore(kind) {
      try { return JSON.parse(sessionStorage.getItem(this.confinementStoragePrefix + kind) || 'null'); }
      catch { this.error = 'The saved confinement draft could not be loaded.'; return null; }
    },
    clearRetained(kind) { sessionStorage.removeItem(this.confinementStoragePrefix + kind); },
    persistAdmission() { if (this.admissionForm) this.retain('admission', {form: this.admissionForm, pending: this.admissionPending}); },
    persistMonitoring() { if (this.monitoringForm) this.retain('monitoring', {form: this.monitoringForm, pending: this.monitoringPending}); },
    persistDischarge() { if (this.dischargeForm) this.retain('discharge', {form: this.dischargeForm, pending: this.dischargePending}); },
    setConfinementBusy(saving, id = this.confinementCaseId) {
      this.busy = saving;
      window.dispatchEvent(new CustomEvent('clinic-workspace-busy', {detail: {id, saving}}));
    },
    updateConfinementCase(status = 'in_consultation') {
      this.confinementCase = {...this.confinementCase, status, confinement: this.episode};
      window.dispatchEvent(new CustomEvent('clinic-case-patch', {detail: {id: this.confinementCaseId, status, confinement: this.episode}}));
      window.dispatchEvent(new CustomEvent('clinic-case-updated'));
    },
    async loadConfinement() {
      const generation = this._generation;
      this.loading = true; this.error = '';
      try {
        const response = await API.getClinicConfinement(this.confinementCaseId);
        if (generation !== this._generation) return;
        this.episode = response.data;
        if (this.episode) {
          this.admissionForm = null; this.admissionPending = null; this.clearRetained('admission');
          await this.loadObservations();
          if (generation !== this._generation) return;
          if (this.canCareForConfinement) {
            const monitor = this.restore('monitoring');
            if (monitor) { this.monitoringForm = monitor.form; this.monitoringPending = monitor.pending; this.monitoringOpen = true; }
            const discharge = this.restore('discharge');
            if (discharge) { this.dischargeForm = discharge.form; this.dischargePending = discharge.pending; await this.loadDischargePreview(); }
          }
        } else if (this.clinicalConfinement) {
          const admission = this.restore('admission');
          if (admission) {
            this.admissionForm = admission.form; this.admissionPending = admission.pending;
            await this.loadConfinementOptions();
            if (this.admissionForm.clinic_service_id) await this.previewAdmissionService();
          }
        }
      } catch (error) { if (generation === this._generation) this.error = error.message || 'Could not load confinement.'; }
      finally { if (generation === this._generation) this.loading = false; }
    },
    async loadConfinementOptions(page = 1) {
      const generation = this._generation;
      this.optionsLoading = true;
      try {
        const response = await API.clinicConfinementOptions(page);
        if (generation !== this._generation) return;
        this.options = page === 1 ? response.data : [...this.options, ...response.data];
        this.optionsPage = response.current_page; this.optionsLastPage = response.last_page;
        if (response.total === 1 && this.admissionForm && !this.admissionForm.clinic_service_id) {
          this.admissionForm.clinic_service_id = this.options[0].id; await this.previewAdmissionService();
        }
      } catch (error) { if (generation === this._generation) this.error = error.message || 'Could not load confinement services.'; }
      finally { if (generation === this._generation) this.optionsLoading = false; }
    },
    async openAdmission() {
      if (!this.canAdmitConfinement || this.busy) return;
      this.admissionForm = {clinic_service_id: '', reason: '', notes: '', request_key: crypto.randomUUID()};
      this.admissionPending = null; this.error = ''; this.persistAdmission();
      await this.loadConfinementOptions();
    },
    async previewAdmissionService() {
      const id = this.admissionForm?.clinic_service_id;
      if (!id) { this.selectedService = null; this.pricing = null; return; }
      const generation = this._generation, request = ++this._pricingRequest;
      this.pricingLoading = true; this.pricing = null; this.persistAdmission();
      try {
        const response = await API.clinicCaseServicePricing(this.confinementCaseId, id);
        if (generation !== this._generation || request !== this._pricingRequest) return;
        this.selectedService = response.data; this.pricing = response.pricing;
      } catch (error) { if (generation === this._generation && request === this._pricingRequest) this.error = error.message || 'Could not preview this service.'; }
      finally { if (generation === this._generation && request === this._pricingRequest) this.pricingLoading = false; }
    },
    async submitAdmission() {
      if (!this.canAdmitConfinement || this.busy || !this.admissionForm) return;
      if (!this.admissionPending && (!this.admissionForm.clinic_service_id || !this.admissionForm.reason.trim())) {
        this.error = 'Select a confinement service and enter the reason for admission.'; return;
      }
      const payload = this.admissionPending || {...this.admissionForm};
      this.admissionPending = payload;
      if (!this.retain('admission', {form: this.admissionForm, pending: payload})) { this.admissionPending = null; return; }
      const id = this.confinementCaseId, generation = this._generation;
      this.setConfinementBusy(true, id); this.error = '';
      try {
        const response = await API.clinicAdmitConfinement(id, payload);
        if (generation !== this._generation) return;
        this.clearRetained('admission');
        this.episode = response.data; this.admissionForm = null; this.admissionPending = null;
        await this.loadObservations();
        this.updateConfinementCase();
        this.$store.clinicFeedback.notify('Patient admitted for confinement.');
      } catch (error) {
        if (generation !== this._generation) return;
        if (error.errors || error.status === 422) { this.admissionPending = null; this.persistAdmission(); }
        this.error = error.message || 'Admission could not be confirmed. Retry this same request safely.';
      } finally { this.setConfinementBusy(false, id); }
    },
    async loadObservations(page = 1) {
      const generation = this._generation;
      this.observationsLoading = true;
      try {
        const response = await API.getClinicConfinementObservations(this.confinementCaseId, page);
        if (generation !== this._generation) return;
        this.observations = page === 1 ? response.data : [...this.observations, ...response.data];
        this.observationPage = response.current_page; this.observationLastPage = response.last_page;
      } catch (error) { if (generation === this._generation) this.error = error.message || 'Could not load monitoring history.'; }
      finally { if (generation === this._generation) this.observationsLoading = false; }
    },
    openMonitoring() {
      if (!this.canCareForConfinement || this.busy) return;
      this.monitoringForm ||= {request_key: crypto.randomUUID(), temperature_c: '', heart_rate_bpm: '', respiratory_rate_bpm: '', notes: ''};
      this.monitoringOpen = true; this.error = ''; this.persistMonitoring();
    },
    async submitMonitoring() {
      if (!this.canCareForConfinement || this.busy || !this.monitoringForm) return;
      const form = this.monitoringForm;
      if (![form.temperature_c, form.heart_rate_bpm, form.respiratory_rate_bpm, form.notes.trim()].some(value => value !== '' && value != null)) {
        this.error = 'Record at least one clinical value or observation.'; return;
      }
      const payload = this.monitoringPending || Object.fromEntries(Object.entries(form).map(([key, value]) => [key, value === '' ? null : value]));
      this.monitoringPending = payload;
      if (!this.retain('monitoring', {form, pending: payload})) { this.monitoringPending = null; return; }
      const id = this.confinementCaseId, generation = this._generation;
      this.setConfinementBusy(true, id); this.error = '';
      try {
        await API.clinicRecordConfinementObservation(id, payload);
        if (generation !== this._generation) return;
        this.clearRetained('monitoring');
        this.monitoringForm = null; this.monitoringPending = null; this.monitoringOpen = false;
        await this.loadObservations();
        this.$store.clinicFeedback.notify('Monitoring recorded.');
      } catch (error) {
        if (generation !== this._generation) return;
        if (error.errors || error.status === 422) { this.monitoringPending = null; this.persistMonitoring(); }
        this.error = error.message || 'Monitoring could not be confirmed. Retry this same request safely.';
      } finally { this.setConfinementBusy(false, id); }
    },
    async loadDischargePreview() {
      const generation = this._generation;
      this.pricingLoading = true;
      try {
        const response = await API.clinicConfinementDischargePreview(this.confinementCaseId);
        if (generation === this._generation) this.dischargePreview = response;
      } catch (error) { if (generation === this._generation) this.error = error.message || 'Could not preview discharge billing.'; }
      finally { if (generation === this._generation) this.pricingLoading = false; }
    },
    async openDischarge() {
      if (!this.canCareForConfinement || this.busy) return;
      this.error = ''; this.priceError = ''; this.dischargePreview = null;
      this.dischargeForm ||= {applied_price: '', discharge_note: ''};
      this.persistDischarge(); await this.loadDischargePreview();
    },
    async submitDischarge() {
      if (!this.canCareForConfinement || this.busy || !this.dischargeForm || this.pricingLoading) return;
      const pricing = this.dischargePreview?.pricing;
      if (!this.dischargePending && (!pricing || pricing.error)) return;
      this.priceError = !this.dischargePending && pricing.requires_final_price
        ? clinicFinalPriceError(this.dischargeForm.applied_price, pricing, this.money) : '';
      if (this.priceError) return;
      const payload = this.dischargePending || {discharge_note: this.dischargeForm.discharge_note,
        ...(pricing.requires_final_price ? {applied_price: this.dischargeForm.applied_price.trim()} : {})};
      this.dischargePending = payload;
      if (!this.retain('discharge', {form: this.dischargeForm, pending: payload})) { this.dischargePending = null; return; }
      const id = this.confinementCaseId, generation = this._generation;
      this.setConfinementBusy(true, id); this.error = ''; let succeeded = false;
      try {
        const response = await API.clinicDischargeConfinement(id, payload);
        if (generation !== this._generation) return;
        this.clearRetained('discharge');
        this.episode = response.data; this.dischargeForm = null; this.dischargePending = null;
        this.updateConfinementCase('for_payment');
        this.$store.clinicFeedback.notify('Patient discharged. The case is ready for Billing.'); succeeded = true;
      } catch (error) {
        if (generation !== this._generation) return;
        if (error.errors || error.status === 422) { this.dischargePending = null; this.persistDischarge(); }
        this.error = error.message || 'Discharge could not be confirmed. Retry to check this episode safely.';
      } finally { this.setConfinementBusy(false, id); }
      if (succeeded) this.selectSection?.('billing');
    },
    closeConfinementForm(kind) {
      if (this.busy || this[kind + 'Pending']) return;
      this[kind + 'Form'] = null; this.clearRetained(kind);
      if (kind === 'monitoring') this.monitoringOpen = false;
    },
  };
}
