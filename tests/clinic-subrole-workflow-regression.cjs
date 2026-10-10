const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const { webcrypto } = require('node:crypto');

const root = path.resolve(__dirname, '..');
const stores = {};
let alpineInit;
let role = 'staff';
const sandbox = {
  document: { addEventListener(name, callback) { if (name === 'alpine:init') alpineInit = callback; } },
  window: { addEventListener() {}, dispatchEvent() {}, location: { pathname: '/pages/admin/clinic.html', search: '' } },
  Alpine: { store(name, value) { stores[name] = value; } },
  CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options?.detail; } },
  API: { getUserRole: () => role },
  crypto: webcrypto, URLSearchParams, setTimeout, clearTimeout,
};
vm.createContext(sandbox);
for (const file of ['admin-clinic.js', 'admin-vaccinations.js', 'admin-sidebar.js']) {
  vm.runInContext(fs.readFileSync(path.join(root, 'scripts/components', file), 'utf8'), sandbox);
}
alpineInit();
const feedback = { confirm: async () => true, notify() {} };
function component(factory) {
  const value = sandbox[factory]();
  value.$store = { clinicAccess: stores.clinicAccess, clinicFeedback: feedback };
  value.$refs = {};
  value.$nextTick = (callback) => callback();
  return value;
}
function permissions(clinical, payment, queue, customers) {
  stores.clinicAccess.permissions = { access: true, intake: true, clinical, payment, queue, customers };
}

(async () => {
  let calls = [];
  for (const name of ['clinicSaveRecord', 'clinicUploadAttachment', 'clinicDeleteAttachment', 'startClinicCase', 'clinicRecordPayment', 'clinicCancel', 'createAdminPetVaccination', 'publishAdminPetVaccination']) {
    sandbox.API[name] = async () => { calls.push(name); };
  }
  permissions(false, true, true, true);
  const receptionistRecord = component('adminClinicModal');
  receptionistRecord.currentAppt = { status: 'in_consultation' };
  assert.equal(receptionistRecord.canEditClinical, false);
  await receptionistRecord.saveRecord(true);
  await receptionistRecord.uploadAttachment();
  await receptionistRecord.deleteAttachment({});
  const receptionist = component('adminClinicPage');
  await receptionist.startCase({ id: 1 });
  receptionist.profile = { pet: { id: 7, petName: 'Mochi' }, owner: { fullName: 'Ana Santos' }, open: true };
  let historyPet;
  receptionist.openCase = (appt, section) => { historyPet = appt.pet.id; assert.equal(section, 'vaccinations'); };
  sandbox.API.createClinicCase = async () => { throw new Error('Viewing history must not create a case'); };
  await receptionist.openVaccinations();
  assert.equal(historyPet, 7);
  const receptionistVaccines = component('adminClinicVaccinations');
  receptionistVaccines.pet = { id: 1 };
  receptionistVaccines.appointment = { id: 1, status: 'in_consultation' };
  receptionistVaccines.openCreateForm();
  await receptionistVaccines.saveDraft();
  await receptionistVaccines.publishRecord({});
  assert.equal(receptionistVaccines.formModal.open, false);
  assert.deepEqual(calls, []);

  let checkIns = 0;
  sandbox.API.clinicCheckIn = async () => { checkIns++; return { appointment: { id: 1, status: 'checked_in' } }; };
  receptionist.loadActiveCases = async () => {};
  await receptionist.checkInCase({ id: 1, status: 'waiting_to_arrive' });
  assert.equal(checkIns, 1);
  const paymentCase = { id: 1, status: 'for_payment', total_amount: '600.00', charges: [{ description: 'Exam', amount: '600.00' }] };
  receptionist.reviewPayment(paymentCase);
  assert.equal(receptionist.payment.open, true);
  sandbox.API.clinicRecordPayment = async (id, payload) => {
    assert.equal(id, 1);
    assert.equal(payload.total_amount, '600.00');
    assert.equal(payload.payment_method, 'cash');
  };
  await receptionist.recordPayment();
  assert.equal(receptionist.payment.open, false);
  assert.equal(receptionist.payment.error, '');

  permissions(true, false, false, false);
  const vet = component('adminClinicPage');
  vet.loadActiveCases = async () => {};
  await vet.checkInCase({ id: 2, status: 'waiting_to_arrive' });
  assert.equal(checkIns, 2);
  vet.reviewPayment(paymentCase);
  assert.equal(vet.payment.open, false);
  await vet.recordPayment();
  await vet.cancelCase({ id: 1 });
  sandbox.API.startClinicCase = async () => ({ case: { id: 1, status: 'in_consultation' } });
  let openedCase;
  vet.openCase = (item) => { openedCase = item; };
  await vet.startCase({ id: 1, status: 'checked_in' });
  assert.equal(openedCase.status, 'in_consultation');
  assert.equal(vet.caseStatus({ status: 'for_payment' }), 'For Payment');

  const record = component('adminClinicModal');
  record.currentAppt = { status: 'in_consultation' };
  record.apptId = 1;
  Object.assign(record.form, { weight_kg: 0, temperature_c: 38.5, heart_rate_bpm: 80, respiratory_rate_bpm: 20 });
  sandbox.API.clinicSaveRecord = async (id, payload) => {
    assert.equal(id, 1);
    assert.equal(payload.finish_case, true);
    assert.equal('services' in payload, false);
    assert.equal('medications' in payload, false);
    assert.equal(payload.weight_kg, 0);
  };
  await record.saveRecord(true);
  assert.equal(record.modalError, '');
  assert.equal('addMedication' in record, false);
  assert.equal('finalizeProduct' in record, false);
  assert.equal('loadClinicalItems' in record, false);
  record.currentAppt.status = 'for_payment';
  assert.equal(record.canEditClinical, false);
  role = 'admin';
  assert.equal(record.canEditClinical, true);
  assert.equal(record.canFinish, false);

  role = 'staff';
  for (const [staff_type, staff_subrole, label] of [
    ['clinic', 'veterinarian', 'Veterinarian'], ['clinic', 'clinic_receptionist', 'Clinic Receptionist'],
    ['grooming', null, 'Grooming Receptionist'], [null, null, 'Staff'],
  ]) {
    sandbox.API.getMe = sandbox.API.getStaffIdentity = async () => ({ user: { first_name: 'Ana', last_name: 'Santos', staff_type, staff_subrole } });
    const sidebar = sandbox.adminSidebar();
    await sidebar.loadLoggedInIdentity();
    assert.equal(sidebar.staffRoleLabel, label);
  }
  const page = fs.readFileSync(path.join(root, 'pages/admin/clinic.html'), 'utf8');
  for (const label of ['Medications', 'Services Performed', 'Clinical Products Used', 'Finalize Use', 'productBusy']) {
    assert.equal(page.includes(label), false, `${label} must be removed`);
  }
  assert.match(page, /Attachments<\/h3>/);
  assert.match(page, /Follow-up<\/h3>/);
  assert.equal((page.match(/x-for="caseItem in visibleActiveCases"/g) || []).length, 1);
  assert.match(page, /permissions\.payment && caseItem\.status === 'for_payment'/);
  assert.match(page, /permissions\.intake && caseItem\.status === 'waiting_to_arrive'/);
  console.log('Clinic subrole workflow regression checks passed');
})().catch((error) => { console.error(error); process.exitCode = 1; });
