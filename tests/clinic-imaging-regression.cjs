const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const calls = [], events = [];
const service = {id: 34, name: 'X-ray — Abdominal', billing_unit: 'service'};
const shot = {id: 41, name: 'X-ray — Additional Shot', billing_unit: 'shot'};
let fail = false;
const sandbox = {crypto: require('node:crypto').webcrypto, setTimeout, clearTimeout,
  CustomEvent: class {constructor(type, options) {this.type = type; this.detail = options.detail;}},
  window: {dispatchEvent: event => events.push(event)},
  API: {
    getClinicCaseServices: async (...args) => {calls.push(['rows', ...args]); return {data: [], current_page: 1, last_page: 1};},
    clinicCaseServiceOptions: async (...args) => {calls.push(['options', ...args]); return {data: [service, shot], current_page: 1, last_page: 1};},
    clinicCaseServicePricing: async (id, serviceId) => ({data: serviceId === shot.id ? shot : service,
      pricing: {resolved_price: serviceId === shot.id ? '350.00' : '1950.00', requires_final_price: false}}),
    clinicCreateCaseService: async (id, payload) => {calls.push(['add', id, {...payload}]); if (fail) throw new Error('timeout');},
    clinicUploadAttachment: async (...args) => {calls.push(['upload', ...args]); return {attachment: {id: 1, clinic_case_service_id: args[3]}};},
  },
};
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(path.join(__dirname, '../scripts/components/admin-clinic-case-workspace.js'), 'utf8'), sandbox);
function component() {
  const state = sandbox.clinicImagingWorkspace();
  Object.assign(state, {canEditClinical: true, serviceSaving: false, serviceRemovingId: null, saving: false,
    attachmentBusy: false, attachments: [], $store: {clinicFeedback: {notify() {}}},
    loadServices: async () => calls.push(['services']), loadBilling: async () => calls.push(['billing']),
    removeService: async entry => {calls.push(['remove', entry.id]); return true;}});
  state.syncImaging(true, {id: 1}, 'medical');
  return state;
}
(async () => {
  const state = component();
  await state.loadImagingOptions();
  assert.deepEqual(calls.find(call => call[0] === 'options'), ['options', '', 1, 'imaging']);
  await state.selectImagingService(service);
  state.imagingNotes = 'Sample imaging';
  await state.addImagingService();
  const payload = calls.find(call => call[0] === 'add')[2];
  assert.equal(payload.clinic_service_id, 34);
  assert.equal(payload.notes, 'Sample imaging');
  for (const field of ['quantity', 'status', 'applied_price']) assert.equal(field in payload, false);
  assert.ok(calls.some(call => call[0] === 'services'));
  assert.ok(calls.some(call => call[0] === 'billing'));
  await state.selectImagingService(shot);
  for (const value of [0, -1, 1.5, '']) {state.imagingShots = value; assert.equal(state.validImagingShots, false);}
  state.imagingShots = 2; fail = true;
  await state.addImagingService();
  const pending = JSON.stringify(state.imagingPending);
  fail = false; await state.addImagingService();
  assert.equal(JSON.stringify(calls.filter(call => call[0] === 'add').at(-1)[2]), pending, 'Retries reuse the same occurrence key');
  assert.equal(calls.filter(call => call[0] === 'add').at(-1)[2].quantity, 2);
  const event = {target: {files: [{name: 'sample.pdf'}], value: 'sample.pdf'}};
  await state.uploadImagingAttachment({id: 7}, event);
  assert.equal(calls.find(call => call[0] === 'upload')[4], 7);
  assert.equal(state.imagingAttachments({id: 7}).length, 1);
  assert.equal(event.target.value, '');
  await state.removeImagingService({id: 7});
  assert.ok(calls.some(call => call[0] === 'remove' && call[1] === 7));
  state.canEditClinical = false;
  const before = calls.length;
  await state.loadImagingOptions(); await state.addImagingService(); await state.uploadImagingAttachment({id: 7}, event); await state.removeImagingService({id: 7});
  assert.equal(calls.length, before, 'Read-only cases do not send imaging mutations');
  state.syncImaging(false, null, 'medical');
  const generation = state._imagingGeneration;
  state.syncImaging(false, null, 'medical');
  assert.equal(state._imagingGeneration, generation, 'Closing is stable under Alpine effects');
  console.log('Clinic imaging selection, quantity, shared billing, retry, file association and permissions passed');
})().catch(error => {console.error(error); process.exitCode = 1;});
