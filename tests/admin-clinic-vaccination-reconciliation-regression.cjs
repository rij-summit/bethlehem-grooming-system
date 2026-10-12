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
  document: { addEventListener() {} },
  crypto: { randomUUID: require("node:crypto").randomUUID },
};
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(path.join(root, "scripts/components/admin-clinic.js"), "utf8"), sandbox);
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

  const medicine = { item_id: 302, item_name: "Medicine", unit: "bottle", dose_unit: "mL",
    strength_concentration: "10 mg/mL", pricing_method: "weight_based", weight_pricing_mode: "ranges",
    weight_prices: [{ min_weight_kg: 1, max_weight_kg: 5, price: 250 }] };
  const treatment = component();
  treatment.pet = { id: 101 };
  treatment.appointment = { id: 201, status: "in_consultation", weight_kg: 4 };
  treatment.currentUserId = 2;
  treatment.openCreateForm();
  treatment.changeTreatmentType("medicine");
  treatment.selectMedicine(medicine);
  assert.equal(treatment.medicineQuote.price, 250);
  assert.equal(treatment.form.dose_unit, "mL");
  treatment.form.dose_amount = 0.5;
  assert.equal(treatment.validateForm(), false, "Stock medicine must require physical quantity");
  treatment.form.inventory_quantity = "0.25";
  assert.equal(treatment.validateForm(), true);
  const medicinePayload = treatment.buildPayload();
  assert.equal(medicinePayload.treatment_type, "medicine");
  assert.equal(medicinePayload.expected_price, 250);
  assert.equal(medicinePayload.expected_weight_kg, 4);
  assert.equal(medicinePayload.inventory_quantity, "0.25");
  assert.equal(treatment.buildPayload().request_key, medicinePayload.request_key, "Retries keep the same request key");
  assert.equal(Object.hasOwn(medicinePayload, "batch_number"), false);
  treatment.loadVaccinations = async () => {};
  treatment.loadOptions = async () => {};
  let retries = [];
  sandbox.API.createAdminPetVaccination = async (petId, payload) => {
    retries.push(payload.request_key);
    if (retries.length === 1) throw new Error("Lost response");
    return { vaccination: { id: 12 } };
  };
  await treatment.saveDraft();
  assert.equal(treatment.formModal.open, true);
  await treatment.saveDraft();
  assert.equal(retries[0], retries[1]);

  sandbox.API.clinicMedicineOptions = async () => ({ data: [medicine] });
  treatment.openEditForm({ ...draft, treatment_type: "medicine", inventory_item_id: 302,
    inventory_quantity: 0.25, vaccine_name: "Medicine", request_key: medicinePayload.request_key });
  await treatment.restoreMedicineSelection();
  assert.equal(treatment.selectedMedicine.item_id, 302);
  assert.equal(treatment.form.inventory_quantity, 0.25, "Restoring selection preserves stock used");
  assert.equal(treatment.form.dose_amount, 1, "Restoring selection preserves clinical dosage");
  assert.equal(treatment.form.request_key, medicinePayload.request_key);
  assert.equal(treatment.validateForm(), true);
  assert.equal(treatment.buildPayload().expected_price, 250);

  // A vaccine confirmation must use vaccine stock policy even if the last form was medicine.
  let confirmation;
  treatment.$store.clinicFeedback.confirm = async details => { confirmation = details; return true; };
  await treatment.confirmDeduction(vaccine, "Publish");
  assert.match(confirmation.message, /^1 dose of Rabies/);
  assert.equal(confirmation.message.includes("medicine charge"), false);

  treatment.recordKind = "historical";
  treatment.changeSource();
  treatment.form.vaccine_name = "External medicine";
  treatment.form.administered_date = "2020-01-01";
  treatment.form.administered_by_name = "External provider";
  assert.equal(treatment.validateForm(), true);
  const externalPayload = treatment.buildPayload();
  assert.equal(externalPayload.inventory_item_id, null);
  assert.equal(Object.hasOwn(externalPayload, "expected_price"), false);
  assert.equal(Object.hasOwn(externalPayload, "inventory_quantity"), false);
  assert.equal(externalPayload.administered_date, "2020-01-01");
  treatment.changeTreatmentType("vaccine");
  assert.equal(treatment.selectedMedicine, null);
  assert.equal(treatment.form.inventory_item_id, "");
  assert.equal(treatment.form.inventory_quantity, "");

  const page = fs.readFileSync(path.join(root, "pages/admin/clinic.html"), "utf8");
  assert.ok(page.includes("No treatments recorded for this pet."));
  assert.ok(!page.includes("Create a draft to begin this patient's"));
  assert.ok(page.includes("Add Treatment"));
  assert.ok(page.includes(':aria-pressed="treatmentType'));

  const css = fs.readFileSync(path.join(root, "css/output.css"), "utf8");
  for (const layer of [200, 210, 230, 240, 250, 270]) {
    const selector = `.z-\\[${layer}\\] {`;
    const offset = css.indexOf(selector);
    assert.ok(offset >= 0, `Compiled CSS must include ${selector}`);
    assert.ok(css.slice(offset, css.indexOf("}", offset)).includes(`z-index: ${layer}`));
  }
  console.log("Clinic treatment reconciliation regression checks passed");
})().catch((error) => { console.error(error); process.exitCode = 1; });
