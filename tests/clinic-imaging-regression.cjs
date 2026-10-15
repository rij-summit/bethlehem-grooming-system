const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const calls = [], events = [];
const service = {id: 34, name: 'X-ray — Abdominal', billing_unit: 'service'};
const shot = {id: 41, name: 'X-ray — Additional Shot', billing_unit: 'shot'};
let fail = false, rowsFail = false, optionsFail = false, pricingError = null, createError = null;
const sandbox = {crypto: require('node:crypto').webcrypto, setTimeout, clearTimeout,
  CustomEvent: class {constructor(type, options) {this.type = type; this.detail = options.detail;}},
  window: {dispatchEvent: event => events.push(event)},
  API: {
    getClinicCaseServices: async (...args) => {calls.push(['rows', ...args]); if (rowsFail) throw new Error('offline'); return {data: [], current_page: 1, last_page: 1};},
    clinicCaseServiceOptions: async (...args) => {calls.push(['options', ...args]); if (optionsFail) throw new Error('offline'); return {data: [service, shot], current_page: 1, last_page: 1};},
    clinicCaseServicePricing: async (id, serviceId) => ({data: serviceId === shot.id ? shot : service,
      pricing: {resolved_price: serviceId === shot.id ? '350.00' : '1950.00', requires_final_price: false, error: pricingError}}),
    clinicCreateCaseService: async (id, payload) => {calls.push(['add', id, {...payload}]); if (createError) throw createError; if (fail) throw new Error('timeout');},
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
  const event = {target: {files: [{name: 'sample.png', size: 1024}], value: 'sample.png'}};
  await state.uploadImagingAttachment({id: 7}, event);
  assert.equal(calls.find(call => call[0] === 'upload')[4], 7);
  assert.equal(state.imagingAttachments({id: 7}).length, 1);
  assert.equal(event.target.value, '');
  for (const name of ['scan.JPG', 'scan.jpeg', 'scan.png', 'scan.dcm']) {
    event.target.files = [{name, size: 10 * 1024 * 1024}];
    const uploads = calls.filter(call => call[0] === 'upload').length;
    await state.uploadImagingAttachment({id: 8}, event);
    assert.equal(calls.filter(call => call[0] === 'upload').length, uploads + 1);
    assert.equal(state.imagingAttachments({id: 8}).length > 0, true);
  }
  for (const file of [{name: 'result.pdf', size: 1024}, {name: 'oversize.png', size: 10 * 1024 * 1024 + 1}]) {
    event.target.files = [file];
    const uploads = calls.filter(call => call[0] === 'upload').length;
    await state.uploadImagingAttachment({id: 7}, event);
    assert.equal(calls.filter(call => call[0] === 'upload').length, uploads);
    assert.match(state.imagingError, /JPG, JPEG, PNG or DCM/);
  }
  rowsFail = true; await state.loadImagingServices();
  assert.equal(state.imagingRetry.action, 'rows');
  rowsFail = false; await state.retryImaging();
  assert.equal(state.imagingError, ''); assert.equal(state.imagingRetry, null);
  optionsFail = true; await state.loadImagingOptions();
  assert.equal(state.imagingRetry.action, 'options');
  optionsFail = false; await state.retryImaging();
  assert.equal(state.imagingError, '');
  pricingError = 'No configured price: PHP 500';
  await state.selectImagingService(service);
  assert.equal(state.imagingError, state.imagingSetupMessage);
  const adds = calls.filter(call => call[0] === 'add').length;
  await state.addImagingService();
  assert.equal(calls.filter(call => call[0] === 'add').length, adds);
  pricingError = null; await state.selectImagingService(service);
  createError = {status: 422, errors: {applied_price: ['Minimum PHP 500']}};
  await state.addImagingService();
  assert.equal(state.imagingError, state.imagingSetupMessage);
  createError = null;
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
  state.imagingOptionsPage = 1;
  state.syncImaging(true, {id: 2}, 'medical');
  assert.equal(state.imagingOptionsPage, 0, 'A reopened record does not show a stale empty-search result');
  const page = fs.readFileSync(path.join(__dirname, '../pages/admin/clinic.html'), 'utf8');
  const medical = page.slice(page.indexOf('<form id="medical-record-form"'), page.indexOf('</form>', page.indexOf('<form id="medical-record-form"')));
  assert.ok(medical.includes('Diagnostic Imaging'));
  assert.doesNotMatch(medical, /Diagnostics &amp; Tests|Add Diagnostic|generalAttachments|Associate with diagnostic|Refresh Imaging|Resolving price|cataloguePrice|money\(|>Price<|>Attachments<|\.pdf/);
  assert.match(medical, /accept="\.jpg,\.jpeg,\.png,\.dcm"/);
  const header = page.slice(page.indexOf('id="patient-record-title"'), page.indexOf('role="tablist"', page.indexOf('id="patient-record-title"')));
  assert.doesNotMatch(header, /Charges|total_amount/);
  console.log('Clinic imaging selection, quantity, shared billing, retry, file association and permissions passed');
})().catch(error => {console.error(error); process.exitCode = 1;});
