const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../scripts/components/admin-clinic.js'), 'utf8');
const deferred = () => {
  let resolve;
  const promise = new Promise(done => { resolve = done; });
  return {promise, resolve};
};
const record = {appointment_id: 10, status: 'completed', working_diagnosis: 'Otitis', attachments: [{id: 1}]};
const pet = {id: 7, petName: 'Mochi', species: 'Dog', breed: 'Mixed', gender: 'female', birthdate: '2021-02-03',
  weight: 12.5, size: 'medium', sizeVerified: true, color: 'Brown', furType: 'short_coat',
  isNeutered: true, neuteredDate: '2022-05-06', isDeceased: false, medicalConditions: ' Sensitive skin '};
const owner = {id: 8, recordType: 'registered', fullName: 'Ana Santos', phone: '09171234567', email: 'ana@example.test'};

function setup() {
  const calls = [], focused = [], events = [];
  const context = {
    document: {body: {style: {overflow: 'auto'}}, addEventListener() {}, activeElement: null},
    window: {addEventListener() {}, dispatchEvent: event => events.push(event)},
    CustomEvent: class {constructor(type, options) {this.type = type; this.detail = options?.detail;}},
    API: {
      adminGetPetProfile: async id => {calls.push(['history', id]); return {medical_records: [record]};},
      getCustomerDetails: async id => {calls.push(['registered', id]); return {customer: {...owner, pets: [pet]}};},
      getUnregisteredCustomerDetails: async id => {calls.push(['unregistered', id]); return {customer: {...owner, pets: [pet]}};},
    },
    setTimeout, clearTimeout, console,
  };
  vm.createContext(context);
  vm.runInContext(source, context);
  const state = context.adminClinicPage();
  state.$nextTick = callback => callback();
  state.$refs = {profileBody: {scrollTop: 0}, profileClose: {focus: () => focused.push('close')}, profileBack: {focus: () => focused.push('back')}};
  state.$store = {clinicAccess: {permissions: {access: true, intake: true, clinical: true}}, clinicFeedback: {confirm: async () => true, notify() {}}};
  const trigger = {focus: () => focused.push('trigger')};
  return {context, state, calls, focused, events, trigger};
}

(async () => {
  const app = setup();
  await app.state.openProfile({pet: {id: 7, petName: 'Mochi'}, owner}, app.trigger);
  assert.deepEqual(app.calls, [['history', 7], ['registered', 8]]);
  assert.equal(app.state.profile.pet.color, 'Brown', 'Profile loads full pet data rather than the directory summary');
  assert.equal(app.state.patientName, 'Mochi');
  assert.equal(app.state.patientConditions, 'Sensitive skin');
  assert.equal(app.state.profile.records[0], record, 'Full completed record data is reused without a second fetch');
  const fields = Object.fromEntries(app.state.patientInformation().map(field => [field.label, field.value]));
  assert.equal(fields.Weight, '12.5 kg');
  assert.equal(fields.Gender, 'Female');
  assert.equal(fields['Fur Type'], 'Short Coat');
  assert.equal(fields['Neutered / Spayed'], 'Yes');
  assert.ok(fields['Neutered Date']);
  assert.equal(fields.Deceased, 'No');
  assert.equal('Deceased Date' in fields, false);
  assert.equal('Phone' in fields, false, 'Owner contact belongs outside patient information');
  assert.equal('Pet Name' in fields, false, 'Patient identity is only in the header');
  assert.equal(app.context.document.body.style.overflow, 'hidden');
  assert.equal(app.focused.at(-1), 'close');

  app.state.$refs.profileBody.scrollTop = 340;
  const rowButton = {focus: () => app.focused.push('record')};
  app.state.viewDetail(record, {querySelector: () => rowButton});
  assert.equal(app.state.detail.record, record);
  assert.equal(app.state.profile.open, true, 'Drill-down stays inside the same workspace');
  assert.equal('open' in app.state.detail, false, 'There is no second detail-modal state');
  assert.equal(app.state.$refs.profileBody.scrollTop, 0);
  assert.equal(app.focused.at(-1), 'back');
  assert.equal(app.calls.length, 2, 'Opening a historical visit does not fetch or mutate a case');
  app.state.backToProfile();
  assert.equal(app.state.detail.record, null);
  assert.equal(app.state.profile.records.length, 1);
  assert.equal(app.state.$refs.profileBody.scrollTop, 340);
  assert.equal(app.focused.at(-1), 'record');
  app.state.closeProfile();
  assert.equal(app.context.document.body.style.overflow, 'auto');
  assert.equal(app.focused.at(-1), 'trigger');

  await app.state.openProfile({pet: {id: 7}, owner: {...owner, recordType: 'unregistered'}}, app.trigger);
  assert.deepEqual(app.calls.at(-1), ['unregistered', 8], 'Walk-in customers use their existing details API');
  app.state.profile.pet = {...pet, isNeutered: false, isDeceased: true, deceasedDate: '2026-01-01', medicalConditions: null};
  const deceased = Object.fromEntries(app.state.patientInformation().map(field => [field.label, field.value]));
  assert.ok(deceased['Deceased Date']);
  assert.equal('Neutered Date' in deceased, false);
  assert.equal(app.state.patientConditions, '');
  app.state.closeProfile();

  const delayed = setup(), history = deferred();
  delayed.context.API.adminGetPetProfile = () => history.promise;
  const opening = delayed.state.openProfile({pet: {id: 7}, owner}, delayed.trigger);
  assert.equal(delayed.state.profile.loading, true);
  delayed.state.closeProfile();
  delayed.context.API.adminGetPetProfile = async () => ({medical_records: []});
  await delayed.state.openProfile({pet: {id: 7}, owner}, delayed.trigger);
  history.resolve({medical_records: [record]});
  await opening;
  assert.equal(delayed.state.profile.records.length, 0, 'A closed profile response cannot overwrite a new profile');
  delayed.state.closeProfile();

  const failed = setup();
  failed.context.API.getCustomerDetails = async () => {throw new Error('Unable to load customer');};
  await failed.state.openProfile({pet: {id: 7}, owner}, failed.trigger);
  assert.equal(failed.state.profile.error, 'Unable to load customer');
  assert.equal(failed.state.profile.loading, false);
  failed.state.closeProfile();

  const workflow = setup();
  await workflow.state.openProfile({pet: {id: 7}, owner}, workflow.trigger);
  const transitions = [];
  workflow.context.API.createClinicCase = async payload => {transitions.push(['create', payload.pet_id, payload.case_type]); return {case: {id: 90, status: 'waiting_to_arrive'}};};
  workflow.context.API.clinicCheckIn = async id => {transitions.push(['check-in', id]); return {appointment: {id, status: 'checked_in'}};};
  workflow.context.API.startClinicCase = async id => {transitions.push(['start', id]); return {case: {id, status: 'in_consultation'}};};
  workflow.state.loadActiveCases = async () => {};
  await workflow.state.newConsultation();
  assert.deepEqual(transitions, [['create', 7, 'consultation'], ['check-in', 90], ['start', 90]]);
  assert.equal(workflow.events.at(-1).type, 'clinic-open-modal');
  assert.equal(workflow.events.at(-1).detail.section, 'medical');
  assert.equal(workflow.state.profile.open, false, 'New Case hands off to the established case workspace');

  console.log('Patient Profile full data, drill-down, focus restoration, stale loading, errors and New Case regressions passed');
})().catch(error => {console.error(error); process.exitCode = 1;});
