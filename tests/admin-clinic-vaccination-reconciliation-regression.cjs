const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const root = path.resolve(__dirname, "..");
const source = fs.readFileSync(path.join(root, "scripts/components/admin-vaccinations.js"), "utf8");
const sandbox = {
  window: { addEventListener() {}, dispatchEvent() {}, lucide: null },
  CustomEvent: class { constructor(type) { this.type = type; } },
  clinicLocalDate: (date) => date.toISOString().slice(0, 10),
  setTimeout, clearTimeout, URLSearchParams,
  API: {},
};
vm.createContext(sandbox);
vm.runInContext(source, sandbox);

function component() {
  const instance = sandbox.adminClinicVaccinations();
  instance.$nextTick = (callback) => callback();
  instance.$refs = {};
  instance.$store = { clinicAccess: { permissions: { clinical: true } }, clinicFeedback: { confirm: async () => true, notify() {} } };
  return instance;
}

const vaccine = {
  item_id: 301, item_name: "Rabies", unit: "dose", deduction_supported: true,
  available_quantity: 3, next_batch: { batch_number: "EARLY", expiry_date: "2027-12-01" },
};
const draft = {
  id: 7, state: "draft", inventory_item_id: 301, vaccine_name: "Rabies",
  administered_date: sandbox.vaccinationToday(), dose_amount: 1, dose_unit: "mL",
  administered_by_user_id: 2, clinic_appointment_id: 201,
};

(async () => {
  let resolveOptions;
  sandbox.API.getAdminVaccinationOptions = () => new Promise((resolve) => { resolveOptions = resolve; });
  const loading = component();
  const pending = loading.loadOptions();
  loading.openEditForm(draft);
  assert.equal(loading.form.inventory_item_id, "301");
  assert.equal(loading.formModal.unavailableVaccineName, "");
  resolveOptions({ vaccines: [vaccine], veterinarians: [], current_user_id: 2 });
  await pending;
  assert.equal(loading.selectedVaccine.item_id, 301);
  assert.equal(loading.formModal.unavailableVaccineName, "");
  assert.equal(loading.validateForm(), true);
  const payload = loading.buildPayload();
  for (const field of ["administered_date", "vaccine_name", "manufacturer", "batch_number", "product_expiry_date", "administered_by_name"]) {
    assert.equal(Object.hasOwn(payload, field), false, `Inventory payload must leave ${field} to the server`);
  }
  assert.equal(loading.vaccineSelectable({ ...vaccine, available_quantity: 0 }), false);
  assert.equal(loading.vaccineSelectable({ ...vaccine, next_batch: null }), false);
  assert.equal(loading.vaccineSelectable({ ...vaccine, deduction_supported: false, unit: "mL" }), false);
  assert.equal(loading.vaccineAvailabilityLabel({ ...vaccine, deduction_supported: false }), "Single-dose unit required");

  const historical = component();
  historical.openEditForm({ ...draft, administered_date: "2020-01-01", manufacturer: "Original", batch_number: "OLD", administered_by_user_id: null, administered_by_name: "External vet" });
  assert.equal(historical.recordKind, "historical");
  historical.form.next_due_date = "2020-02-01";
  historical.form.dose_amount = "";
  historical.form.dose_unit = "";
  assert.equal(historical.validateForm(), true);
  const historicalPayload = historical.buildPayload();
  assert.equal(historicalPayload.inventory_item_id, null);
  assert.equal(historicalPayload.administered_date, "2020-01-01");
  assert.equal(historicalPayload.manufacturer, "Original");
  assert.equal(historicalPayload.batch_number, "OLD");
  assert.equal(historicalPayload.administered_by_name, "External vet");

  let publications = [];
  sandbox.API.createAdminPetVaccination = async () => ({ vaccination: { id: 8 } });
  sandbox.API.publishAdminPetVaccination = async (...args) => { publications.push(args); return { message: "Published" }; };
  sandbox.API.stockOut = () => { throw new Error("Clinic must not perform a separate stock-out request"); };
  const current = component();
  current.pet = { id: 101 };
  current.appointment = { id: 201, status: "in_consultation" };
  current.vaccineOptions = [vaccine];
  current.openEditForm(draft);
  current.loadVaccinations = async () => {};
  current.loadOptions = async () => {};
  sandbox.API.updateAdminPetVaccination = async () => ({ vaccination: { id: draft.id } });
  await current.saveAndFinishCase();
  assert.equal(publications.length, 1);
  assert.equal(publications[0][2].finishCase, true);
  assert.equal(publications[0][2].consumeInventory, true);

  publications = [];
  historical.pet = { id: 101 };
  historical.loadVaccinations = async () => {};
  historical.loadOptions = async () => {};
  await historical.publishRecord({ ...draft, inventory_item_id: null, administered_date: "2020-01-01", administered_by_user_id: null, administered_by_name: "External vet", dose_amount: null, dose_unit: null });
  assert.equal(publications.length, 1);
  assert.equal(publications[0][2].consumeInventory, false);

  const css = fs.readFileSync(path.join(root, "css/output.css"), "utf8");
  for (const layer of [200, 210, 230, 240, 250, 270]) {
    const selector = `.z-\\[${layer}\\] {`;
    const offset = css.indexOf(selector);
    assert.ok(offset >= 0, `Compiled CSS must include ${selector}`);
    assert.ok(css.slice(offset, css.indexOf("}", offset)).includes(`z-index: ${layer}`));
  }
  console.log("Clinic vaccination reconciliation regression checks passed");
})().catch((error) => { console.error(error); process.exitCode = 1; });
