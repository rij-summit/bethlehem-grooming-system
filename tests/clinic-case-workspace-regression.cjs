const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {webcrypto} = require('node:crypto');
const root = path.resolve(__dirname, '..');
const page = fs.readFileSync(path.join(root, 'pages/admin/clinic.html'), 'utf8');
const source = fs.readFileSync(path.join(root, 'scripts/components/admin-clinic-case-workspace.js'), 'utf8');
const fixed = {id: 1, name: 'Consultation', pricing_method: 'fixed', price: '500.00', billing_unit: 'visit'};
const range = {id: 2, name: 'X-Ray', pricing_method: 'range', min_price: '800.00', max_price: '1500.00'};
const starting = {id: 3, name: 'Procedure', pricing_method: 'starting_at', price: '1000.00'};
const tiered = {id: 4, name: 'Lab test', pricing_method: 'tiered'};
const services = [fixed, range, starting, tiered];
const storage = new Map();
const submissions = [], events = [];
let role = 'staff', permission = {clinical: true, billing: true, payment: false};
let failSubmission = false, delayedOptions = null;
const preview = service => ({requires_final_price: ['range', 'starting_at'].includes(service.pricing_method),
  min_price: service.min_price || service.price, max_price: service.max_price || null, technical_max_price: 100000,
  resolved_price: service.pricing_method === 'tiered' ? '600.00' : service.price, pet_size: 'medium', pet_weight_kg: '18.40', error: null});
const sandbox = {
  console, setTimeout, clearTimeout, crypto: webcrypto,
  sessionStorage: {setItem: (key, value) => storage.set(key, value), getItem: key => storage.get(key) || null, removeItem: key => storage.delete(key)},
  CustomEvent: class {constructor(type, options) {this.type = type; this.detail = options?.detail;}},
  window: {dispatchEvent: event => events.push(event)},
  API: {
    getUserRole: () => role,
    getClinicCaseServices: async () => ({data: [], current_page: 1, last_page: 1}),
    getClinicCaseCharges: async () => ({total_amount: '1734.00', charges: {data: [
      {id: 1, description: 'Performed consultation', clinic_case_service_id: 1, quantity: 1, unit_price: 500, amount: 500},
      {id: 2, description: 'Medicine', kind: 'medicine', quantity: 1, unit_price: 1234, amount: 1234},
    ], current_page: 1, last_page: 1}}),
    clinicCaseServiceOptions: async () => delayedOptions || ({data: services, current_page: 1, last_page: 1}),
    clinicCaseServicePricing: async (id, serviceId) => {const service = services.find(row => row.id === serviceId); return {data: service, pricing: preview(service)};},
    clinicCreateCaseService: async (id, payload) => {submissions.push({id, payload: {...payload}}); if (failSubmission) throw new Error('timeout'); return {data: {id: 5}};},
    clinicUpdateCaseService: async (id, occurrenceId, payload) => {submissions.push({id, occurrenceId, payload: {...payload}}); return {data: {id: occurrenceId}};},
  },
};
vm.createContext(sandbox);
vm.runInContext(source, sandbox);
function component(id = 1) {
  const state = sandbox.clinicCaseWorkspace();
  state.$store = {clinicAccess: {permissions: permission, user: {user_id: 7}}, clinicFeedback: {notify() {}, confirm: async () => true}};
  state.$refs = {}; state.$nextTick = callback => callback();
  state.syncWorkspace(true, {id, status: 'in_consultation', vitals: {weight_kg: 18.4}, pet: {size: 'small', weight: 1}}, 'services');
  return state;
}
const settle = () => new Promise(resolve => setImmediate(resolve));

(async () => {
  const state = component();
  await state.selectService(fixed);
  assert.equal(state.needsFinalPrice, false);
  assert.equal(state.servicePricing.resolved_price, '500.00');
  state.serviceForm.applied_price = '400';
  await state.submitService();
  assert.equal('applied_price' in submissions.at(-1).payload, false, 'Fixed does not send a manual price');
  assert.equal(state.serviceForm, null);

  await state.selectService(range);
  assert.equal(state.needsFinalPrice, true);
  assert.match(state.priceRule(), /800\.00.*1,500\.00/);
  for (const invalid of ['', '799.99', '1500.01', '-1', '1e3', '800.001']) {
    state.serviceForm.applied_price = invalid;
    const before = submissions.length;
    await state.submitService();
    assert.equal(submissions.length, before);
    assert.ok(state.serviceErrors.applied_price);
    assert.equal(state.serviceForm.applied_price, invalid, 'Validation retains entered values');
  }
  for (const valid of ['800', '1250', '1500']) {state.serviceForm.applied_price = valid; assert.equal(state.validateService(), true);}
  state.serviceForm.status = 'ordered';
  assert.equal(state.needsFinalPrice, false);
  await state.submitService();
  assert.equal(submissions.at(-1).payload.status, 'ordered');
  assert.equal('applied_price' in submissions.at(-1).payload, false);

  await state.selectService(starting);
  assert.equal(state.needsFinalPrice, true);
  assert.match(state.priceRule(), /Starting at.*1,000\.00/);
  state.serviceForm.applied_price = '999.99';
  assert.equal(state.validateService(), false);
  assert.match(state.serviceErrors.applied_price, /at least/);
  state.serviceForm.applied_price = '1800';
  assert.equal(state.validateService(), true, 'No Grooming +500 limit');
  await state.submitService();
  assert.equal(submissions.at(-1).payload.applied_price, '1800');

  await state.selectService(tiered);
  assert.equal(state.needsFinalPrice, false);
  assert.equal(state.servicePricing.pet_weight_kg, '18.40');
  assert.equal(state.servicePricing.pet_size, 'medium');
  state.servicePricing.error = "Record the pet's weight in Vitals before performing this service.";
  const beforeTiered = submissions.length;
  await state.submitService();
  assert.equal(submissions.length, beforeTiered);
  state.serviceForm.status = 'ordered';
  await state.submitService();
  assert.equal('applied_price' in submissions.at(-1).payload, false, 'Tiered has no client price');

  await state.selectService(range, {id: 22, request_key: 'existing-key', quantity: '2.00', notes: 'Ordered earlier'});
  state.serviceForm.applied_price = '850';
  await state.submitService();
  assert.equal(submissions.at(-1).occurrenceId, 22);
  assert.equal(submissions.at(-1).payload.quantity, '2.00');
  assert.equal('request_key' in submissions.at(-1).payload, false, 'Ordered transitions use the existing occurrence');

  await state.selectService(fixed);
  const key = state.serviceForm.request_key;
  failSubmission = true;
  await state.submitService();
  assert.ok(state.pendingPayload);
  assert.ok(storage.size);
  state.dismissService();
  assert.equal(state.serviceForm.request_key, key, 'Unconfirmed requests cannot be discarded and recreated');
  const reloaded = component();
  await settle();
  assert.equal(reloaded.serviceForm.request_key, key, 'Reload restores the UUID');
  assert.equal(reloaded.pendingPayload.request_key, key);
  failSubmission = false;
  await reloaded.submitService();
  assert.equal(submissions.at(-1).payload.request_key, submissions.at(-2).payload.request_key);
  assert.equal(storage.size, 0, 'Confirmed success clears the retry draft');

  const historical = {service_snapshot: {name: 'Original name', pet_size: 'medium', pet_weight_kg: '18.40'}, clinic_service: {name: 'Renamed service'}};
  assert.equal(state.serviceInfo(historical).name, 'Original name');
  await state.loadBilling();
  assert.equal(state.billingTotal, '1734.00', 'Uses server total instead of calculating another total');
  assert.equal(state.chargeRows[1].kind, 'medicine');
  state.requestCasePayment();
  assert.equal(events.some(event => event.type === 'clinic-review-payment'), false, 'Vet cannot open payment');
  permission = {clinical: false, billing: true, payment: true};
  const receptionist = component(2);
  assert.equal(receptionist.canMutateServices, false);
  const beforeReceptionist = submissions.length;
  await receptionist.selectService(fixed);
  await receptionist.submitService();
  await receptionist.cancelOrderedService({status: 'ordered'});
  assert.equal(submissions.length, beforeReceptionist);
  receptionist.workspaceAppointment = {id: 2, status: 'for_payment', charges: []};
  await receptionist.loadBilling();
  await receptionist.requestCasePayment();
  assert.equal(events.at(-1).type, 'clinic-review-payment');
  assert.equal(events.at(-1).detail.total_amount, '1734.00');
  assert.equal(events.at(-1).detail.charges.length, 2, 'Payment receives the reviewed charges instead of a stale case array');
  receptionist.chargeLastPage = 2;
  sandbox.API.getClinicCaseCharges = async (id, page) => ({total_amount: '2000.00', charges: {
    data: [{id: 3, description: 'Additional treatment', amount: '266.00'}], current_page: page, last_page: 2,
  }});
  await receptionist.requestCasePayment();
  assert.equal(events.at(-1).detail.charges.length, 3, 'Remaining pages are included in payment review');
  assert.equal(events.at(-1).detail.total_amount, '2000.00');
  permission = {clinical: true, billing: true, payment: true}; role = 'admin';
  const admin = component(3);
  for (const locked of [{status: 'completed'}, {status: 'in_consultation', paid: true}, {status: 'in_consultation', archived_at: '2026-10-13'}]) {
    admin.workspaceAppointment = {id: 3, ...locked};
    assert.equal(admin.canMutateServices, false);
  }

  permission = {clinical: true, billing: true, payment: false}; role = 'staff';
  const stale = component(4);
  let resolveOptions;
  delayedOptions = new Promise(resolve => {resolveOptions = resolve;});
  const pending = stale.loadOptions();
  stale.syncWorkspace(false);
  resolveOptions({data: [fixed], current_page: 1, last_page: 1});
  await pending;
  assert.equal(stale.searchRows.length, 0, 'Closed case ignores an in-flight catalogue response');

  const billingMarkup = page.slice(page.indexOf('<section id="case-billing-panel"'), page.indexOf('<!-- Vaccination section'));
  assert.equal(/<input|<select|Edit Price|Discount/.test(billingMarkup), false, 'Billing has no pricing editor');
  assert.match(page, /<template x-if="needsFinalPrice">/);
  assert.match(page, /x-show="selectedService\?\.pricing_method === 'tiered'/);
  assert.match(page, /Saved case weight/);
  assert.match(page, /Pricing tier/);
  assert.match(page, /Go to Vitals/);
  assert.match(page, /x-show="canRecordCasePayment"/);
  assert.match(page, /x-show="\$store\.clinicAccess\.permissions\.billing"/);
  assert.match(source, /service_snapshot \|\| entry.clinic_service/);
  assert.equal(/sizeFor|groomingSize|\+ 500|\+ 200/.test(source), false, 'JS does not recreate size or pricing rules');
  console.log('Clinic case workspace pricing, history, retry, billing and permission regressions passed');
})().catch(error => {console.error(error); process.exitCode = 1;});
