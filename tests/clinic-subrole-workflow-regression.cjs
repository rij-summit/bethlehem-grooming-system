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
  document: { addEventListener(name, callback) { if (name === 'alpine:init') alpineInit = callback; }, getElementById() { return { focus() {} }; } },
  window: { addEventListener() {}, dispatchEvent() {}, location: { pathname: '/pages/admin/clinic.html', search: '' } },
  Alpine: { store(name, value) { stores[name] = value; } },
  CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options?.detail; } },
  API: { getUserRole: () => role },
  crypto: webcrypto, URLSearchParams, setTimeout, clearTimeout,
};
vm.createContext(sandbox);
for (const file of ['admin-clinic.js', 'admin-vaccinations.js', 'admin-sidebar.js']) {
  vm.runInContext(fs.readFileSync(path.join(root, 'scripts/components', file), 'utf8'), sandbox, {
    filename: path.join(root, 'scripts/components', file),
    importModuleDynamically: vm.constants.USE_MAIN_CONTEXT_DEFAULT_LOADER,
  });
}
alpineInit();
const feedback = { confirm: async () => true, notify() {} };
function component(factory) {
  const value = sandbox[factory]();
  value.$store = { clinicAccess: stores.clinicAccess, clinicFeedback: feedback };
  value.$refs = {};
  value.$el = { querySelector() { return null; } };
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
  let openedPet;
  receptionist.activeCases = [{id: 1, status: 'checked_in', pet: {id: 7}}];
  receptionist.openCase = appt => {openedPet = appt.pet.id;};
  sandbox.API.createClinicCase = async () => { throw new Error('Viewing must not create a case'); };
  receptionist.openProfileCase();
  assert.equal(openedPet, 7);
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
  Object.assign(record.form, { primary_veterinarian_id: '1', weight_kg: '4.20', temperature_c: 38.5, heart_rate_bpm: 80, respiratory_rate_bpm: 20 });
  let recordSaveCalls = 0;
  sandbox.API.clinicSaveRecord = async (id, payload) => {
    recordSaveCalls++;
    assert.equal(id, 1);
    assert.equal(payload.finish_case, true);
    assert.equal('services' in payload, false);
    assert.equal('medications' in payload, false);
    assert.equal(payload.weight_kg, '4.20');
  };
  await record.saveRecord(true);
  assert.equal(recordSaveCalls, 1);
  for (const weight of ['4.780', '4.200000', '10.555']) {
    record.form.weight_kg = weight;
    await record.saveRecord(true);
    assert.equal(recordSaveCalls, 1, 'Invalid precision cannot reach the clinic API');
    assert.equal(record.form.weight_kg, weight);
    assert.equal(record.fieldError('weight_kg'), 'Enter weight using no more than 2 decimal places.');
  }
  record.form.weight_kg = '4.20';
  await record.saveRecord(true);
  assert.equal(record.fieldError('weight_kg'), '');
  assert.equal(recordSaveCalls, 2);
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
    ['grooming', 'grooming_receptionist', 'Grooming Receptionist'], ['grooming', null, 'Staff'], [null, null, 'Staff'],
  ]) {
    sandbox.API.getMe = sandbox.API.getStaffIdentity = async () => ({ user: { first_name: 'Ana', last_name: 'Santos', staff_type, staff_subrole } });
    const sidebar = sandbox.adminSidebar();
    await sidebar.loadLoggedInIdentity();
    assert.equal(sidebar.staffRoleLabel, label);
  }
  const page = fs.readFileSync(path.join(root, 'pages/admin/clinic.html'), 'utf8');
  const filters = page.slice(page.indexOf('aria-label="Case status filters"'), page.indexOf('<section x-show="[\'active-cases\''));
  const filterOrder = [...filters.matchAll(/@click="setTab\('([^']+)'\)"/g)].map(match => match[1]);
  assert.deepEqual(filterOrder, ['active-cases', 'confined', 'for-payment']);
  assert.match(filters, /class="[^"]*lg:ml-auto"[^>]*:class="tab === 'for-payment'/);
  assert.equal(filters.includes('justify-between'), false, 'Small screens use natural spacing and wrapping');
  assert.equal((page.match(/Save &amp; Send to Payment/g) || []).length, 2);
  assert.match(page, /x-show="caseItem.status === 'for_payment'"[^>]*x-text="money\(caseItem.total_amount\)"/);
  for (const label of ['Medications', 'Clinical Products Used', 'Finalize Use', 'productBusy']) {
    assert.equal(page.includes(label), false, `${label} must be removed`);
  }
  assert.match(page, /aria-label="Billing"/);
  assert.match(page, /Attachments<\/h3>/);
  assert.match(page, /Follow-up<\/h3>/);
  assert.equal((page.match(/x-for="caseItem in caseRows"/g) || []).length, 1);
  const clinicSource = fs.readFileSync(path.join(root, 'scripts/components/admin-clinic.js'), 'utf8');
  assert.match(clinicSource, /item\.status === 'for_payment' && this\.permissions\.payment/);
  assert.match(clinicSource, /item\.status === 'waiting_to_arrive' && this\.permissions\.intake/);
  permissions(true, true, true, true);
  const casePage = component('adminClinicPage');
  const actions = [];
  casePage.checkInCase = item => actions.push(['check-in', item.id]);
  casePage.startCase = item => actions.push(['start', item.id]);
  casePage.reviewPayment = item => actions.push(['payment', item.id]);
  casePage.openCase = (item, section) => actions.push(['open', item.id, section]);
  for (const [status, label] of Object.entries({waiting_to_arrive: 'Check In', checked_in: 'Start Consultation', in_consultation: 'Open Case', for_payment: 'Review & Collect Payment'})) {
    assert.equal(casePage.caseActionLabel({status}), label);
    casePage.runCaseAction({id: 1, status});
  }
  assert.equal(actions[0][0], 'check-in');
  assert.equal(actions[1][0], 'start');
  assert.equal(actions[2][0], 'open');
  assert.equal(actions[3][0], 'payment', 'Payment action opens the existing payment review directly');
  casePage.activeCases = [{id: 1, status: 'in_consultation'}, {id: 2, status: 'for_payment'},
    {id: 3, status: 'in_consultation', confinement: {discharged_at: null}}];
  assert.equal(casePage.activeCount, 1);
  assert.equal(casePage.forPaymentCount, 1);
  assert.equal(casePage.visibleActiveCases[0].id, 1);
  casePage.tab = 'for-payment';
  assert.equal(casePage.visibleActiveCases.length, 1);
  assert.equal(casePage.visibleActiveCases[0].id, 2);
  permissions(false, false, false, false);
  assert.equal(casePage.caseActionLabel({status: 'for_payment'}), 'Open Case', 'Read-only staff do not receive payment actions');
  const chargesModal = component('adminClinicModal');
  chargesModal.$nextTick = () => {};
  chargesModal.currentAppt = {id: 1, status: 'for_payment'};
  chargesModal.activeSection = 'services';
  chargesModal.selectSection('billing');
  assert.equal(chargesModal.chargesOpen, false, 'Charges requires billing permission');
  stores.clinicAccess.permissions.billing = true;
  chargesModal.selectSection('billing');
  assert.equal(chargesModal.chargesOpen, true);
  assert.equal(chargesModal.activeSection, 'services', 'Charges preserves the clinical section');
  chargesModal.closeCharges();
  assert.equal(chargesModal.activeSection, 'services');
  assert.equal(chargesModal.chargesOpen, false);
  console.log('Clinic subrole workflow regression checks passed');
})().catch((error) => { console.error(error); process.exitCode = 1; });
