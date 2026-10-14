// UX guidance only; the backend resolves catalogue prices authoritatively.
function clinicFinalPriceError(value, pricing, money) {
  const price = String(value ?? '').trim();
  if (!/^\d+(?:\.\d{1,2})?$/.test(price)) return 'Enter the final price using no more than 2 decimal places.';
  if (Number(price) < Number(pricing.min_price) || (pricing.max_price != null && Number(price) > Number(pricing.max_price)))
    return pricing.max_price != null ? `Enter an amount from ${money(pricing.min_price)} to ${money(pricing.max_price)}.`
      : `Enter an amount of at least ${money(pricing.min_price)}.`;
  return Number(price) > pricing.technical_max_price ? 'The final price exceeds the supported monetary limit.' : '';
}

// Case services use server pricing previews; historical lines use saved snapshots.
function clinicCaseWorkspace() {
  return {
    workspaceAppointment: null, workspaceSection: '', workspaceCaseId: null,
    serviceRows: [], servicePage: 0, serviceLastPage: 1, servicesLoading: false, servicesError: '',
    chargeRows: [], chargePage: 0, chargeLastPage: 1, billingLoading: false, billingError: '', billingTotal: null,
    searchQuery: '', searchRows: [], searchPage: 0, searchLastPage: 1, searchLoading: false, searchError: '',
    selectedService: null, servicePricing: null, pricingLoading: false,
    serviceForm: null, serviceErrors: {}, serviceError: '', serviceSaving: false, serviceRemovingId: null, pendingPayload: null,
    _generation: 0, _servicesRequest: 0, _billingRequest: 0, _billingScope: '', _searchRequest: 0, _selectionRequest: 0, _searchTimer: null, _savedWeight: null,

    get canMutateServices() {
      const appt = this.workspaceAppointment;
      return !!this.$store.clinicAccess.permissions.clinical && !appt?.paid && !appt?.archived_at
        && (['checked_in', 'in_consultation'].includes(appt?.status)
          || (API.getUserRole() === 'admin' && appt?.status === 'for_payment'));
    },
    get canRecordCasePayment() {
      return !!this.$store.clinicAccess.permissions.payment && this.workspaceAppointment?.status === 'for_payment'
        && !this.workspaceAppointment?.paid && !this.workspaceAppointment?.archived_at;
    },
    get treatmentCharges() { return this.chargeRows.filter(charge => ['medicine', 'vaccine'].includes(charge.kind)); },
    get needsFinalPrice() { return !!this.servicePricing?.requires_final_price; },
    get draftStorageKey() { return `clinic-service-draft:${this.$store.clinicAccess.user?.user_id}:${this.workspaceCaseId}`; },
    money(value) { return value == null ? '—' : Number(value).toLocaleString('en-PH', {style: 'currency', currency: 'PHP'}); },
    sizeLabel(size) { return ({small: 'Small', medium: 'Medium', large: 'Large', extra_large: 'Extra Large'})[size] || '—'; },
    methodLabel(method) { return ({fixed: 'Fixed', range: 'Range', starting_at: 'Starting At', tiered: 'Tiered'})[method] || method; },
    serviceInfo(entry) { return entry.service_snapshot || entry.clinic_service || {}; },
    serviceNotes(entry) { return entry.notes?.trim() ? entry.notes : '-'; },
    canRemoveService(entry) { return this.canMutateServices && entry.status === 'performed' && !entry.confinement_exists; },
    cataloguePrice(service) {
      if (service.pricing_method === 'range') return `${this.money(service.min_price)} – ${this.money(service.max_price)}`;
      if (service.pricing_method === 'starting_at') return `Starting at ${this.money(service.price)}`;
      if (service.pricing_method === 'tiered') return 'Price by case weight';
      return this.money(service.price);
    },
    priceRule() {
      if (this.selectedService?.pricing_method === 'range') return `Allowed price: ${this.money(this.servicePricing?.min_price)} – ${this.money(this.servicePricing?.max_price)}`;
      if (this.selectedService?.pricing_method === 'starting_at') return `Starting at ${this.money(this.servicePricing?.min_price)}`;
      return this.money(this.servicePricing?.resolved_price);
    },
    syncWorkspace(open, appt, section) {
      if (!open) {
        if (this.workspaceCaseId !== null) { ++this._generation; ++this._selectionRequest; ++this._searchRequest; }
        clearTimeout(this._searchTimer);
        this.workspaceCaseId = null;
        this.workspaceAppointment = null;
        this.workspaceSection = '';
        return;
      }
      if (!appt?.id) {
        clearTimeout(this._searchTimer);
        this.workspaceSection = '';
        return;
      }
      const weight = appt.vitals?.weight_kg;
      this.workspaceAppointment = appt;
      if (this.workspaceCaseId === appt.id && this.workspaceSection === section) {
        if (weight !== this._savedWeight) {
          this._savedWeight = weight;
          if (this.selectedService && !this.pendingPayload && this.canMutateServices) this.refreshPricing(this.selectedService.id);
        }
        return;
      }
      if (this.workspaceCaseId !== appt.id) {
        ++this._generation; ++this._selectionRequest; ++this._searchRequest;
        this.workspaceCaseId = appt.id;
        this.serviceRows = []; this.chargeRows = []; this.billingTotal = null;
        this.selectedService = null; this.servicePricing = null; this.serviceForm = null; this.pendingPayload = null;
        this.searchRows = []; this.searchQuery = ''; this.searchError = ''; this.serviceError = ''; this.serviceErrors = {};
        this.pricingLoading = false; this.searchLoading = false;
      }
      this._savedWeight = weight;
      this.workspaceSection = section;
      if (section === 'services') {
        if (!this.serviceForm) this.restoreDraft();
        this.loadServices();
        if (this.$store.clinicAccess.permissions.billing) this.loadBilling();
      }
      else if (section === 'billing' && this.$store.clinicAccess.permissions.billing) this.loadBilling();
    },
    destroy() { clearTimeout(this._searchTimer); ++this._generation; ++this._searchRequest; ++this._selectionRequest; },
    async loadServices(page = 1) {
      const generation = this._generation, id = this.workspaceCaseId, request = ++this._servicesRequest;
      this.servicesLoading = true; this.servicesError = '';
      try {
        const response = await API.getClinicCaseServices(id, page);
        if (generation !== this._generation || request !== this._servicesRequest) return;
        this.serviceRows = page === 1 ? response.data : [...this.serviceRows, ...response.data];
        this.servicePage = response.current_page; this.serviceLastPage = response.last_page;
      } catch (error) { if (generation === this._generation && request === this._servicesRequest) this.servicesError = error.message || 'Could not load case services.'; }
      finally { if (generation === this._generation && request === this._servicesRequest) this.servicesLoading = false; }
    },
    async loadBilling(page = 1, scope = this.workspaceSection === 'services' ? 'treatment' : '') {
      const generation = this._generation, id = this.workspaceCaseId, request = ++this._billingRequest;
      this.billingLoading = true; this.billingError = '';
      try {
        const response = await API.getClinicCaseCharges(id, page, scope);
        if (generation !== this._generation || request !== this._billingRequest) return;
        this.chargeRows = page === 1 ? response.charges.data : [...this.chargeRows, ...response.charges.data];
        this.chargePage = response.charges.current_page; this.chargeLastPage = response.charges.last_page;
        this._billingScope = scope;
        this.billingTotal = response.total_amount;
        window.dispatchEvent(new CustomEvent('clinic-case-patch', {detail: {id, total_amount: response.total_amount}}));
      } catch (error) { if (generation === this._generation && request === this._billingRequest) this.billingError = error.message || 'Could not load billing.'; }
      finally { if (generation === this._generation && request === this._billingRequest) this.billingLoading = false; }
    },
    searchServices() {
      clearTimeout(this._searchTimer); ++this._searchRequest;
      this._searchTimer = setTimeout(() => this.loadOptions(), 250);
    },
    async loadOptions(page = 1) {
      if (!this.canMutateServices || this.serviceSaving || this.serviceRemovingId !== null || this.pendingPayload) return;
      const generation = this._generation, request = ++this._searchRequest;
      this.searchLoading = true; this.searchError = '';
      try {
        const response = await API.clinicCaseServiceOptions(this.searchQuery.trim(), page);
        if (generation !== this._generation || request !== this._searchRequest) return;
        this.searchRows = response.data; this.searchPage = response.current_page; this.searchLastPage = response.last_page;
      } catch (error) { if (generation === this._generation && request === this._searchRequest) this.searchError = error.message || 'Could not search the catalogue.'; }
      finally { if (generation === this._generation && request === this._searchRequest) this.searchLoading = false; }
    },
    async selectService(service) {
      if (!this.canMutateServices || this.serviceSaving || this.serviceRemovingId !== null || this.pendingPayload) return;
      const composer = this.$refs.serviceComposer, generation = this._generation;
      clearTimeout(this._searchTimer); ++this._searchRequest; this.searchLoading = false;
      this.serviceForm = {service_id: service.id, request_key: crypto.randomUUID(), notes: '', applied_price: ''};
      this.selectedService = service; this.serviceError = ''; this.serviceErrors = {}; this.searchRows = [];
      this.persistDraft();
      await this.refreshPricing(service.id);
      if (generation !== this._generation || this.selectedService?.id !== service.id) return;
      this.$nextTick?.(() => {
        composer?.scrollIntoView({block: 'nearest'});
        composer?.querySelector('input, textarea')?.focus();
      });
    },
    async refreshPricing(serviceId) {
      const generation = this._generation, request = ++this._selectionRequest;
      this.pricingLoading = true; this.servicePricing = null;
      try {
        const response = await API.clinicCaseServicePricing(this.workspaceCaseId, serviceId);
        if (generation !== this._generation || request !== this._selectionRequest) return;
        this.selectedService = response.data; this.servicePricing = response.pricing;
      } catch (error) { if (generation === this._generation && request === this._selectionRequest) this.serviceError = error.message || 'Could not load service pricing. The service may be inactive.'; }
      finally { if (generation === this._generation && request === this._selectionRequest) this.pricingLoading = false; }
    },
    persistDraft() {
      try {
        sessionStorage.setItem(this.draftStorageKey, JSON.stringify({form: this.serviceForm, pending: this.pendingPayload}));
        return true;
      } catch {
        this.serviceError = 'The service draft could not be retained for retries. Enable browser session storage before submitting.';
        return false;
      }
    },
    restoreDraft() {
      if (!this.canMutateServices) return;
      try {
        const draft = JSON.parse(sessionStorage.getItem(this.draftStorageKey) || 'null');
        if (!draft?.form?.service_id || !draft.form.request_key) return;
        this.serviceForm = draft.form; this.pendingPayload = draft.pending;
        this.refreshPricing(draft.form.service_id);
      } catch { this.serviceError = 'The saved service draft could not be loaded.'; }
    },
    dismissService() {
      if (this.serviceSaving || this.pendingPayload) return;
      ++this._selectionRequest;
      sessionStorage.removeItem(this.draftStorageKey);
      this.serviceForm = null; this.selectedService = null; this.servicePricing = null;
      this.pricingLoading = false; this.serviceError = ''; this.serviceErrors = {};
    },
    validateService() {
      this.serviceErrors = {};
      if (this.needsFinalPrice) {
        const error = clinicFinalPriceError(this.serviceForm.applied_price, this.servicePricing, this.money);
        if (error) this.serviceErrors.applied_price = error;
      }
      return Object.keys(this.serviceErrors).length === 0;
    },
    async submitService() {
      if (!this.canMutateServices || this.serviceSaving || this.serviceRemovingId !== null || !this.serviceForm) return;
      if (!this.pendingPayload && (this.pricingLoading || !this.servicePricing || !this.validateService()
        || this.servicePricing.error)) return;
      const id = this.workspaceCaseId, generation = this._generation, form = this.serviceForm;
      const payload = this.pendingPayload || {notes: form.notes, clinic_service_id: form.service_id, request_key: form.request_key,
        ...(this.needsFinalPrice ? {applied_price: form.applied_price.trim()} : {})};
      this.pendingPayload = payload;
      if (!this.persistDraft()) { this.pendingPayload = null; return; }
      this.serviceSaving = true; this.serviceError = '';
      window.dispatchEvent(new CustomEvent('clinic-workspace-busy', {detail: {id, saving: true}}));
      try {
        await API.clinicCreateCaseService(id, payload);
        sessionStorage.removeItem(this.draftStorageKey);
        if (generation !== this._generation) return;
        this.pendingPayload = null; this.serviceForm = null; this.selectedService = null; this.servicePricing = null;
        clearTimeout(this._searchTimer); ++this._searchRequest;
        this.searchQuery = ''; this.searchRows = []; this.searchPage = 0; this.searchLoading = false; this.searchError = '';
        await Promise.all([this.loadServices(), this.loadBilling()]);
        if (generation !== this._generation) return;
        this.$store.clinicFeedback.notify('Service added.');
        window.dispatchEvent(new CustomEvent('clinic-case-updated'));
      } catch (error) {
        if (generation !== this._generation) return;
        if (error.status === 422 || error.errors) {
          this.pendingPayload = null; this.persistDraft();
          this.serviceErrors = Object.fromEntries(Object.entries(error.errors || {}).map(([key, messages]) => [key, messages[0]]));
          this.serviceError = Object.values(this.serviceErrors)[0] || error.message;
          await this.refreshPricing(form.service_id);
        } else this.serviceError = 'The result could not be confirmed. Retry this same service request to check its result safely.';
      } finally {
        this.serviceSaving = false;
        window.dispatchEvent(new CustomEvent('clinic-workspace-busy', {detail: {id, saving: false}}));
      }
    },
    async removeService(entry) {
      if (!this.canRemoveService(entry) || this.serviceSaving || this.serviceRemovingId !== null || this.pendingPayload) return;
      const id = this.workspaceCaseId, generation = this._generation;
      this.serviceRemovingId = entry.id;
      let removing = false;
      try {
        const confirmed = await this.$store.clinicFeedback.confirm({title: 'Remove service?',
          message: `Remove "${this.serviceInfo(entry).name}" from this case? Its ${this.money(entry.charge?.amount ?? entry.applied_price)} charge will also be removed from Billing.`,
          confirmLabel: 'Remove Service', cancelLabel: 'Cancel', danger: true});
        if (!confirmed || generation !== this._generation || !this.canRemoveService(entry)) return;
        removing = true; this.servicesError = '';
        window.dispatchEvent(new CustomEvent('clinic-workspace-busy', {detail: {id, saving: true}}));
        await API.clinicDeleteCaseService(id, entry.id);
        if (generation !== this._generation) return;
        await Promise.all([this.loadServices(), this.loadBilling()]);
        if (generation !== this._generation) return;
        this.$store.clinicFeedback.notify('Service removed.');
        window.dispatchEvent(new CustomEvent('clinic-case-updated'));
      } catch (error) { if (generation === this._generation) this.servicesError = error.message || 'Could not remove this service.'; }
      finally {
        this.serviceRemovingId = null;
        if (removing) window.dispatchEvent(new CustomEvent('clinic-workspace-busy', {detail: {id, saving: false}}));
      }
    },
    async requestCasePayment() {
      if (!this.canRecordCasePayment || this.billingLoading || this.billingError || this.billingTotal == null) return;
      const generation = this._generation;
      if (this._billingScope === 'treatment') {
        await this.loadBilling(1, '');
        if (generation !== this._generation || this.billingError) return;
      }
      // The existing payment dialog must receive the complete reviewed breakdown.
      while (this.chargePage < this.chargeLastPage) {
        await this.loadBilling(this.chargePage + 1, '');
        if (generation !== this._generation || this.billingError) return;
      }
      if (!this.canRecordCasePayment) return;
      window.dispatchEvent(new CustomEvent('clinic-review-payment', {detail: {
        ...this.workspaceAppointment, charges: [...this.chargeRows], total_amount: this.billingTotal,
      }}));
    },
  };
}
