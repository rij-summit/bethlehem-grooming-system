const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const {webcrypto} = require('node:crypto');
const root = path.resolve(__dirname, '..');
const deferred = () => {let resolve; const promise = new Promise(done => {resolve = done;}); return {promise, resolve};};
const settle = () => new Promise(resolve => setImmediate(resolve));
const events = [], writes = [];
const context = {console, setTimeout, clearTimeout, crypto: webcrypto,
  document: {addEventListener() {}, body: {style: {overflow: 'auto'}}},
  window: {addEventListener() {}, dispatchEvent: event => events.push(event)},
  CustomEvent: class {constructor(type, options) {this.type = type; this.detail = options?.detail;}},
  API: {getUserRole: () => 'staff'},
};
vm.createContext(context);
for (const file of ['admin-clinic.js', 'admin-clinic-case-workspace.js', 'admin-clinic-confinement.js', 'admin-vaccinations.js'])
  vm.runInContext(fs.readFileSync(path.join(root, 'scripts/components', file), 'utf8'), context);
const access = {permissions: {access: true, clinical: true, intake: true}};
const attach = state => {
  state.$store = {clinicAccess: structuredClone(access), clinicFeedback: {confirm: async () => true, notify() {}}};
  state.$nextTick = callback => callback(); state.$refs = {};
  return state;
};
const appt = id => ({id, pet: {id: 7, name: 'Mochi'}, status: 'in_consultation', vitals: {weight_kg: 12}});
const product = {item_id: 5, item_name: 'Rabies', available_quantity: 10, unit: 'vial'};
(async () => {
  const state = attach(context.adminClinicVaccinations()); state.inline = true;
  context.API.getAdminVaccinationOptions = async () => ({vaccines: [product]});
  const reads = [], older = deferred();
  context.API.getAdminPetVaccinations = async (pet, id) => {
    reads.push([pet, id]);
    return id === 90 ? older.promise : {vaccinations: [
      {id: 1, clinic_appointment_id: 90}, {id: 2, clinic_appointment_id: 91}, {id: 3, clinic_appointment_id: null},
    ]};
  };
  state.openForAppointment(appt(90));
  assert.equal(state.formModal.open, true, 'Selecting Treatment immediately opens entry');
  assert.equal(state.formModal.clinicAppointmentId, 90);
  state.selectVaccine(product);
  assert.equal(writes.length, 0, 'Selection has no stock or billing side effects');
  state.openForAppointment(appt(91)); await settle();
  assert.equal(state.formModal.clinicAppointmentId, 91, 'Switching visits for the same pet resets the case association');
  assert.equal(state.selectedProduct, null);
  assert.deepEqual(Array.from(state.records, row => row.id), [2]);
  older.resolve({vaccinations: [{id: 9, clinic_appointment_id: 90}]}); await settle();
  assert.deepEqual(Array.from(state.records, row => row.id), [2], 'An old visit response cannot replace the current visit');
  assert.deepEqual(reads, [[7,90],[7,91]]);

  context.API.getAdminPetVaccinations = async () => ({vaccinations: []});
  context.API.createAdminPetVaccination = async (pet, payload) => {writes.push(['create',pet,payload]); return {vaccination:{id:10}};};
  let failPublish = true;
  context.API.publishAdminPetVaccination = async (pet,id,options) => {writes.push(['publish',pet,id,options]); if (failPublish) throw new Error('Publish failed'); return {};};
  context.API.updateAdminPetVaccination = async (pet,id,payload) => {writes.push(['update',pet,id,payload]); return {vaccination:{id}};};
  state.selectVaccine(product);state.form.notes = 'Current visit';
  await state.recordTreatment();
  assert.equal(state.formModal.open,true);
  assert.equal(state.formModal.mode,'edit');
  assert.equal(state.formModal.recordId,10,'Failed publishing retains the saved draft for retry');
  failPublish = false;await state.recordTreatment();
  assert.deepEqual(writes.map(call => call[0]), ['create','publish','update','publish'], 'Retry updates the same draft');
  assert.equal(writes[0][2].clinic_appointment_id,91);
  assert.equal(writes[3][2],10);
  assert.equal(writes[3][3].finishCase,false,'Recording treatment keeps the consultation open');
  assert.equal(writes[3][3].consumeInventory,true);
  assert.equal(state.appointment.status,'in_consultation');
  assert.equal(state.formModal.open,true,'Entry remains ready for the next treatment');
  assert.equal(state.formModal.mode,'create');assert.equal(state.form.notes,'');assert.equal(state.selectedProduct,null);

  state.recordKind='historical';state.changeSource();state.form.vaccine_name='External vaccine';state.form.administered_date='2020-01-01';
  await state.recordTreatment();
  assert.equal(writes.at(-1)[3].consumeInventory,false);
  assert.equal(writes.at(-2)[2].inventory_item_id,null);
  assert.equal(writes.at(-2)[2].clinic_appointment_id,91);
  const count = writes.length;
  state.$store.clinicAccess.permissions.clinical = false;await state.saveDraft();await state.recordTreatment();
  assert.equal(writes.length,count,'Read-only roles cannot record or save drafts');
  state.$store.clinicAccess.permissions.clinical = true;state.appointment.paid = true;
  await state.recordTreatment();await state.saveDraft();assert.equal(writes.length,count,'Paid cases cannot receive treatment entries');

  assert.equal(context.clinicTreatmentSummary({dose_amount:'0.500',dose_unit:'mL',route:'intramuscular'}),'0.5 mL \u00b7 IM');
  assert.equal(context.clinicTreatmentSummary({route:'oral'}),'Oral');
  const fields = Object.fromEntries(context.clinicTreatmentDetails({treatment_type:'medicine',manufacturer:'Maker',batch_number:'LOT',product_expiry_date:'2027-01-01',notes:'Saved note',clinic_appointment_reference:'CL-91',inventory_quantity:'1.00',inventory_unit:'bottle'}).map(field => [field.label,field.value]));
  assert.equal(fields.Manufacturer,'Maker');assert.equal(fields['Batch / Lot'],'LOT');assert.equal(fields.Notes,'Saved note');assert.equal(fields.Case,'CL-91');assert.equal(fields.Quantity,'1 bottle');

  const profile = attach(context.adminClinicPage());
  const owner = {id:8,recordType:'registered',fullName:'Ana'};
  const historyReads=[];
  context.API.adminGetPetProfile = async (pet,tab,page) => {historyReads.push([pet,tab,page]);return tab ? {data:[{id:1,clinic_appointment_id:80,notes:'Saved',service_snapshot:{name:'Saved service'}}],current_page:page,last_page:2}:{medical_records:[]};};
  context.API.getCustomerDetails = async () => ({customer:{...owner,pets:[{id:7,name:'Mochi'}]}});
  context.API.getAdminPetVaccinations = async () => ({vaccinations:[{id:11,treatment_type:'medicine',notes:'Saved medicine'}]});
  await profile.openProfile({pet:{id:7},owner});assert.equal(historyReads.length,1);
  profile.selectHistory('services');await settle();assert.equal(historyReads.length,2);
  profile.selectHistory('medical');profile.selectHistory('services');await settle();assert.equal(historyReads.length,2,'Revisiting a loaded history tab does not refetch');
  await profile.loadHistory('services',2);assert.equal(profile.histories.services.rows.length,2);
  profile.selectHistory('treatment');await settle();assert.equal(profile.histories.treatment.rows[0].treatment_type,'medicine');
  profile.viewDetail(profile.histories.treatment.rows[0],null,'treatment');assert.equal(profile.historyDetailFields().find(field => field.label==='Notes').value,'Saved medicine');profile.backToProfile();assert.equal(profile.historyTab,'treatment');
  const delayed = deferred();context.API.adminGetPetProfile = () => delayed.promise;
  profile.selectHistory('confinement');profile.closeProfile();
  context.API.adminGetPetProfile = async () => ({medical_records:[]});await profile.openProfile({pet:{id:7},owner});
  delayed.resolve({data:[{id:100}],current_page:1,last_page:1});await settle();
  assert.equal(profile.histories.confinement.rows.length,0,'A closed profile response cannot populate a new profile');
  const monitoring = deferred();context.API.getClinicConfinementObservations = () => monitoring.promise;
  profile.viewDetail({id:1,clinic_appointment_id:80},null,'confinement');profile.backToProfile();
  monitoring.resolve({data:[{id:1}],current_page:1,last_page:1});await settle();assert.equal(profile.detail.record,null,'Late monitoring never reopens a closed detail');
  console.log('Patient Record separation, case isolation, explicit publishing, reset, retry, permissions and lazy history checks passed');
})().catch(error=>{console.error(error);process.exitCode=1;});
