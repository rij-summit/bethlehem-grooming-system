function adminGroomingServices() {
  const sizes = ['small', 'medium', 'large', 'extra_large'];
  const labels = ['Small', 'Medium', 'Large', 'Extra Large'];
  return {
    rows: [], priceLimits: null, loading: false, loaded: false, error: '', busyId: null,
    groups: [{kind: 'package', label: 'Grooming Packages', action: 'Add Grooming Package'},
      {kind: 'ala_carte', label: 'Ala carte', action: 'Add Ala carte Service'}],
    form: null, editingId: null, saving: false, formError: '', fieldErrors: {},
    init() {},
    async activate() { if (!this.loaded && !this.loading) await this.load(); },
    async load() {
      this.loading = true;
      this.error = '';
      try {
        const response = await API.adminRequest('GET', '/admin/grooming/services', null, {force: true});
        this.rows = response.data;
        this.priceLimits = response.priceLimits;
        this.loaded = true;
      } catch (error) { this.error = error.message || 'Unable to load Grooming Services.'; }
      finally { this.loading = false; }
    },
    services(kind) { return this.rows.filter(service => service.kind === kind); },
    pricing(option) {
      const money = value => `₱${Number(value).toLocaleString('en-PH', {maximumFractionDigits: 2})}`;
      return money(option.minAmount) + (option.pricingType === 'starting_at' ? '+'
        : option.pricingType === 'range' ? `–${money(option.maxAmount)}` : '');
    },
    openEditor(kind, service = null) {
      this.editingId = service?.serviceId ?? null;
      this.fieldErrors = {};
      this.formError = '';
      const priceRows = kind === 'package' ? sizes.map((sizeKey, index) => {
        const saved = service?.priceOptions.find(option => option.sizeKey === sizeKey);
        return {sizeKey, label: labels[index], enabled: !service || !!saved,
          pricingType: saved?.pricingType || 'fixed', amount: saved ? String(saved.minAmount) : '',
          maximum: saved?.pricingType === 'range' ? String(saved.maxAmount) : ''};
      }) : [{sizeKey: '', label: 'Price', enabled: true,
        pricingType: service?.priceOptions[0]?.pricingType || 'fixed',
        amount: service ? String(service.priceOptions[0].minAmount) : '',
        maximum: service?.priceOptions[0]?.pricingType === 'range' ? String(service.priceOptions[0].maxAmount) : ''}];
      this.form = {kind, name: service?.name || '', petType: service?.petType || 'dog',
        descriptionItems: (service?.descriptionItems?.length ? service.descriptionItems : ['']).map((text, index) => ({key: index, text})),
        nextDescriptionKey: service?.descriptionItems?.length || 1,
        includedServiceIds: [...(service?.includedServiceIds || [])],
        isActive: service?.isActive ?? true, displayOrder: service?.displayOrder ?? this.rows.length,
        priceRows, defaultPrices: service?.defaultPriceOptions || []};
      this.$refs.editor.showModal();
      this.$nextTick(() => this.$refs.serviceName.focus());
    },
    closeEditor() {
      if (this.saving) return;
      this.$refs.editor.close();
      this.form = null;
    },
    addDescription() {
      this.form.descriptionItems.push({key: this.form.nextDescriptionKey++, text: ''});
      this.$nextTick(() => this.$refs.editor.querySelector('[data-description-row]:last-child input')?.focus());
    },
    removeDescription(index) {
      this.form.descriptionItems.splice(index, 1);
      this.$nextTick(() => {
        const inputs = this.$refs.editor.querySelectorAll('[data-description-row] input');
        inputs[Math.min(index, inputs.length - 1)]?.focus();
      });
    },
    restorePrices() {
      for (const option of this.form.defaultPrices) {
        const row = this.form.priceRows.find(row => row.sizeKey === option.sizeKey);
        if (row) Object.assign(row, {enabled: true, pricingType: option.pricingType,
          amount: String(option.minAmount), maximum: option.pricingType === 'range' ? String(option.maxAmount) : ''});
      }
      this.formError = '';
      this.fieldErrors = {};
    },
    validatePricing() {
      const limits = this.priceLimits?.[this.form.kind];
      const valid = value => limits && /^\d+(?:\.\d{1,2})?$/.test(String(value))
        && Number(value) >= limits.minimum && Number(value) <= limits.maximum;
      this.fieldErrors = {};
      const rows = this.form.priceRows.filter(row => row.enabled);
      if (!rows.length) this.fieldErrors.sizes = 'Enter size pricing.';
      let previous = 0;
      for (const row of rows) {
        const key = this.form.kind === 'package' ? `sizes.${row.sizeKey}.` : '';
        if (!valid(row.amount)) this.fieldErrors[key + 'amount'] = 'Enter a reasonable price amount.';
        else if (Number(row.amount) < previous) this.fieldErrors[key + 'amount'] = 'Price must not be lower than the preceding size.';
        previous = Number(row.amount);
        if (!['fixed', 'range', 'starting_at'].includes(row.pricingType)) this.fieldErrors[key + 'pricing_type'] = 'Choose a supported pricing type.';
        if (row.pricingType === 'range') {
          if (!valid(row.maximum)) this.fieldErrors[key + 'maximum'] = 'Enter a reasonable price amount.';
          else if (Number(row.maximum) <= Number(row.amount)) this.fieldErrors[key + 'maximum'] = 'Maximum price must be greater than minimum price.';
        }
      }
      this.formError = Object.values(this.fieldErrors).join(' ');
      return !this.formError;
    },
    async save() {
      if (this.saving || !this.$refs.form.reportValidity()) return;
      if (!this.validatePricing()) { this.$nextTick(() => this.$refs.formError.focus()); return; }
      const f = this.form;
      const price = row => ({pricing_type: row.pricingType, amount: row.amount,
        ...(row.pricingType === 'range' ? {maximum: row.maximum} : {})});
      const payload = {name: f.name.trim(), kind: f.kind, isActive: f.isActive, displayOrder: f.displayOrder,
        ...(f.kind === 'package' ? {petType: f.petType, descriptionItems: f.descriptionItems.map(item => item.text.trim()),
          includedServiceIds: f.includedServiceIds, sizes: Object.fromEntries(f.priceRows.filter(row => row.enabled).map(row => [row.sizeKey, price(row)]))}
          : price(f.priceRows[0]))};
      this.saving = true;
      this.formError = '';
      this.fieldErrors = {};
      try {
        const response = await API.adminRequest(this.editingId ? 'PUT' : 'POST',
          `/admin/grooming/services${this.editingId ? '/' + this.editingId : ''}`, payload);
        this.rows = this.rows.filter(row => row.serviceId !== response.data.serviceId).concat(response.data)
          .sort((a, b) => a.displayOrder - b.displayOrder || a.serviceId - b.serviceId);
        this.saving = false;
        this.closeEditor();
        this.$store.clinicFeedback.notify(this.editingId ? 'Service updated.' : 'Service added.');
      } catch (error) {
        this.fieldErrors = Object.fromEntries(Object.entries(error.errors || {}).map(([key, values]) => [key, values[0]]));
        this.formError = Object.values(this.fieldErrors).join(' ') || error.message || 'Unable to save service.';
        this.$nextTick(() => this.$refs.formError.focus());
      } finally { this.saving = false; }
    },
    async changeStatus(service) {
      if (this.busyId !== null) return;
      this.busyId = service.serviceId;
      try {
        const response = await API.adminRequest('PATCH', `/admin/grooming/services/${service.serviceId}/status`, {isActive: !service.isActive});
        this.rows = this.rows.map(row => row.serviceId === service.serviceId ? response.data : row);
        this.$store.clinicFeedback.notify(response.data.isActive ? 'Service activated.' : 'Service deactivated.');
      } catch (error) { this.$store.clinicFeedback.notify(error.message || 'Unable to update service status.', 'error'); }
      finally { this.busyId = null; }
    },
    destroy() { this.$refs.editor.close(); },
  };
}
