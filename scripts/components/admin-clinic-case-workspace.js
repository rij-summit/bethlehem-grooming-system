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
    serviceForm: null, serviceErrors: {}, serviceError: '', serviceSaving: false, pendingPayload: null,
    _generation: 0, _servicesRequest: 0, _billingRequest: 0, _searchRequest: 0, _selectionRequest: 0, _searchTimer: null, _savedWeight: null,

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
    get needsFinalPrice() { return this.serviceForm?.status === 'performed' && !!this.servicePricing?.requires_final_price; },
    get draftStorageKey() { return `clinic-service-draft:${this.$store.clinicAccess.user?.user_id}:${this.workspaceCaseId}`; },
    money(value) { return value == null ? '—' : Number(value).toLocaleString('en-PH', {style: 'currency', currency: 'PHP'}); },
    sizeLabel(size) { return ({small: 'Small', medium: 'Medium', large: 'Large', extra_large: 'Extra Large'})[size] || '—'; },
    methodLabel(method) { return ({fixed: 'Fixed', range: 'Range', starting_at: 'Starting At', tiered: 'Tiered'})[method] || method; },
    serviceInfo(entry) { return entry.service_snapshot || entry.clinic_service || {}; },
    isConfinementBase(entry) { return this.workspaceAppointment?.confinement?.clinic_case_service_id === entry.id; },
    categoryLabel(service) { return typeof service?.category === 'string' ? service.category : service?.category?.name; },
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
    serviceDate(entry) {
      const value = entry.performed_at || entry.cancelled_at || entry.ordered_at;
      return value ? new Date(value).toLocaleString('en-PH', {dateStyle: 'medium', timeStyle: 'short'}) : '—';
    },
    statusClass(status) {
      return ({ordered: 'border-amber-200 bg-amber-50 text-amber-800', performed: 'border-emerald-200 bg-emerald-50 text-emerald-700',
        cancelled: 'border-slate-200 bg-slate-50 text-slate-600'})[status];
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
      if (!['services', 'billing'].includes(section) || !appt?.id) {
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
        this.restoreDraft();
      }
      this._savedWeight = weight;
      this.workspaceSection = section;
      if (section === 'services') this.loadServices();
      else if (this.$store.clinicAccess.permissions.billing) this.loadBilling();
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
    async loadBilling(page = 1) {
      const generation = this._generation, id = this.workspaceCaseId, request = ++this._billingRequest;
      this.billingLoading = true; this.billingError = '';
      try {
        const response = await API.getClinicCaseCharges(id, page);
        if (generation !== this._generation || request !== this._billingRequest) return;
        this.chargeRows = page === 1 ? response.charges.data : [...this.chargeRows, ...response.charges.data];
        this.chargePage = response.charges.current_page; this.chargeLastPage = response.charges.last_page;
        this.billingTotal = response.total_amount;
      } catch (error) { if (generation === this._generation && request === this._billingRequest) this.billingError = error.message || 'Could not load billing.'; }
      finally { if (generation === this._generation && request === this._billingRequest) this.billingLoading = false; }
    },
    searchServices() {
      clearTimeout(this._searchTimer); ++this._searchRequest;
      this._searchTimer = setTimeout(() => this.loadOptions(), 250);
    },
    async loadOptions(page = 1) {
      if (!this.canMutateServices || this.pendingPayload) return;
      const generation = this._generation, request = ++this._searchRequest;
      this.searchLoading = true; this.searchError = '';
      try {
        const response = await API.clinicCaseServiceOptions(this.searchQuery.trim(), page);
        if (generation !== this._generation || request !== this._searchRequest) return;
        this.searchRows = response.data; this.searchPage = response.current_page; this.searchLastPage = response.last_page;
      } catch (error) { if (generation === this._generation && request === this._searchRequest) this.searchError = error.message || 'Could not search the catalogue.'; }
      finally { if (generation === this._generation && request === this._searchRequest) this.searchLoading = false; }
    },
    async selectService(service, entry = null) {
      if (!this.canMutateServices || this.serviceSaving || this.pendingPayload) return;
      clearTimeout(this._searchTimer); ++this._searchRequest; this.searchLoading = false;
      this.serviceForm = {service_id: service.id, occurrence_id: entry?.id || null, request_key: entry?.request_key || crypto.randomUUID(),
        status: 'performed', quantity: entry?.quantity || '1', notes: entry?.notes || '', applied_price: ''};
      this.selectedService = service; this.serviceError = ''; this.serviceErrors = {}; this.searchRows = [];
      this.persistDraft();
      await this.refreshPricing(service.id);
      this.$nextTick?.(() => this.$refs.serviceComposer?.scrollIntoView({block: 'nearest'}));
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
      const quantity = String(this.serviceForm?.quantity ?? '');
      if (!/^\d+(?:\.\d{1,2})?$/.test(quantity) || Number(quantity) < 0.01 || Number(quantity) > 99999999.99)
        this.serviceErrors.quantity = 'Enter a quantity greater than zero, with no more than 2 decimal places.';
      if (this.needsFinalPrice) {
        const error = clinicFinalPriceError(this.serviceForm.applied_price, this.servicePricing, this.money);
        if (error) this.serviceErrors.applied_price = error;
      }
      return Object.keys(this.serviceErrors).length === 0;
    },
    async submitService() {
      if (!this.canMutateServices || this.serviceSaving || !this.serviceForm) return;
      if (!this.pendingPayload && (this.pricingLoading || !this.servicePricing || !this.validateService()
        || (this.serviceForm.status === 'performed' && this.servicePricing.error))) return;
      const id = this.workspaceCaseId, generation = this._generation, form = this.serviceForm;
      const payload = this.pendingPayload || {status: form.status, quantity: form.quantity, notes: form.notes,
        ...(!form.occurrence_id ? {clinic_service_id: form.service_id, request_key: form.request_key} : {}),
        ...(this.needsFinalPrice ? {applied_price: form.applied_price.trim()} : {})};
      this.pendingPayload = payload;
      if (!this.persistDraft()) { this.pendingPayload = null; return; }
      this.serviceSaving = true; this.serviceError = '';
      window.dispatchEvent(new CustomEvent('clinic-workspace-busy', {detail: {id, saving: true}}));
      try {
        if (form.occurrence_id) await API.clinicUpdateCaseService(id, form.occurrence_id, payload);
        else await API.clinicCreateCaseService(id, payload);
        sessionStorage.removeItem(this.draftStorageKey);
        if (generation !== this._generation) return;
        this.pendingPayload = null; this.serviceForm = null; this.selectedService = null; this.servicePricing = null;
        await this.loadServices();
        this.$store.clinicFeedback.notify(payload.status === 'performed' ? 'Service performed. Billing charge created.' : 'Service ordered.');
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
    async cancelOrderedService(entry) {
      if (!this.canMutateServices || this.serviceSaving || this.pendingPayload || entry.status !== 'ordered') return;
      const id = this.workspaceCaseId, generation = this._generation;
      const confirmed = await this.$store.clinicFeedback.confirm({title: 'Cancel this service order?',
        message: this.serviceInfo(entry).name, confirmLabel: 'Cancel Order', cancelLabel: 'Keep Order', danger: true});
      if (!confirmed || generation !== this._generation || !this.canMutateServices) return;
      this.serviceSaving = true;
      window.dispatchEvent(new CustomEvent('clinic-workspace-busy', {detail: {id, saving: true}}));
      try {
        await API.clinicUpdateCaseService(id, entry.id, {status: 'cancelled'});
        await this.loadServices();
        this.$store.clinicFeedback.notify('Service order cancelled.');
      } catch (error) { this.servicesError = error.message || 'Could not cancel this service order.'; }
      finally { this.serviceSaving = false; window.dispatchEvent(new CustomEvent('clinic-workspace-busy', {detail: {id, saving: false}})); }
    },
    async requestCasePayment() {
      if (!this.canRecordCasePayment || this.billingLoading || this.billingError || this.billingTotal == null) return;
      const generation = this._generation;
      // The existing payment dialog must receive the complete reviewed breakdown.
      while (this.chargePage < this.chargeLastPage) {
        await this.loadBilling(this.chargePage + 1);
        if (generation !== this._generation || this.billingError) return;
      }
      if (!this.canRecordCasePayment) return;
      window.dispatchEvent(new CustomEvent('clinic-review-payment', {detail: {
        ...this.workspaceAppointment, charges: [...this.chargeRows], total_amount: this.billingTotal,
      }}));
    },
  };
}
