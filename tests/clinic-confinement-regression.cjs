const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {webcrypto} = require('node:crypto');
const root = path.resolve(__dirname, '..');
const storage = new Map(), requests = [], events = [];
let episode = null, timeout = false, variable = false;
const service = {id: 10, name: 'Inpatient care', pricing_method: 'fixed', price: '500.00', billing_unit: 'day'};
const pricing = () => ({requires_final_price: variable, min_price: 500, max_price: 900, resolved_price: variable ? null : '500.00', error: null});
const sandbox = {console, crypto: webcrypto, Date, setTimeout, clearTimeout, document: {addEventListener() {}},
  sessionStorage: {setItem: (k, v) => storage.set(k, v), getItem: k => storage.get(k), removeItem: k => storage.delete(k)},
  CustomEvent: class {constructor(type, options) {this.type = type; this.detail = options?.detail;}},
  window: {dispatchEvent: e => events.push(e)},
  API: {
    adminRequest: async (method, url, payload) => {
      if (method === 'GET') return {data: service, pricing: pricing()};
      requests.push({kind: 'extra', method, url, payload: {...payload}});
      if (timeout) throw Error('timeout');
      return {data: {}};
    },
    getClinicConfinement: async () => ({data: episode}),
    clinicConfinementOptions: async () => ({data: [service], total: 1, current_page: 1, last_page: 1}),
    clinicCaseServicePricing: async () => ({data: service, pricing: pricing()}),
    clinicAdmitConfinement: async (id, payload) => {requests.push({id, kind: 'admission', payload: {...payload}});
      if (timeout) throw Error('timeout');
      episode = {id: 5, admitted_at: '2026-10-13T10:00:00+08:00', discharged_at: null, case_service: {clinic_service: service}};
      return {data: episode};},
    getClinicConfinementObservations: async () => ({data: [], current_page: 1, last_page: 1}),
    clinicRecordConfinementObservation: async (id, payload) => {requests.push({id, kind: 'monitoring', payload: {...payload}}); if (timeout) throw Error('timeout'); return {data: {}};},
    clinicConfinementDischargePreview: async () => ({service: {...service, pricing_method: variable ? 'range' : 'fixed'}, pricing: pricing(), quantity: 2}),
    clinicDischargeConfinement: async (id, payload) => {requests.push({id, kind: 'discharge', payload: {...payload}});
      if (timeout) throw Error('timeout'); episode = {...episode, discharged_at: '2026-10-14T10:01:00+08:00'}; return {data: episode};},
  },
};
vm.createContext(sandbox);
for (const file of ['admin-clinic.js', 'admin-clinic-case-workspace.js', 'admin-clinic-confinement.js'])
  vm.runInContext(fs.readFileSync(path.join(root, 'scripts/components', file), 'utf8'), sandbox);
function component(permission = true, id = 1) {
  const state = sandbox.clinicConfinementWorkspace();
  state.$store = {clinicAccess: {permissions: {clinical: permission, billing: true}, user: {user_id: 7}}, clinicFeedback: {notify() {}}};
  state.selectSection = section => {state.section = section;};
  state.confinementCase = {id, status: 'in_consultation', paid: false, pet: {name: 'Mochi'}}; state.confinementCaseId = id;
  return state;
}
(async () => {
  const state = component();
  await state.openAdmission(); assert.equal(state.selectedService.id, 10, 'Base is resolved by server'); assert.equal('clinic_service_id' in state.admissionForm, false);
  state.admissionForm.reason = 'Observe'; timeout = true;
  await state.submitAdmission(); const original = JSON.stringify(requests.at(-1).payload);
  assert.ok(state.admissionPending); assert.equal(state.busy, false);
  const reload = component(); await reload.loadConfinement(); assert.equal(reload.admissionForm.reason, 'Observe');
  timeout = false; await reload.submitAdmission(); assert.equal(JSON.stringify(requests.at(-1).payload), original);
  assert.equal(reload.activeConfinement, true); assert.equal(storage.has(reload.confinementStoragePrefix + 'admission'), false);
  assert.ok(events.some(e => e.type === 'clinic-case-patch' && e.detail.confinement));

  reload.openMonitoring(); const count = requests.length; await reload.submitMonitoring(); assert.equal(requests.length, count, 'Empty monitoring is blocked');
  reload.monitoringForm.notes = 'Alert'; timeout = true; await reload.submitMonitoring();
  const monitoring = JSON.stringify(requests.at(-1).payload); timeout = false; await reload.submitMonitoring();
  assert.equal(JSON.stringify(requests.at(-1).payload), monitoring); assert.equal(reload.monitoringOpen, false);
  assert.equal('recorded_at' in requests.at(-1).payload, false, 'Monitoring time is server-owned');

  await reload.openExtra(); reload.extraForm.clinic_service_id = 10; await reload.previewExtra();
  timeout = true; await reload.submitExtra();
  const extraPayload = JSON.stringify(requests.at(-1).payload);
  assert.ok(reload.extraPending); assert.equal('quantity' in requests.at(-1).payload, false);
  assert.equal('applied_price' in requests.at(-1).payload, false, 'Daily start has no final-price input');
  timeout = false; await reload.submitExtra(); assert.equal(JSON.stringify(requests.at(-1).payload), extraPayload);
  assert.equal(reload.extraPending, null);
  const running = {id: 11, started_at: '2026-10-13T10:00:00Z', case_service: {clinic_service: service}, pricing: pricing()};
  await reload.openExtra(running);
  assert.match(requests.at(-1).url, /charges\/11\/stop$/);
  assert.equal('stopped_at' in requests.at(-1).payload, false, 'Stop time is server-owned');
  assert.equal(reload.extraForm, null, 'Fixed daily charges stop without an extra confirmation form');
  assert.equal(reload.billableDays('2026-10-13T00:00:00Z', '2026-10-14T00:01:00Z'), 2);

  await reload.openDischarge(); assert.equal(reload.dischargePreview.quantity, 2);
  reload.dischargeForm.applied_price = '1'; timeout = true; await reload.submitDischarge();
  assert.equal('quantity' in requests.at(-1).payload, false); assert.equal('applied_price' in requests.at(-1).payload, false, 'Fixed never sends a manual price');
  const discharge = JSON.stringify(requests.at(-1).payload); timeout = false; await reload.submitDischarge();
  assert.equal(JSON.stringify(requests.at(-1).payload), discharge); assert.equal(reload.section, 'billing');
  assert.equal(reload.confinementCase.status, 'for_payment'); assert.equal(reload.activeConfinement, false);

  episode = {...episode, discharged_at: null}; variable = true;
  const range = component(true, 2); range.episode = episode; await range.openDischarge();
  for (const value of ['', '499.99', '900.01', '500.001', '1e3']) {
    range.dischargeForm.applied_price = value; const before = requests.length; await range.submitDischarge();
    assert.equal(requests.length, before); assert.ok(range.priceError); assert.equal(range.dischargeForm.applied_price, value);
  }
  range.dischargeForm.applied_price = '700'; await range.submitDischarge(); assert.equal(requests.at(-1).payload.applied_price, '700');

  const reception = component(false); reception.episode = {...episode, discharged_at: null};
  const before = requests.length; await reception.openAdmission(); reception.openMonitoring(); await reception.openDischarge();
  assert.equal(requests.length, before); assert.equal(reception.monitoringForm, null); assert.equal(reception.dischargeForm, null);
  assert.equal(reception.canCareForConfinement, false);
  const paid = component(); paid.confinementCase.paid = true; assert.equal(paid.canAdmitConfinement, false);
  const modal = sandbox.adminClinicModal(); modal.$store = state.$store;
  modal.currentAppt = {id: 1, status: 'in_consultation', confinement: {...episode, discharged_at: null}};
  assert.equal(modal.canFinish, false, 'Ordinary completion is hidden during active confinement');
  modal.currentAppt.confinement.discharged_at = '2026-10-14T10:01:00Z'; assert.equal(modal.canFinish, true);
  sandbox.API.getClinicConfinedCases = async () => ({data: [{id: 1, confinement: episode}], total: 23, current_page: 2, last_page: 2});
  const pageState = sandbox.adminClinicPage(); pageState.$store = state.$store;
  await pageState.loadConfinedCases(2); assert.equal(pageState.confinedCount, 23); assert.equal(pageState.confinedPage, 2);
  assert.equal(pageState.confinedCases.length, 1, 'Count comes from the server, not loaded page length');
  assert.equal(sandbox.clinicConfinementDuration('2026-10-13T00:00:00Z', '2026-10-14T01:02:00Z'), '1d 1h 2m');
  assert.equal(state.methodLabel('fixed'), 'Fixed', 'Shared formatting used in Confinement remains available');
  assert.equal(state.sizeLabel('medium'), 'Medium');
  assert.equal(state.cataloguePrice({pricing_method: 'fixed', price: '500.00'}), state.money(500));
  const page = fs.readFileSync(path.join(root, 'pages/admin/clinic.html'), 'utf8');
  assert.match(page, /Confined \(<span x-text="confinedCount"/);
  assert.match(page, /clinicConfinementWorkspace\(\)/); assert.match(page, /charge\.vaccination_record_id \? 'Treatment'/);
  assert.match(page, /dischargePreview\?\.pricing.requires_final_price/);
  assert.match(page, /x-show="canRemoveService\(entry\)"/);
  assert.equal(/x-model="(?:dischargeForm\.quantity|dischargeForm\.billable_days)"/.test(page), false);
  console.log('Clinic confinement admission, monitoring, discharge, retry, pricing and permission regressions passed');
})().catch(error => {console.error(error); process.exitCode = 1;});
