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
    findings: 'Legacy exam', diagnosis: 'Legacy diagnosis',
  } } });
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(vet.form.primary_veterinarian_id, '1');
  assert.equal(vet.form.additional_veterinarian_ids.length, 0);
  assert.equal(vet.form.physical_exam_findings, 'Legacy exam');
  assert.equal(vet.form.working_diagnosis, 'Legacy diagnosis');
  assert.equal(optionsCalls[0], true, 'Medical Record requests provider options without vaccine inventory');
  vet.addDiagnostic();
  vet.form.diagnostics[0].diagnostic = 'CBC';
  assert.equal(vet.form.diagnostics[0].status, 'Ordered');
  await vet.saveRecord();
  assert.equal(saves[0].diagnostics[0].result_attachment_id, null);
  assert.equal(Object.hasOwn(saves[0], 'medicines'), false);
  assert.equal(Object.hasOwn(saves[0], 'recorded_by_user_id'), false);
  assert.equal(saves[0].finish_case, false);

  vet.open = true;
  vet.attachments = [{ id: 10, file_name: 'CBC.pdf' }, { id: 11, file_name: 'General.pdf' }];
  vet.form.diagnostics[0].result_attachment_id = '10';
  assert.equal(vet.generalAttachments.length, 1);
  assert.equal(vet.generalAttachments[0].id, 11);
  vet.form.temperature_c = 38.5;
  vet.form.heart_rate_bpm = 80;
  vet.form.respiratory_rate_bpm = 20;
  await vet.saveRecord(true);
  assert.equal(saves.length, 1, 'Missing completion vitals keep the record open');
  assert.ok(vet.fieldError('weight_kg'));
  assert.equal(vet.open, true);
  vet.form.weight_kg = 24;
  sandbox.API.clinicSaveRecord = async () => { throw { errors: { 'diagnostics.0.custom_name': ['Enter a test name.'] } }; };
  await vet.saveRecord();
  assert.equal(vet.fieldError('diagnostics.0.custom_name'), 'Enter a test name.');
  assert.equal(vet.form.diagnostics.length, 1, 'Validation preserves unsaved clinical rows');
  assert.equal(vet.open, true);

  sandbox.API.getAdminVaccinationOptions = async () => ({ veterinarians: [{ user_id: 2, name: 'Vet' }], current_user_id: 3, current_user_is_veterinarian: false });
  const admin = component({ user_id: 3, role: 'admin' });
  await admin.openModal({ appt: { id: 101, status: 'in_consultation', ownerName: 'Owner' } });
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(admin.form.primary_veterinarian_id, '', 'Administrators must choose an attending veterinarian');
  assert.equal(admin.isVeterinarian, false);
  console.log('Medical Record frontend workflow regression checks passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
