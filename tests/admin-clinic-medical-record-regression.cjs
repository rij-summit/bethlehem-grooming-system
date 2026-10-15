const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const sandbox = {
  document: { addEventListener() {}, getElementById() {}, activeElement: { focus() {} } },
  window: { addEventListener() {}, dispatchEvent() {} },
  CustomEvent: class { constructor(type) { this.type = type; } },
  API: {}, crypto: require('node:crypto'), setTimeout, clearTimeout,
};
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(path.join(__dirname, '../scripts/components/admin-clinic.js'), 'utf8'), sandbox);
vm.runInContext(`clinicPetWeightToolsReady = Promise.resolve({
  formatSavedWeight: value => value == null ? '' : String(value),
  getWeightValueValidationMessage: () => '',
});`, sandbox);

function component(user) {
  const instance = sandbox.adminClinicModal();
  instance.$store = { clinicAccess: { user, permissions: { clinical: true } } };
  instance.$el = { querySelector() { return null; } };
  instance.$nextTick = callback => callback();
  instance.$refs = {};
  return instance;
}

(async () => {
  let optionsCalls = [], saves = [];
  sandbox.API.getUserRole = () => 'admin';
  sandbox.API.getAdminVaccinationOptions = async clinicalOnly => {
    optionsCalls.push(clinicalOnly);
    return { veterinarians: [{ user_id: 1, name: 'Vet' }, { user_id: 2, name: 'Other Vet' }],
      current_user_id: 1, current_user_is_veterinarian: true,
      administration_routes: ['oral'], diagnostic_options: ['CBC', 'Other'] };
  };
  sandbox.API.clinicSaveRecord = async (id, payload) => { saves.push(payload); };
  const vet = component({ user_id: 1, role: 'staff', staff_subrole: 'veterinarian' });
  await vet.openModal({ appt: { id: 100, status: 'in_consultation', ownerName: 'Owner', record: {
    primary_veterinarian_id: 2, additional_veterinarians: [{ user_id: 1 }],
    findings: 'Legacy exam', diagnosis: 'Legacy diagnosis', diagnostics: [{diagnostic: 'CBC', status: 'Completed'}],
  } } });
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(vet.form.primary_veterinarian_id, '1');
  assert.equal(vet.form.additional_veterinarian_ids.length, 0);
  assert.equal(vet.form.physical_exam_findings, 'Legacy exam');
  assert.equal(vet.form.working_diagnosis, 'Legacy diagnosis');
  assert.equal(optionsCalls[0], true, 'Medical Record requests provider options without vaccine inventory');
  assert.equal(Object.hasOwn(vet.form, 'diagnostics'), false);
  assert.equal(vet.addDiagnostic, undefined);
  assert.equal(vet.uploadAttachment, undefined);
  await vet.saveRecord();
  assert.equal(Object.hasOwn(saves[0], 'diagnostics'), false, 'Saving clinical fields leaves historical diagnostics untouched');
  assert.equal(Object.hasOwn(saves[0], 'medicines'), false);
  assert.equal(Object.hasOwn(saves[0], 'recorded_by_user_id'), false);
  assert.equal(saves[0].finish_case, false);

  vet.open = true;
  vet.form.temperature_c = 38.5;
  vet.form.heart_rate_bpm = 80;
  vet.form.respiratory_rate_bpm = 20;
  await vet.saveRecord(true);
  assert.equal(saves.length, 1, 'Missing completion vitals keep the record open');
  assert.equal(vet.hasFieldError('weight_kg'), true);
  assert.equal(vet.fieldError('weight_kg'), '', 'Missing fields do not repeat required messages');
  assert.equal(vet.modalError, 'Complete the required fields marked *.');
  assert.equal(vet.open, true);
  vet.form.weight_kg = 24;
  sandbox.API.clinicSaveRecord = async () => { throw { errors: { 'prescriptions.0.medicine': ['Enter a medicine name.'] } }; };
  await vet.saveRecord();
  assert.equal(vet.fieldError('prescriptions.0.medicine'), 'Enter a medicine name.');
  assert.equal(vet.open, true);

  sandbox.API.getAdminVaccinationOptions = async () => ({ veterinarians: [{ user_id: 2, name: 'Vet' }], current_user_id: 3, current_user_is_veterinarian: false });
  const admin = component({ user_id: 3, role: 'admin' });
  await admin.openModal({ appt: { id: 101, status: 'in_consultation', ownerName: 'Owner' } });
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(admin.form.primary_veterinarian_id, '', 'Administrators must choose an attending veterinarian');
  assert.equal(admin.isVeterinarian, false);
  Object.assign(admin.form, { weight_kg: 24, temperature_c: 38.5, heart_rate_bpm: 80, respiratory_rate_bpm: 20 });
  await admin.saveRecord(true);
  assert.equal(admin.hasFieldError('primary_veterinarian_id'), true);
  assert.equal(admin.fieldError('primary_veterinarian_id'), '');
  assert.equal(admin.modalError, 'Complete the required fields marked *.');

  admin.toggleVeterinarian(2);
  admin.toggleVeterinarian(4);
  admin.toggleVeterinarian(5);
  assert.equal(admin.form.primary_veterinarian_id, '2');
  assert.deepEqual(Array.from(admin.form.additional_veterinarian_ids), ['4', '5']);
  admin.toggleVeterinarian(2);
  assert.equal(admin.form.primary_veterinarian_id, '4', 'Removing the first selection promotes the next veterinarian');
  assert.deepEqual(Array.from(admin.form.additional_veterinarian_ids), ['5']);
  let adminPayload;
  sandbox.API.clinicSaveRecord = async (id, payload) => { adminPayload = payload; };
  await admin.saveRecord();
  assert.equal(adminPayload.primary_veterinarian_id, '4');
  assert.deepEqual(Array.from(adminPayload.additional_veterinarian_ids), ['5']);

  await admin.openModal({ appt: { id: 102, status: 'in_consultation', ownerName: 'Owner', record: {
    primary_veterinarian_id: 4, primary_veterinarian_name: 'Saved Vet',
    additional_veterinarians: [{ user_id: 5, name: 'Another Saved Vet' }],
  } } });
  await new Promise(resolve => setImmediate(resolve));
  assert.deepEqual(Array.from(admin.selectedVeterinarianIds), ['4', '5']);
  assert.deepEqual(Array.from(admin.selectedVeterinarians, row => row.name), ['Saved Vet', 'Another Saved Vet']);

  vet.toggleVeterinarian(1);
  assert.equal(vet.form.primary_veterinarian_id, '1', 'The current veterinarian cannot be deselected');
  vet.toggleVeterinarian(2);
  assert.deepEqual(Array.from(vet.form.additional_veterinarian_ids), ['2']);
  vet.toggleVeterinarian(2);
  assert.deepEqual(Array.from(vet.form.additional_veterinarian_ids), []);

  const clean = component({ user_id: 3, role: 'admin' });
  clean.form.weight_kg = 'invalid';
  assert.equal(clean.hasFieldError('weight_kg'), false, 'Typing alone does not show validation errors');
  clean.showValidationErrors({ 'prescriptions.0.medicine': ['The prescriptions.0.medicine field is required.'],
    primary_veterinarian_id: ['The primary veterinarian id field is required when finish case is accepted.'] });
  assert.equal(clean.modalError, 'Complete the required fields marked *.');
  assert.equal(clean.hasFieldError('prescriptions.0.medicine'), true);
  assert.equal(clean.fieldError('prescriptions.0.medicine'), '');
  clean.form.prescriptions = [{ medicine: 'Entered medicine' }];
  assert.equal(clean.fieldError('prescriptions.0.medicine'), '', 'Typing after a failed save does not introduce a new message');
  clean.showValidationErrors({ 'prescriptions.0.quantity': ['The prescriptions.0.quantity format is invalid.'],
    follow_up_date: ['The follow up date field must be a date after or equal to today.'] });
  assert.equal(clean.modalError, 'Check the highlighted fields.');
  assert.equal(clean.fieldError('prescriptions.0.quantity'), 'Enter a positive quantity, e.g. 14 tablets.');
  assert.equal(clean.fieldError('follow_up_date'), 'Choose a follow-up date within the allowed range.');
  clean.showValidationErrors({ 'prescriptions.0.medicine': ['The prescriptions.0.medicine field must not be greater than 200 characters.'] });
  assert.equal(clean.fieldError('prescriptions.0.medicine'), 'Use up to 200 characters.');

  let focused;
  clean.$el.querySelector = selector => selector.includes('aria-invalid') ? { focus() { focused = 'field'; }, scrollIntoView() {} } : null;
  clean.showValidationErrors({ 'prescriptions.0.dose': ['The prescriptions.0.dose field is required.'] });
  assert.equal(focused, 'field');
  clean.$el.querySelector = selector => selector.includes('medical-validation-summary') ? { focus() { focused = 'summary'; } } : null;
  clean.focusInvalidField();
  assert.equal(focused, 'summary', 'Errors without an editable field focus the notification');

  admin.veterinarians = [{ user_id: 2, name: 'Maria Santos' }, { user_id: 4, name: 'John Reyes' }];
  admin.vetSearch = 'MARIA';
  assert.deepEqual(Array.from(admin.filteredVeterinarians, row => row.user_id), [2]);
  admin.vetSearch = '';
  const choices = [{ focus() { focused = 0; } }, { focus() { focused = 1; } }];
  admin.$refs.vetDropdown = { querySelectorAll: () => choices };
  admin.vetDropdownKeydown({ key: 'ArrowDown', target: choices[0], preventDefault() {} });
  assert.equal(focused, 1);
  admin.vetDropdownKeydown({ key: 'ArrowDown', target: choices[1], preventDefault() {} });
  assert.equal(focused, 0);
  let toggledByEnter = false, preventedSubmit = false;
  admin.vetDropdownKeydown({ key: 'Enter', target: { type: 'checkbox', click() { toggledByEnter = true; } }, preventDefault() { preventedSubmit = true; } });
  assert.equal(toggledByEnter, true);
  assert.equal(preventedSubmit, true, 'Enter in the picker must select a veterinarian without submitting the medical record');
  preventedSubmit = false;
  admin.vetDropdownKeydown({ key: 'Enter', target: { type: 'search' }, preventDefault() { preventedSubmit = true; } });
  assert.equal(preventedSubmit, true);
  admin.$refs.vetTrigger = { focus() { focused = 'vet-picker'; } };
  const toggle = admin.toggleVeterinarian.bind(admin);
  admin.toggleVeterinarian = id => { toggle(id); delete admin.$refs.vetTrigger; };
  admin.removeVeterinarian(4);
  assert.equal(focused, 'vet-picker', 'Removing a chip retains its focus target before the chip leaves the DOM');
  console.log('Medical Record frontend workflow regression checks passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
