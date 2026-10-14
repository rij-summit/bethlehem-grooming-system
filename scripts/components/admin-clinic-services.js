const CLINIC_SERVICE_MAX_PRICE = 100000;

function clinicServicePriceError(value) {
  const text = String(value ?? '').trim();
  if (!text || !Number.isFinite(Number(text)) || Number(text) < 0) return 'Enter a non-negative price.';
  if (Number(text) > CLINIC_SERVICE_MAX_PRICE) {
    return `Price must be ₱${CLINIC_SERVICE_MAX_PRICE.toLocaleString('en-PH', {minimumFractionDigits: 2})} or less.`;
  }
  if (!/^(?:\d+\.?\d{0,2}|\.\d{1,2})$/.test(text)) return 'Price must have no more than 2 decimal places.';
  return '';
}

function emptyClinicService() {
  return {
    name: '', category_id: '', pricing_method: 'fixed', billing_unit: '',
    notes: '', price: '', min_price: '', max_price: '', tier_basis: 'pet_size',
    tiers: ['small', 'medium', 'large', 'extra_large'].map(size => ({size, enabled: true, price: ''})),
  };
}

function clinicServicePricing(service) {
  const money = value => '₱' + Number(value).toLocaleString('en-PH', {maximumFractionDigits: 2});
  let label;
  switch (service.pricing_method) {
    case 'range': label = `${money(service.min_price)}–${money(service.max_price)}`; break;
    case 'starting_at': label = `From ${money(service.price)}`; break;
    case 'tiered': {
      const prices = service.tiers.map(tier => Number(tier.price));
      const min = Math.min(...prices), max = Math.max(...prices);
      label = `${money(min)}${min === max ? '' : '–' + money(max)} by size`;
      break;
    }
    default: label = money(service.price);
  }
  // Per service is the implicit billing unit; other units are shown explicitly.
  return label + (service.billing_unit && service.billing_unit !== 'service' ? ` / ${service.billing_unit}` : '');
}

function adminClinicServices() {
  return {
    rows: [], categories: [], q: '', category: '', status: 'active',
    total: 0, currentPage: 1, lastPage: 1,
    loading: false, error: '', loaded: false, optionsLoaded: false,
    _requestId: 0, _searchTimer: null, _activation: null,
    modalOpen: false, saving: false, busyId: null, editingId: null, formError: '',
    form: emptyClinicService(), details: null, _detailsTrigger: null,
    maximumPrice: CLINIC_SERVICE_MAX_PRICE, priceErrors: {},
    init() {},
    destroy() { clearTimeout(this._searchTimer); this.$refs.serviceDialog.close(); this.closeDetails(false); },
    async activate() {
      if (this.loaded) return;
      return this._activation ??= this.load().finally(() => { this._activation = null; });
    },
    pricing: clinicServicePricing,
    tierLabel(size) { return ({small: 'Small', medium: 'Medium', large: 'Large', extra_large: 'Extra Large'})[size]; },
    get filtersActive() { return !!(this.q.trim() || this.category || this.status !== 'active'); },
    async load(page = 1) {
      clearTimeout(this._searchTimer);
      const requestId = ++this._requestId;
      this.loading = true;
      this.error = '';
      const params = new URLSearchParams({page, status: this.status});
      if (this.q.trim()) params.set('q', this.q.trim());
      if (this.category) params.set('category_id', this.category);
      try {
        const [response, options] = await Promise.all([
          API.adminRequest('GET', `/admin/clinic-services?${params}`, null, {force: true}),
          this.optionsLoaded ? null : API.adminRequest('GET', '/admin/clinic-services/options'),
        ]);
        if (requestId !== this._requestId) return;
        if (options) {
          this.categories = options.categories;
          this.optionsLoaded = true;
        }
        if (page > response.last_page) { await this.load(response.last_page); return; }
        this.rows = response.data;
        this.total = response.total;
        this.currentPage = response.current_page;
        this.lastPage = response.last_page;
        this.loaded = true;
      } catch (error) {
        if (requestId === this._requestId) this.error = error.message || 'Unable to load services. Please try again.';
      } finally { if (requestId === this._requestId) this.loading = false; }
    },
    search() {
      // Invalidate an older response as soon as the query changes.
      this._requestId++;
      clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => this.load(), 300);
    },
    clearFilters() { this.q = ''; this.category = ''; this.status = 'active'; return this.load(); },
    openDetails(service, event) {
      this.details = service;
      this._detailsTrigger = event.currentTarget;
      this.$refs.detailsDialog.showModal();
      this.$nextTick(() => this.$refs.detailsClose.focus());
    },
    closeDetails(restoreFocus = true) {
      this.$refs.detailsDialog.close();
      this.details = null;
      if (restoreFocus) this._detailsTrigger?.focus();
      this._detailsTrigger = null;
    },
    openModal(service = null) {
      this.editingId = service?.id ?? null;
      this.form = emptyClinicService();
      if (service) {
        for (const key of Object.keys(this.form)) {
          if (service[key] != null) this.form[key] = service[key];
        }
        this.form.tiers = emptyClinicService().tiers.map(tier => {
          const saved = service.tiers?.find(row => row.size === tier.size);
          return {...tier, enabled: !!saved, price: saved?.price ?? ''};
        });
      }
      this.formError = '';
      this.priceErrors = {};
      this.modalOpen = true;
      this.$refs.serviceDialog.showModal();
      this.$nextTick(() => this.$refs.serviceName.focus());
    },
    closeModal(force = false) {
      if (this.saving && !force) return;
      this.$refs.serviceDialog.close();
      this.modalOpen = false;
    },
    payload() {
      const f = this.form;
      const payload = {
        name: f.name.trim(), category_id: f.category_id,
        pricing_method: f.pricing_method, billing_unit: f.billing_unit || null,
        notes: f.notes || null,
      };
      if (f.pricing_method === 'range') Object.assign(payload, {min_price: f.min_price, max_price: f.max_price});
      else if (f.pricing_method === 'tiered') Object.assign(payload, {
        tier_basis: f.tier_basis,
        tiers: f.tiers.filter(tier => tier.enabled).map(({size, price}) => ({size, price})),
      });
      else payload.price = f.price;
      return payload;
    },
    priceFields() {
      if (this.form.pricing_method === 'tiered') {
        return this.form.tiers.filter(tier => tier.enabled).map(tier => ({key: tier.size, value: tier.price}));
      }
      return (this.form.pricing_method === 'range' ? ['min_price', 'max_price'] : ['price'])
        .map(key => ({key, value: this.form[key]}));
    },
    validatePrice(key) {
      const field = this.priceFields().find(field => field.key === key);
      let error = field ? clinicServicePriceError(field.value) : '';
      if (!error && key === 'max_price' && !clinicServicePriceError(this.form.min_price)
        && Number(this.form.max_price) < Number(this.form.min_price)) {
        error = 'Maximum Price must be greater than or equal to Minimum Price.';
      }
      this.priceErrors[key] = error;
      if (key === 'min_price' && this.priceErrors.max_price) this.validatePrice('max_price');
      return !error;
    },
    priceInput(key) {
      // Recheck an existing error while correcting it; first validation happens on blur or Save.
      if (this.priceErrors[key]) this.validatePrice(key);
      if (key === 'min_price' && this.priceErrors.max_price) this.validatePrice('max_price');
    },
    async save() {
      if (this.saving) return;
      this.formError = '';
      const payload = this.payload();
      this.priceErrors = {};
      for (const field of this.priceFields()) this.validatePrice(field.key);
      if (Object.values(this.priceErrors).some(Boolean)) {
        this.$nextTick(() => this.$refs.serviceForm.querySelector('[aria-invalid="true"]')?.focus());
        return;
      }
      if (payload.tiers && !payload.tiers.length) { this.formError = 'Configure at least one pet size tier.'; return; }
      if (!this.$refs.serviceForm.reportValidity()) return;
      this.saving = true;
      try {
        await API.adminRequest(this.editingId ? 'PUT' : 'POST',
          `/admin/clinic-services${this.editingId ? '/' + this.editingId : ''}`, payload);
        this.closeModal(true);
        this.$store.clinicFeedback.notify(this.editingId ? 'Service updated.' : 'Service added.');
        await this.load(this.editingId ? this.currentPage : 1);
      } catch (error) {
        this.formError = error.errors ? Object.values(error.errors).flat().join(' ') : error.message || 'Unable to save service.';
        this.$nextTick(() => this.$refs.formError.focus());
      } finally { this.saving = false; }
    },
    async changeStatus(service) {
      if (this.busyId) return;
      const active = !service.is_active;
      if (!await this.$store.clinicFeedback.confirm({
        title: `${active ? 'Reactivate' : 'Deactivate'} service?`,
        message: `${service.name}. ${active ? 'This service will be available in the catalog.' : 'This service will become inactive. Existing case charges are preserved.'}`,
        confirmLabel: active ? 'Reactivate' : 'Deactivate', danger: !active, dismissOnEscape: false,
      })) return;
      this.busyId = service.id;
      try {
        await API.adminRequest('PATCH', `/admin/clinic-services/${service.id}/status`, {is_active: active});
        this.$store.clinicFeedback.notify(active ? 'Service reactivated.' : 'Service deactivated.');
        await this.load(this.currentPage);
      } catch (error) { this.$store.clinicFeedback.notify(error.message || 'Unable to update service status.', false); }
      finally { this.busyId = null; }
    },
  };
}
