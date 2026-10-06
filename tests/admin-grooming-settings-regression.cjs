const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const catalogueResponse = JSON.parse(fs.readFileSync(path.join(__dirname, 'grooming-catalogue-fixture.json'), 'utf8').replace(/^\uFEFF/, ''));
const catalogue = catalogueResponse.data;
const source = fs.readFileSync(path.join(__dirname, '../scripts/components/admin-settings.js'), 'utf8');

async function main() {
  let role = 'admin', submitted, release;
  const overlay = {}, background = { inert: false }, alreadyInert = { inert: true };
  const document = { body: { style: { overflow: 'auto', paddingRight: '3px' }, children: [background, alreadyInert, overlay] },
    documentElement: { style: { overflow: 'scroll' }, clientWidth: 1000 }, activeElement: null };
  const controls = Array.from({ length: 4 }, () => ({ offsetParent: {}, focus() { document.activeElement = this; } }));
  let triggerFocuses = 0;
  const trigger = { focus() { triggerFocuses++; } };
  const context = { API: { getUserRole: () => role,
    getGroomingCatalogue: async () => catalogueResponse,
    updateGroomingPricing: async (id, payload) => {
      submitted = { id, payload };
      await new Promise((resolve) => { release = resolve; });
      return { data: { ...catalogue[5], priceOptions: [{ label: 'Standard', sizeKey: '', pricingType: 'fixed', minAmount: 200, maxAmount: 200 }] } };
    } }, window: { innerWidth: 1015, getComputedStyle: () => ({ paddingRight: '3px' }) }, document, setTimeout };
  vm.runInNewContext(source, context);
  const ui = context.adminSettings();
  ui.$nextTick = (callback) => callback();
  ui.$refs = { pricingDialog: { closest: () => overlay, querySelector: () => controls[1],
    querySelectorAll: () => ui.pricingModal.busy ? [] : controls, focus() { document.activeElement = this; } } };
  ui.showStaffActionToast = (message) => { ui.toast = message; };
  await ui.loadGroomingPrices();
  assert.equal(ui.groomingPrices.length, 10);
  assert.equal(ui.groomingPriceLimits, catalogueResponse.priceLimits);
  // One contextual footer action, with semantic dirty tracking for every service.
  const originalUpdate = context.API.updateGroomingPricing;
  let footerSaves = 0;
  context.API.updateGroomingPricing = async (id, payload) => {
    footerSaves++;
    const savedService = catalogue.find(service => service.serviceId === id);
    assert.deepEqual(JSON.parse(JSON.stringify(payload)), savedService.kind === 'package'
      ? { sizes: Object.fromEntries(savedService.defaultPriceOptions.map(option => [option.sizeKey, {
        pricing_type: option.pricingType, amount: String(option.minAmount),
        ...(option.pricingType === 'range' ? { maximum: String(option.maxAmount) } : {}),
      }])) }
      : { pricing_type: savedService.defaultPriceOptions[0].pricingType,
        amount: String(savedService.defaultPriceOptions[0].minAmount),
        ...(savedService.defaultPriceOptions[0].pricingType === 'range'
          ? { maximum: String(savedService.defaultPriceOptions[0].maxAmount) } : {}) });
    return { data: savedService };
  };
  for (const service of catalogue) {
    ui.openPricingModal(service);
    assert.equal(ui.pricingIsDirty, false);
    assert.equal(ui.pricingCanRestoreDefault, false);
    assert.equal(ui.pricingActionDisabled, true, 'Unchanged defaults cannot be saved');
    const originalAmount = ui.pricingModal.rows[0].amount;
    ui.pricingModal.rows[0].amount = Number(originalAmount).toFixed(2);
    assert.equal(ui.pricingIsDirty, false, 'Equivalent currency formatting is not a changed price');
    await ui.saveGroomingPrice();
    assert.equal(footerSaves, service.serviceId - 1, 'Unchanged values do not send a save request');
    ui.closePricingModal();
    const customized = JSON.parse(JSON.stringify(service));
    customized.priceOptions.forEach(option => {
      option.pricingType = 'range'; option.minAmount += 25; option.maxAmount = option.minAmount + 50;
    });
    const savedState = JSON.stringify(customized);
    ui.openPricingModal(customized);
    assert.equal(ui.pricingCanRestoreDefault, true);
    assert.equal(ui.pricingActionDisabled, false);
    const edited = ui.pricingModal.rows[0];
    const originalMaximum = edited.maximum;
    edited.maximum = String(Number(originalMaximum) + 1);
    ui.pricingFieldChanged(edited, 'maximum');
    assert.equal(ui.pricingIsDirty, true, 'A range maximum alone makes the form dirty');
    assert.equal(ui.pricingCanRestoreDefault, false);
    assert.equal(ui.pricingActionDisabled, false);
    edited.maximum = originalMaximum;
    ui.pricingFieldChanged(edited, 'maximum');
    assert.equal(ui.pricingCanRestoreDefault, true, 'Reverting all fields brings back Restore default');
    edited.pricingType = 'fixed';
    assert.equal(ui.pricingIsDirty, true, 'Pricing rules are part of the saved state');
    edited.pricingType = 'range';
    assert.equal(ui.pricingIsDirty, false);
    const originalSize = edited.sizeKey;
    edited.sizeKey = 'unsupported';
    assert.equal(ui.pricingIsDirty, true, 'Available pet sizes are part of the saved state');
    edited.sizeKey = originalSize;
    edited.amount = '0';
    ui.pricingFieldChanged(edited, 'amount');
    assert.equal(ui.pricingActionDisabled, true, 'Invalid dirty forms cannot be saved');
    edited.amount = String(customized.priceOptions[0].minAmount);
    ui.pricingFieldChanged(edited, 'amount');
    for (const close of ['cancel', 'x', 'escape']) {
      await ui.submitPricingAction();
      assert.equal(footerSaves, service.serviceId - 1, 'Restore default does not persist');
      assert.equal(ui.pricingModal.open, true);
      assert.equal(ui.pricingIsDirty, true);
      assert.equal(ui.pricingCanRestoreDefault, false);
      assert.equal(ui.pricingActionDisabled, false);
      assert.equal(ui.pricingModal.rows.length, service.defaultPriceOptions.length);
      ui.pricingModal.rows.forEach((restored, index) => {
        assert.equal(ui.pricingPreview(restored), ui.pricingDisplay(service.defaultPriceOptions[index]));
      });
      if (close === 'escape') ui.pricingModalKeydown({ key: 'Escape', preventDefault() {} });
      else ui.closePricingModal(); // Cancel and X use the same close handler.
      assert.equal(JSON.stringify(customized), savedState, 'Closing discards restored values without changing saved pricing');
      ui.openPricingModal(customized);
      assert.equal(ui.pricingCanRestoreDefault, true);
    }
    await ui.submitPricingAction(); // Stage the defaults.
    await ui.submitPricingAction(); // Explicitly persist them.
    assert.equal(footerSaves, service.serviceId);
    assert.equal(ui.pricingModal.open, false);
    ui.openPricingModal(ui.groomingPrices.find(item => item.id === service.id));
    assert.equal(ui.pricingActionDisabled, true, 'A successful default save reopens with disabled Save changes');
    ui.closePricingModal();
  }
  context.API.updateGroomingPricing = originalUpdate;
  const range = catalogue.find((service) => service.id === 'nail_clipping');
  assert.equal(ui.pricingSizeDisplay(catalogue[0], 'large'), '₱600+');
  assert.equal(ui.pricingSizeDisplay(catalogue.find(service => service.id === 'cat_full_grooming'), 'large'), '—');
  // Revealing Range fields must not show errors until interaction or a save attempt.
  for (const service of catalogue) {
    ui.openPricingModal(service);
    for (const rangeRow of ui.pricingModal.rows) {
      const originalAmount = rangeRow.amount;
      rangeRow.pricingType = 'fixed';
      rangeRow.amount = '0.99';
      ui.changePricingType(rangeRow);
      rangeRow.pricingType = 'range';
      ui.changePricingType(rangeRow);
      assert.equal(rangeRow.maximum, '');
      assert.equal(ui.validatePricing(), false, 'Invalid untouched fields still block saving');
      assert.equal(ui.pricingFieldError(rangeRow, 'amount'), '', 'A newly revealed minimum remains neutral');
      assert.equal(ui.pricingFieldError(rangeRow, 'maximum'), '', 'An empty newly revealed maximum remains neutral');
      assert.equal(ui.pricingPreview(rangeRow), '—');
      ui.pricingFieldChanged(rangeRow, 'amount');
      assert.equal(ui.pricingFieldError(rangeRow, 'amount'), 'Enter a reasonable price amount.');
      assert.equal(ui.pricingFieldError(rangeRow, 'maximum'), '', 'Interacting with the minimum must not touch the maximum');
      rangeRow.amount = originalAmount;
      ui.pricingFieldChanged(rangeRow, 'amount');
      assert.equal(Boolean(ui.pricingFieldError(rangeRow, 'amount')), false);
      ui.pricingFieldChanged(rangeRow, 'maximum');
      assert.equal(ui.pricingFieldError(rangeRow, 'maximum'), 'Enter a reasonable price amount.', 'Leaving an empty field validates it');
      rangeRow.maximum = String(Number(originalAmount) + 100);
      ui.pricingFieldChanged(rangeRow, 'maximum');
      assert.equal(Boolean(ui.pricingFieldError(rangeRow, 'maximum')), false);
      rangeRow.pricingType = 'fixed';
      ui.changePricingType(rangeRow);
      rangeRow.pricingType = 'range';
      ui.changePricingType(rangeRow);
      assert.equal(ui.pricingFieldError(rangeRow, 'maximum'), '', 'Switching back to Range resets the newly revealed field');
      const priorRequest = submitted;
      await ui.saveGroomingPrice();
      assert.equal(submitted, priorRequest, 'Incomplete ranges must never submit a request');
      assert.equal(ui.pricingFieldError(rangeRow, 'maximum'), 'Enter a reasonable price amount.', 'A save attempt exposes required-field errors');
      rangeRow.pricingType = 'fixed';
      ui.changePricingType(rangeRow);
    }
    ui.closePricingModal();
    ui.openPricingModal(service);
    assert.equal(Object.keys(ui.pricingModal.pristineFields).length, 0, 'Field interaction state must reset for a new editor');
    ui.closePricingModal();
  }
  // A different pet-size edit must not expose an untouched Range maximum.
  ui.openPricingModal(catalogue[0]);
  const untouchedRange = ui.pricingModal.rows[0];
  untouchedRange.pricingType = 'range';
  ui.changePricingType(untouchedRange);
  ui.pricingFieldChanged(ui.pricingModal.rows[1], 'amount');
  assert.equal(ui.pricingFieldError(untouchedRange, 'maximum'), '');
  ui.closePricingModal();
  triggerFocuses = 0;
  ui.openPricingModal(range, trigger);
  assert.equal(document.body.style.overflow, 'hidden');
  assert.equal(document.documentElement.style.overflow, 'hidden');
  assert.equal(document.body.style.paddingRight, '18px');
  assert.equal(background.inert, true);
  assert.equal(overlay.inert, undefined, 'The body-level overlay must remain interactive');
  assert.equal(document.activeElement, controls[1], 'Focus the first pricing field on open');
  let prevented = false;
  document.activeElement = controls[0];
  ui.pricingModalKeydown({ key: 'Tab', shiftKey: true, preventDefault() { prevented = true; } });
  assert.equal(prevented, true);
  assert.equal(document.activeElement, controls[3]);
  ui.pricingModalKeydown({ key: 'Tab', shiftKey: false, preventDefault() {} });
  assert.equal(document.activeElement, controls[0]);
  const row = ui.pricingModal.rows[0];
  assert.equal(ui.pricingPreview(row), '₱50–₱100');
  for (const invalid of ['', '0', '-1', '1e3', '50.001', '₱50', '50,000', 'NaN']) {
    row.amount = invalid;
    assert.equal(ui.validatePricing(), false);
    assert.ok(ui.pricingModal.errors.amount);
    assert.equal(row.amount, invalid);
  }
  row.amount = '50';
  for (const maximum of ['', '49', '50']) {
    row.maximum = maximum;
    assert.equal(ui.validatePricing(), false);
    assert.ok(ui.pricingModal.errors.maximum);
  }
  row.pricingType = 'starting_at';
  ui.changePricingType(row);
  assert.equal(row.maximum, '');
  assert.equal(ui.pricingPreview(row), '₱50+');
  assert.equal(ui.validatePricing(), true);
  row.pricingType = 'fixed'; row.amount = '200';
  const saving = ui.saveGroomingPrice();
  assert.equal(ui.pricingModal.busy, true);
  assert.equal(ui.pricingActionDisabled, true, 'The contextual action is disabled during submission');
  ui.closePricingModal();
  assert.equal(ui.pricingModal.open, true);
  assert.equal(document.body.style.overflow, 'hidden', 'Submitting must keep the page locked');
  ui.pricingModalKeydown({ key: 'Tab', preventDefault() {} });
  assert.equal(document.activeElement, ui.$refs.pricingDialog, 'Keep focus in the dialog while all controls are disabled');
  assert.equal(submitted.payload.pricing_type, 'fixed');
  assert.equal('maximum' in submitted.payload, false);
  release(); await saving;
  assert.equal(ui.pricingModal.open, false);
  assert.equal(document.body.style.overflow, 'auto');
  assert.equal(document.body.style.paddingRight, '3px');
  assert.equal(document.documentElement.style.overflow, 'scroll');
  assert.equal(background.inert, false);
  assert.equal(alreadyInert.inert, true, 'Restore the background state without enabling previously inert content');
  assert.equal(triggerFocuses, 1);
  assert.equal(ui.groomingPrices[5].priceOptions[0].minAmount, 200);
  assert.match(ui.toast, /pricing updated/);
  ui.openPricingModal(range);
  row.amount = '65';
  context.API.updateGroomingPricing = async () => { const error = new Error('Save failed'); error.errors = { amount: ['Invalid price.'] }; throw error; };
  ui.pricingModal.rows[0].amount = '65';
  await ui.saveGroomingPrice();
  assert.equal(ui.pricingModal.open, true);
  assert.equal(ui.pricingModal.rows[0].amount, '65');
  assert.equal(ui.pricingModal.errors.amount, 'Invalid price.');
  assert.equal(background.inert, true, 'A failed save keeps background interaction blocked');
  ui.pricingModalKeydown({ key: 'Escape', preventDefault() {} });
  assert.equal(ui.pricingModal.open, false);
  assert.equal(background.inert, false);
  const cat = catalogue.find((service) => service.id === 'cat_full_grooming');
  ui.openPricingModal(cat);
  ui.pricingModal.rows[0].amount = '800';
  assert.equal(ui.validatePricing(), false);
  assert.ok(ui.pricingModal.errors['sizes.medium.amount']);
  ui.pricingModal.rows[0].amount = '500';
  ui.pricingModal.rows[1].pricingType = 'range';
  assert.equal(ui.validatePricing(), false);
  assert.ok(ui.pricingModal.errors['sizes.medium.maximum']);
  const packageRow = ui.pricingModal.rows[1];
  packageRow.maximum = '750';
  assert.equal(ui.validatePricing(), true);
  assert.equal(ui.pricingPreview(packageRow), '₱600–₱750');
  for (const invalid of ['', '0', '-1', '1e3', '600', '599', '750.001', '₱750']) {
    packageRow.maximum = invalid;
    assert.equal(ui.validatePricing(), false);
    assert.ok(ui.pricingModal.errors['sizes.medium.maximum']);
  }
  packageRow.maximum = '750';
  context.API.updateGroomingPricing = async (id, payload) => {
    submitted = { id, payload };
    return { data: { ...cat, priceOptions: cat.priceOptions.map((option) => option.sizeKey === 'medium'
      ? { ...option, pricingType: 'range', maxAmount: 750 } : option) } };
  };
  await ui.saveGroomingPrice();
  assert.equal(submitted.payload.sizes.medium.maximum, '750');
  assert.equal('maximum' in submitted.payload.sizes.small, false);
  assert.equal(ui.pricingModal.open, false);
  assert.equal(ui.pricingSizeDisplay(ui.groomingPrices.find(service => service.id === cat.id), 'medium'), '₱600–₱750');
  ui.openPricingModal(ui.groomingPrices.find(service => service.id === cat.id));
  const reopened = ui.pricingModal.rows[1];
  assert.equal(reopened.maximum, '750');
  reopened.pricingType = 'starting_at';
  ui.changePricingType(reopened);
  assert.equal(reopened.maximum, '');
  assert.equal(ui.pricingPreview(reopened), '₱600+');
  reopened.pricingType = 'other';
  assert.equal(ui.validatePricing(), false);
  assert.ok(ui.pricingModal.errors['sizes.medium.pricing_type']);
  ui.pricingModal.rows.push({ sizeKey: 'large', amount: '900', pricingType: 'fixed' });
  assert.equal(ui.validatePricing(), false);
  assert.ok(ui.pricingModal.errors.sizes);
  for (const service of [catalogue[0], range]) {
    const limits = catalogueResponse.priceLimits[service.kind];
    for (const type of ['fixed', 'starting_at', 'range']) {
      ui.closePricingModal();
      ui.openPricingModal(service);
      ui.pricingModal.rows.forEach(item => { item.pricingType = type; item.amount = String(limits.minimum); item.maximum = String(limits.maximum); });
      const last = ui.pricingModal.rows.at(-1);
      const amountKey = ui.pricingFieldKey(last, 'amount');
      const maximumKey = ui.pricingFieldKey(last, 'maximum');
      assert.equal(ui.validatePricing(), true);
      const invalids = ['', '0', '0.99', '-1', 'NaN', 'Infinity', '1e3', '1E3', '+500', '1.001', '₱500', '1,000', '500\n', String(limits.maximum + 0.01)];
      for (const invalid of invalids) {
        last.amount = invalid;
        assert.equal(ui.validatePricing(), false);
        assert.equal(ui.pricingModal.errors[amountKey], 'Enter a reasonable price amount.');
        assert.equal(ui.pricingPreview(last), '—');
        const previousRequest = submitted;
        await ui.saveGroomingPrice();
        assert.equal(submitted, previousRequest, 'Invalid amounts must never submit a request');
        assert.equal(last.amount, invalid, 'Never clamp invalid input');
      }
      if (type === 'range') {
        last.amount = String(limits.minimum);
        for (const invalid of invalids) {
          last.maximum = invalid;
          assert.equal(ui.validatePricing(), false);
          assert.equal(ui.pricingModal.errors[maximumKey], 'Enter a reasonable price amount.');
          assert.equal(ui.pricingPreview(last), '—');
          assert.equal(last.maximum, invalid);
        }
        for (const upper of ['500', '499']) {
          last.amount = '500'; last.maximum = upper;
          assert.equal(ui.validatePricing(), false);
          assert.equal(ui.pricingModal.errors[maximumKey], 'Maximum price must be greater than minimum price.');
          assert.equal(ui.pricingPreview(last), '—');
        }
        last.amount = String(limits.maximum - 0.01); last.maximum = String(limits.maximum);
        assert.equal(ui.validatePricing(), true);
        assert.equal(ui.pricingPreview(last), `₱${(limits.maximum - 0.01).toLocaleString('en-PH')}–₱${limits.maximum.toLocaleString('en-PH')}`);
      } else {
        last.amount = String(limits.maximum);
        assert.equal(ui.validatePricing(), true);
        assert.equal(ui.pricingPreview(last), `₱${limits.maximum.toLocaleString('en-PH')}${type === 'starting_at' ? '+' : ''}`);
      }
    }
  }
  // A changed server limit must control both validation and the preview without a JS constant.
  ui.openPricingModal(range);
  ui.groomingPriceLimits = { ...catalogueResponse.priceLimits, ala_carte: { minimum: 1, maximum: 300 } };
  ui.pricingModal.rows[0].pricingType = 'fixed';
  ui.pricingModal.rows[0].amount = '300.01';
  assert.equal(ui.validatePricing(), false);
  assert.equal(ui.pricingPreview(ui.pricingModal.rows[0]), '—');
  ui.pricingModal.rows[0].amount = '300';
  assert.equal(ui.validatePricing(), true);
  assert.equal(ui.pricingPreview(ui.pricingModal.rows[0]), '₱300');
  context.API.getGroomingCatalogue = async () => ({ data: catalogue });
  await ui.loadGroomingPrices();
  assert.equal(ui.pricingError, 'Unable to load grooming pricing. Please retry.');
  context.API.getGroomingCatalogue = async () => catalogueResponse;
  await ui.loadGroomingPrices();
  assert.equal(ui.pricingError, '');
  assert.equal(ui.groomingPriceLimits, catalogueResponse.priceLimits);
  ui.destroy();
  assert.equal(background.inert, false);
  assert.equal(document.body.style.overflow, 'auto');
  role = 'staff';
  const staff = context.adminSettings();
  await staff.loadGroomingPrices();
  staff.openPricingModal(cat);
  assert.equal(staff.groomingPrices.length, 0);
  assert.equal(staff.pricingModal.open, false);
  console.log('Admin grooming Settings regression tests passed.');
}
main().catch((error) => { console.error(error); process.exitCode = 1; });
