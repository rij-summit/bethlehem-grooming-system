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
      adminGetPetCaseDetails: async (petId, caseId) => {calls.push(['case', petId, caseId]); return {case: {id: caseId}, medical_record: record};},
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
  await app.state.viewDetail(record, {querySelector: () => rowButton});
  assert.equal(app.state.detail.record, record);
  assert.equal(app.state.profile.open, true, 'Drill-down stays inside the same workspace');
  assert.equal('open' in app.state.detail, false, 'There is no second detail-modal state');
  assert.equal(app.state.$refs.profileBody.scrollTop, 0);
  assert.equal(app.focused.at(-1), 'back');
  assert.deepEqual(app.calls.at(-1), ['case', 7, 10], 'Details read the selected case through the pet-scoped API');
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
  let ongoing = [];
  workflow.context.API.getActiveClinicCases = async () => ({cases: ongoing});
  workflow.context.API.createClinicCase = async payload => {
    transitions.push(['create', payload.pet_id, payload.case_type]);
    const visit = {id: 90, status: 'checked_in', queue_number: 4, pet: {id: 7}};
    ongoing = [visit];
    return {case: visit};
  };
  workflow.context.API.clinicCheckIn = async () => {throw new Error('A newly created checked-in case needs no second check-in');};
  workflow.context.API.startClinicCase = async id => {transitions.push(['start', id]); ongoing = [{...ongoing[0], status: 'in_consultation'}]; return {case: ongoing[0]};};
  await workflow.state.checkInPatient();
  assert.deepEqual(transitions, [['create', 7, 'consultation']], 'Check-in never automatically starts consultation');
  assert.equal(workflow.state.currentClinicVisit.status, 'checked_in');
  assert.equal(workflow.state.currentClinicVisit.queue_number, 4);
  assert.equal(workflow.state.activeCount, 1);
  assert.equal(workflow.events.some(event => event.type === 'clinic-open-modal'), false);
  assert.equal(workflow.state.profile.open, true, 'Check-in keeps patient/history and the operational case separate');
  assert.equal(workflow.state.profileActionLabel, 'Start Consultation');
  await workflow.state.checkInPatient();
  assert.equal(transitions.length, 1, 'An ongoing case prevents repeat creation');
  workflow.state.openProfileCase();
  assert.equal(workflow.events.some(event => event.type === 'clinic-open-modal'), false, 'Checked-in patients cannot bypass Start Consultation');
  await workflow.state.runProfileAction();
  assert.deepEqual(transitions.at(-1), ['start', 90]);
  assert.equal(workflow.events.at(-1).type, 'clinic-open-modal', 'Starting from the profile opens the returned active case automatically');
  assert.equal(workflow.events.at(-1).detail.appt.status, 'in_consultation');
  assert.equal(workflow.state.profile.open, false);
  assert.equal(workflow.state.profileActionLabel, 'Open Case');
  assert.equal(workflow.state.caseActionLabel(ongoing[0]), 'Open Case');
  const starts = transitions.length;
  workflow.state.openProfileCase();
  assert.equal(transitions.length, starts, 'Reopening does not start consultation again');
  ongoing = [{...ongoing[0], status: 'for_payment'}];
  workflow.state.activeCases = ongoing;
  assert.equal(workflow.state.profileActionLabel, '');
  const openedEvents = workflow.events.length;
  workflow.state.openProfileCase();
  await workflow.state.runProfileAction();
  assert.equal(workflow.events.length, openedEvents, 'For Payment has no profile clinical shortcut');

  const waiting = setup();
  await waiting.state.openProfile({pet: {id: 7}, owner}, waiting.trigger);
  let waitingVisit = {id: 2, status: 'waiting_to_arrive', pet: {id: 7}};
  waiting.state.activeCases = [waitingVisit];
  waiting.context.API.getActiveClinicCases = async () => ({cases: [waitingVisit]});
  waiting.context.API.createClinicCase = async () => {throw new Error('Waiting arrivals must reuse their case');};
  waiting.context.API.clinicCheckIn = async id => {assert.equal(id, 2); waitingVisit = {...waitingVisit, status: 'checked_in', queue_number: 5}; return {appointment: waitingVisit};};
  waiting.context.API.startClinicCase = async () => {throw new Error('Check-in must stop at checked_in');};
  assert.equal(waiting.state.activeCount, 0);
  assert.equal(waiting.state.waitingCases.length, 1);
  await waiting.state.checkInPatient();
  assert.equal(waiting.state.activeCount, 1);
  assert.equal(waiting.state.currentClinicVisit.queue_number, 5);

  const race = setup();
  await race.state.openProfile({pet: {id: 7}, owner}, race.trigger);
  race.context.API.getActiveClinicCases = async () => ({cases: []});
  race.context.API.createClinicCase = async () => {throw {code: 'active_case_exists', data: {case: {id: 3, status: 'in_consultation', pet: {id: 7}}}};};
  race.context.API.clinicCheckIn = race.context.API.startClinicCase = async () => {throw new Error('An existing case conflict must not change status');};
  await race.state.checkInPatient();
  assert.equal(race.state.profileActionLabel, 'Open Case');
  assert.equal(race.state.currentClinicVisit.status, 'in_consultation');
  assert.equal(race.state.profile.open, true);

  const denied = setup();
  await denied.state.openProfile({pet: {id: 7}, owner}, denied.trigger);
  denied.state.$store.clinicAccess.permissions.intake = false;
  denied.context.API.createClinicCase = async () => {throw new Error('No intake permission');};
  await denied.state.checkInPatient();
  assert.equal(denied.state.currentClinicVisit, null);
  assert.equal(denied.state.profileActionLabel, '');
  denied.state.activeCases = [{id: 1, pet: {id: 7}, status: 'checked_in'}];
  denied.state.$store.clinicAccess.permissions.clinical = false;
  assert.equal(denied.state.profileActionLabel, '');
  await denied.state.runProfileAction();
  denied.state.openProfileCase();
  assert.equal(denied.events.some(event => event.type === 'clinic-open-modal'), false);
  assert.equal(denied.state.caseActionLabel({status: 'checked_in'}), '');
  denied.state.runCaseAction({id: 1, status: 'checked_in'});
  denied.state.runCaseAction({id: 1, status: 'waiting_to_arrive'});
  denied.state.runCaseAction({id: 1, status: 'for_payment'});
  assert.equal(denied.events.some(event => event.type === 'clinic-open-modal'), false, 'Denied row actions never fall back to opening a case');

  assert.equal(denied.state.casePatientLabel({status: 'waiting_to_arrive', queue_number: 8}), 'Patient');
  assert.equal(denied.state.casePatientLabel({status: 'checked_in', queue_number: 5, pet: {name: 'Max'}}), '#5 · Max');
  const markup = fs.readFileSync(path.join(__dirname, '../pages/admin/clinic.html'), 'utf8');
  assert.doesNotMatch(markup, /Current Clinic Visit|Open Active Case|caseOpened\(/);

  const filtering = setup();
  filtering.state.activeCases = ['waiting_to_arrive', 'checked_in', 'in_consultation', 'for_payment', 'completed'].map((status, id) => ({id, status}));
  filtering.state.activeCases.push({id: 6, status: 'in_consultation', confinement: {discharged_at: null}});
  assert.equal(filtering.state.activeCount, 2);
  assert.deepEqual(Array.from(filtering.state.visibleActiveCases, item => item.status), ['checked_in', 'in_consultation']);
  assert.equal(filtering.state.waitingCases.length, 1);
  assert.equal(filtering.state.caseRows.length, 3, 'Waiting arrivals remain visible without being counted Active');
  filtering.state.tab = 'for-payment';
  assert.deepEqual(Array.from(filtering.state.caseRows, item => item.status), ['for_payment']);

  console.log('Patient Profile read-only browsing, explicit check-in/start, ongoing-case reuse, history and filtering regressions passed');
})().catch(error => {console.error(error); process.exitCode = 1;});
