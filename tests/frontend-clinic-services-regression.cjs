const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const read = name => fs.readFileSync(path.join(__dirname, '..', name), 'utf8');

function setup() {
  const calls = [], panels = [], listeners = new Map();
  let failMarkup = false;
  const context = {
    URLSearchParams, clearTimeout, setTimeout, console,
    location: {hash: '#services'}, history: {replaceState(_, __, hash) {context.location.hash = hash;}},
    scrollTo() {}, addEventListener: (name, listener) => listeners.set(name, listener),
    removeEventListener: name => listeners.delete(name),
    adminClinicPage: () => ({tab: 'active-cases', init() {calls.push('cases:init');}, loadAllRecords() {calls.push('records:load');}}),
    API: {getUserRole: () => 'admin', adminRequest: async (method, url, payload) => {
      calls.push([method, url, payload]);
      return url.endsWith('/options') ? {categories: [{id: 2, name: 'Laboratory'}], types: ['In House']}
        : method !== 'GET' ? {data: {type: payload?.type}}
        : {data: [], total: 0, current_page: 1, last_page: 1};
    }},
    fetch: async () => {calls.push('services:markup'); return {ok: !failMarkup, text: async () => ''};},
    document: {title: '', head: {appendChild(script) {
      calls.push('services:script');
      vm.runInContext(read('scripts/components/admin-clinic-services.js'), context);
      queueMicrotask(script.onload);
    }}, createElement: () => ({style: {}, setAttribute() {}, remove() {}})},
    Alpine: {mutateDom: fn => fn(), initTree(panel) {panel.state = component(context);}, $data: panel => panel.state},
  };
  context.window = context;
  vm.createContext(context);
  vm.runInContext(read('scripts/components/admin-clinic-shell.js'), context);
  const shell = context.adminClinicShell();
  const cases = {state: context.adminClinicCasesSection()};
  shell.$refs = {cases: {firstElementChild: cases}, services: {get firstElementChild() {return panels[0];}, appendChild: panel => panels.push(panel)}};
  shell.$nextTick = fn => fn();
  return {context, shell, calls, panels, listeners, failMarkup: value => {failMarkup = value;},
    visit: async hash => {context.location.hash = hash; await shell.showSection();}};
}

function component(context) {
  const state = context.adminClinicServices();
  const dialog = () => ({open: false, showModal() {this.open = true;}, close() {this.open = false;}});
  state.$refs = {serviceDialog: dialog(), serviceForm: {querySelector: () => null, reportValidity: () => true}, detailsDialog: dialog(), detailsClose: {focus() {}}, serviceName: {focus() {}}, formError: {focus() {}}};
  state.$nextTick = fn => fn();
  state.$store = {clinicFeedback: {notify() {}, confirm: async () => true}};
  return state;
}

async function navigation() {
  const app = setup();
  await app.shell.init();
  // init's nextTick callback is intentionally asynchronous, as in Alpine.
  await app.shell.showSection();
  assert.equal(app.calls.includes('cases:init'), false, 'Direct Services visits must not initialize Cases');
  assert.equal(app.panels.length, 1, 'Concurrent activation mounts only one partial');
  assert.equal(app.calls.filter(call => Array.isArray(call) && call[1].startsWith('/admin/clinic-services?')).length, 1, 'Concurrent activation fetches the catalog once');
  const services = app.panels[0].state;
  const beforeReturn = app.calls.length;
  services.openDetails({id: 1, name: 'CBC'}, {currentTarget: {focus() {}}});
  services.q = 'CBC';
  await app.visit('#cases');
  assert.equal(services.$refs.detailsDialog.open, false, 'Leaving Services closes its details dialog');
  await app.visit('#services');
  await app.visit('#cases');
  assert.equal(app.calls.filter(call => call === 'cases:init').length, 1);
  assert.equal(app.calls.length, beforeReturn + 1, 'Cached navigation runs only the first Cases initialization');
  assert.equal(app.panels[0].state, services);
  assert.equal(services.q, 'CBC', 'Cached section preserves filters');
  await app.visit('#unknown');
  assert.equal(app.context.location.hash, '#cases');
  await app.visit('#records');
  assert.equal(app.shell.activeSection, 'cases');
  assert.equal(app.context.location.hash, '#cases', 'Legacy Records links normalize to Cases');
  assert.equal(app.context.document.title, 'Clinic Cases | Bethlehem Animal Clinic');
  await app.visit('#cases');
  await app.visit('#records');
  assert.equal(app.calls.filter(call => call === 'records:load').length, 0, 'The shell no longer has a separate Records activation');
  assert.equal(app.calls.filter(call => call === 'cases:init').length, 1, 'Records reuse the Clinic workspace');
  app.shell.destroy();
  assert.equal(app.listeners.has('hashchange'), false);
  const retry = setup();
  retry.failMarkup(true);
  await retry.shell.showSection();
  assert.match(retry.shell.sectionError, /Unable to load/);
  retry.failMarkup(false);
  await retry.shell.showSection();
  assert.equal(retry.shell.sectionError, '');
  assert.equal(retry.panels.length, 1);
}

async function workflow() {
  const app = setup();
  await app.shell.showSection();
  const state = app.panels[0].state;
  assert.equal(state.status, 'active');
  state.q = 'CBC'; state.category = '2'; state.type = 'Send Out'; state.status = 'inactive';
  await state.load(2);
  assert.equal(state.filtersActive, true);
  assert.ok(app.calls.some(call => Array.isArray(call) && /q=CBC.*category_id=2.*type=Send\+Out/.test(call[1])));
  await state.clearFilters();
  assert.equal(state.filtersActive, false);
  state.type = '__none__';
  await state.load();
  assert.equal(state.filtersActive, true, 'Not Applicable is an active filter');
  const notApplicableQuery = new URLSearchParams(app.calls.at(-1)[1].split('?')[1]);
  assert.equal(notApplicableQuery.get('type'), '__none__', 'Not Applicable requests only services with an empty Type');
  assert.equal(notApplicableQuery.get('page'), '1', 'Changing the Type filter starts on page one');
  await state.clearFilters();
  const allTypesQuery = new URLSearchParams(app.calls.at(-1)[1].split('?')[1]);
  assert.equal(state.type, '');
  assert.equal(allTypesQuery.has('type'), false, 'All types removes the Type restriction');
  state.openModal();
  assert.equal('is_active' in state.form, false, 'Status is not part of the service form');
  assert.equal('is_active' in state.payload(), false, 'Creation uses the active database default');
  assert.equal(state.form.type, '', 'New services default to Not Applicable');
  assert.equal(state.payload().type, null, 'Not Applicable saves an empty Type');
  state.openModal({id: 1, name: 'CBC', type: 'Send Out'});
  assert.equal(state.form.type, 'Send Out', 'Editing preselects the saved Type');
  state.form.type = '';
  assert.equal(state.payload().type, null, 'Editing can clear a previously selected Type');
  state.openModal({id: 2, name: 'Consultation', type: null});
  assert.equal(state.form.type, '', 'An empty saved Type preselects Not Applicable');
  const inactive = {id: 3, name: 'Boarding', is_active: false, pricing_method: 'tiered', tiers: [{size: 'small', price: 500}]};
  let focused = false;
  const beforeDetails = app.calls.length;
  state.openDetails(inactive, {currentTarget: {focus() {focused = true;}}});
  assert.equal(state.details, inactive, 'Details use the full catalog record already loaded');
  assert.equal(state.$refs.detailsDialog.open, true);
  state.closeDetails();
  assert.equal(state.$refs.detailsDialog.open, false);
  assert.equal(state.details, null);
  assert.equal(focused, true, 'Closing details restores focus to the triggering row');
  assert.equal(app.calls.length, beforeDetails, 'Viewing details does not make another network request');
  state.openModal(inactive);
  assert.equal('is_active' in state.form, false);
  assert.equal('is_active' in state.payload(), false, 'Editing cannot overwrite the saved status');
  state.form.tiers[0].price = 600;
  assert.equal(inactive.tiers[0].price, 500, 'Editing tiers does not mutate catalog details before saving');
  state.openModal(); state.form.name = 'Emergency Fee'; state.form.category_id = 1;
  state.form.pricing_method = 'range'; state.form.min_price = 500; state.form.max_price = 499;
  const before = app.calls.length;
  await state.save();
  assert.equal(app.calls.length, before);
  assert.match(state.priceErrors.max_price, /Maximum Price/);
  state.form.pricing_method = 'tiered'; state.form.tiers.forEach(tier => {tier.enabled = false;});
  await state.save(); assert.match(state.formError, /at least one/);
  state.form.tiers[0].enabled = true; state.form.tiers[0].price = -1;
  await state.save(); assert.match(state.priceErrors.small, /non-negative/);
  state.form.tiers[0].price = 0;
  await state.save();
  const payload = app.calls.find(call => Array.isArray(call) && call[0] === 'POST')[2];
  assert.equal(payload.tiers.length, 1);
  assert.equal(payload.price, undefined);
  await state.changeStatus({id: 1, name: 'CBC', is_active: true});
  assert.ok(app.calls.some(call => Array.isArray(call) && call[0] === 'PATCH' && call[2].is_active === false));
  await state.changeStatus({id: 1, name: 'CBC', is_active: false});
  assert.ok(app.calls.some(call => Array.isArray(call) && call[0] === 'PATCH' && call[2].is_active === true));

  let resolveOld;
  app.context.API.adminRequest = async (_, url) => url.includes('q=old')
    ? new Promise(resolve => {resolveOld = resolve;})
    : {data: [{name: 'New'}], total: 1, current_page: 1, last_page: 1};
  state.q = 'old'; const old = state.load();
  state.q = 'new'; await state.load();
  resolveOld({data: [{name: 'Old'}], total: 1, current_page: 1, last_page: 1});
  await old;
  assert.equal(state.rows[0].name, 'New', 'Late search responses cannot overwrite newer results');
  app.context.API.adminRequest = async () => {throw new Error('Offline');};
  await state.load(); assert.equal(state.error, 'Offline'); assert.equal(state.loading, false);
}

function formatting() {
  const markup = read('pages/admin/clinic/sections/services.html');
  assert.match(markup, /<select x-model="form\.type"/);
  assert.match(markup, /<option value="">Not Applicable<\/option>/);
  assert.match(markup, /<option value="__none__">Not Applicable<\/option>/);
  assert.doesNotMatch(markup, /<input[^>]*x-model="form\.type"|<datalist/);
  assert.doesNotMatch(markup, /x-model="form\.is_active"/);
  for (const dialog of markup.matchAll(/<dialog\b[^>]*>/g)) {
    assert.ok(dialog[0].includes('@cancel.prevent @pointerdown.self.prevent'), 'Outside pointer events and Escape must not dismiss or blur');
    assert.ok(!dialog[0].includes('@click='), 'No outside-click dismissal handler');
  }
  for (const model of ['form.price', 'form.min_price', 'form.max_price', 'tier.price']) {
    const tag = [...markup.matchAll(/<input\b[^>]*>/g)].map(match => match[0]).find(tag => tag.includes(`x-model="${model}"`));
    assert.ok(tag.includes('data-money-input') && tag.includes('inputmode="decimal"'), model);
    assert.ok(tag.includes('@blur="validatePrice(') && tag.includes('@input="priceInput('), model);
    assert.ok(tag.includes(':aria-invalid=') && tag.includes('aria-describedby='), model);
  }
  assert.match(markup, /@click\.stop="openModal\(service\)"/);
  assert.match(markup, /@click\.stop="changeStatus\(service\)"/);
  assert.match(markup, /@keydown\.enter\.self\.prevent="openDetails/);
  assert.match(markup, /@keydown\.space\.self\.prevent="openDetails/);
  assert.ok(markup.includes("service.type || '—'"), 'Empty Type displays an em dash in the table');
  const context = {setTimeout, clearTimeout};
  vm.createContext(context);
  vm.runInContext(read('scripts/components/admin-clinic-services.js'), context);
  const format = context.clinicServicePricing;
  assert.equal(format({pricing_method: 'fixed', price: 990}), '₱990');
  assert.equal(format({pricing_method: 'range', min_price: 500, max_price: 1500}), '₱500–₱1,500');
  assert.equal(format({pricing_method: 'starting_at', price: 500, billing_unit: 'day'}), 'From ₱500 / day');
  assert.equal(format({pricing_method: 'fixed', price: 150, billing_unit: 'sample'}), '₱150 / sample');
  assert.equal(format({pricing_method: 'tiered', tiers: [{price: 800}, {price: 1050}]}), '₱800–₱1,050 by size');
}

async function priceValidation() {
  const app = setup();
  await app.shell.showSection();
  const state = app.panels[0].state;
  const maximumError = 'Price must be ₱100,000.00 or less.';
  for (const editing of [false, true]) {
    for (const method of ['fixed', 'starting_at', 'range', 'tiered']) {
      state.openModal(editing ? {id: 1, name: 'CBC'} : null);
      Object.assign(state.form, {name: 'CBC', category_id: 2, pricing_method: method, price: '100000.00', min_price: '100000.00', max_price: '100000.00'});
      state.form.tiers.forEach(tier => {tier.enabled = true; tier.price = '100000.00';});
      for (const field of state.priceFields()) {
        const target = method === 'tiered' ? state.form.tiers.find(tier => tier.size === field.key) : state.form;
        const property = method === 'tiered' ? 'price' : field.key;
        for (const invalid of ['100000.01', '1000000']) {
          state.priceErrors = {};
          target[property] = invalid;
          state.priceInput(field.key);
          assert.equal(state.priceErrors[field.key], undefined, 'Typing does not show an initial error');
          state.validatePrice(field.key);
          assert.equal(state.priceErrors[field.key], maximumError);
          assert.equal(target[property], invalid, 'Blur preserves the original value');
          state.priceErrors = {};
          const before = app.calls.length;
          await state.save();
          assert.equal(app.calls.length, before, 'Save blocks every over-limit pricing field');
          assert.equal(state.priceErrors[field.key], maximumError);
          assert.equal(target[property], invalid, 'Save never clamps');
          target[property] = '100000.00';
          state.priceInput(field.key);
          assert.equal(state.priceErrors[field.key], '', 'Correcting to the exact limit clears the inline error');
        }
      }
      const before = app.calls.length;
      await state.save();
      assert.ok(app.calls.slice(before).some(call => call[0] === (editing ? 'PUT' : 'POST')), 'The exact limit saves for every method');
    }
  }
  state.openModal();
  for (const invalid of ['-0.01', '1.001', '100.000', '', 'abc']) {
    state.form.price = invalid;
    const before = app.calls.length;
    await state.save();
    assert.ok(state.priceErrors.price);
    assert.equal(app.calls.length, before);
  }
  state.form.price = '0';
  assert.equal(state.validatePrice('price'), true, 'Zero remains valid');
  state.form.pricing_method = 'range';
  state.form.min_price = '500'; state.form.max_price = '499';
  state.validatePrice('max_price');
  assert.match(state.priceErrors.max_price, /Maximum Price/);
  state.form.min_price = '499'; state.priceInput('min_price');
  assert.equal(state.priceErrors.max_price, '', 'Correcting the minimum clears the dependent range error');
  state.openModal();
  assert.equal(Object.keys(state.priceErrors).length, 0, 'Reopening clears prior errors');
  Object.assign(state.form, {name: 'CBC', category_id: 2, pricing_method: 'tiered', price: '1000000'});
  state.form.tiers.forEach(tier => {tier.enabled = false; tier.price = '1000000';});
  state.form.tiers[1].enabled = true;
  state.form.tiers[1].price = '500.00';
  const before = app.calls.length;
  await state.save();
  assert.ok(app.calls.slice(before).some(call => call[0] === 'POST'), 'Unused prices and disabled tiers do not block saving');
}

async function confirmationDismissal() {
  const stores = {};
  const context = {document: {addEventListener: (_, callback) => callback()}, Alpine: {store: (name, store) => {stores[name] = store;}}};
  vm.createContext(context);
  vm.runInContext(read('scripts/components/admin-clinic.js'), context);
  const feedback = stores.clinicFeedback;
  let answer = feedback.confirm({title: 'Service status', dismissOnEscape: false});
  assert.equal(feedback.dialog.dismissOnEscape, false);
  feedback.answer(false);
  assert.equal(await answer, false, 'Cancel still completes confirmation');
  answer = feedback.confirm({title: 'Case action'});
  assert.equal(feedback.dialog.dismissOnEscape, true, 'Other clinic confirmations retain their existing Escape behavior');
  feedback.answer(true);
  assert.equal(await answer, true);
}

(async () => {await navigation(); await workflow(); await priceValidation(); await confirmationDismissal(); formatting(); console.log('Clinic Services navigation, pricing and workflow regressions passed');})().catch(error => {console.error(error); process.exitCode = 1;});
