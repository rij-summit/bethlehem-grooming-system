const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");
const { randomUUID } = require("node:crypto");
const context = { window: {}, document: { addEventListener() {} }, setTimeout, clearTimeout, crypto: { randomUUID }, API: {} };
vm.createContext(context);
vm.runInContext(fs.readFileSync("scripts/components/product-form.js", "utf8"), context);
vm.runInContext(fs.readFileSync("scripts/components/admin-clinic.js", "utf8"), context, {
  filename: require("node:path").resolve("scripts/components/admin-clinic.js"),
  importModuleDynamically: vm.constants.USE_MAIN_CONTEXT_DEFAULT_LOADER,
});
const pf = context.window.ProductForm;
const ranges = [
  { min_weight_kg: "1", max_weight_kg: "5", price: "200" },
  { min_weight_kg: "5.1", max_weight_kg: "10", price: "400" },
  { min_weight_kg: "10.1", max_weight_kg: "15", price: "700" },
  { min_weight_kg: "15.1", max_weight_kg: "50", price: "900" },
];
const form = pf.empty({ item_name: "Medicine", category: "medicine", medicine_type: "deworming",
  unit: "bottle", strength_concentration: "10 mg/mL", unit_cost: "50", reorder_level: "0", weight_prices: ranges.map(range => ({...range, band: "normal"})) });
assert.equal(form.pricing_method, "weight_based");
assert.equal(pf.validate(form), "");
for (const option of pf.medicineTypeOptions) {
  form.medicine_type = option.value;
  assert.equal(pf.validate(form), "");
  assert.equal(pf.payload(form).medicine_type, option.value);
}
const payload = pf.payload(form);
assert.equal(payload.strength_concentration, "10 mg/mL");
assert.equal(pf.clinicFields({category: "medicine", strength_concentration: "50 mg/tablet"}).strength_concentration, "50 mg/tablet");
assert.equal(pf.clinicFields({category: "vaccine", strength_concentration: ">4.6 log10 PFU"}).strength_concentration, "");
form.strength_concentration = "x".repeat(151);
assert.match(pf.validate(form), /Product strength/);
form.strength_concentration = "";
assert.equal(pf.validate(form), "");
assert.equal(pf.payload(form).strength_concentration, null);
form.strength_concentration = "10 mg/mL";
assert.equal(payload.selling_price, null);
assert.deepEqual(Array.from(payload.weight_prices, r => Number(r.min_weight_kg)), [1, 5.1, 10.1, 15.1]);
assert.equal(payload.weight_prices[3].max_weight_kg, "50");
const invalid = [[], [{min_weight_kg: -1, max_weight_kg: 5, price: 1}],
  [{min_weight_kg: 5, max_weight_kg: 5, price: 1}], [{min_weight_kg: 5, max_weight_kg: 4, price: 1}],
  [{min_weight_kg: 0, max_weight_kg: 5, price: 0}], [{min_weight_kg: 0, max_weight_kg: 5, price: "1.001"}],
  [{min_weight_kg: 0, max_weight_kg: 5, price: "abc"}],
  [{min_weight_kg: 0, max_weight_kg: 5, price: 1}, {min_weight_kg: 0, max_weight_kg: 5, price: 1}],
  [{min_weight_kg: 0, max_weight_kg: "", price: 1}, {min_weight_kg: 6, max_weight_kg: 10, price: 1}]];
for (const value of invalid) assert.notEqual(pf.validateRanges(value), "");
form.category = "vaccine"; form.selling_price = "950";
assert.equal(pf.validate(form), "");
assert.equal(pf.isWeightBased(form), false);
assert.equal(pf.payload(form).medicine_type, null);
assert.equal(pf.payload(form).pricing_method, "flat");
assert.equal(pf.payload(form).weight_prices.length, 0);
assert.equal(pf.payload(form).strength_concentration, null);
form.strength_concentration = "x".repeat(151);
assert.equal(pf.validate(form), ""); // Hidden medicine strength cannot block a vaccine save.
form.strength_concentration = "10 mg/mL";
assert.equal(form.weight_prices.length, 4); // Draft was not destroyed by a temporary switch.
form.category = "medicine";
assert.equal(pf.payload(form).strength_concentration, "10 mg/mL"); // A temporary category switch preserves the draft.
assert.equal(pf.payload(form).weight_prices.length, 4);
form.pricing_method = "flat";
assert.equal(pf.payload(form).selling_price, 950);
assert.equal(pf.payload(form).weight_prices.length, 0);
form.pricing_method = "weight_based";
assert.equal(pf.payload(form).weight_prices.length, 4);
assert.equal(Object.hasOwn(pf.payload(form), "dose_unit"), false);
assert.equal(Object.hasOwn(pf.clinicFields({dose_unit: "drop"}), "dose_unit"), false);
assert.ok(pf.unitOptions.some(option => option.value === "pipette" && option.label === "Pipette"));
assert.equal(pf.unitFields("pipette").unit, "pipette");
for (const category of ["medicine", "vaccine"]) {
  const product = pf.empty({item_name: "Spot-on", category, medicine_type: "general_medication",
    unit: "pipette", pricing_method: "flat", unit_cost: "35.00", selling_price: "75.00", reorder_level: "0"});
  assert.equal(pf.validate(product), "");
  assert.equal(pf.payload(product).unit, "pipette");
  assert.equal(Object.hasOwn(pf.payload(product), "dose_unit"), false);
  product.unit = "";
  assert.equal(pf.validate(product), "Unit is required.");
}
for (const category of ["food", "grooming_supply", "pet_shop", "miscellaneous"]) {
  form.category = category;
  assert.equal(pf.validate(form), "");
  assert.equal(Object.hasOwn(pf.payload(form), "dose_unit"), false);
  assert.equal(pf.payload(form).strength_concentration, null);
  assert.equal(pf.payload(form).weight_prices.length, 0);
}
const item = {item_id: 1, item_name: "Medicine", unit: "bottle", pricing_method: "weight_based", weight_prices: payload.weight_prices, weight_pricing_mode: "catalog", selling_price: null};
for (const [weight, price] of [[1,200], [5,200], [5.1,400], [10,400], [10.1,700], [12.4,700], [15,700], [41,900]])
  assert.equal(context.clinicMedicinePrice(item, weight).price, price);
for (const weight of [null, "", 0, -1, NaN]) assert.match(context.clinicMedicinePrice(item, weight).error, /Record or update/);
for (const [weight,price] of [[5.05,200],[10.05,400],[15.05,700]]) assert.equal(context.clinicMedicinePrice(item, weight).price, price);
for (const weight of [0.5, 50.01]) assert.match(context.clinicMedicinePrice(item, weight).error, /No price is configured/);
assert.equal(context.clinicMedicinePrice({pricing_method:"flat",selling_price:"300"}, null).price, 300);
const modal = context.adminClinicModal();
modal.$store = {clinicAccess: {permissions: {clinical: true}}};
modal.currentAppt = {status: "in_consultation"}; modal.form.weight_kg = "12.4";
modal.selectMedicine(item);
assert.equal(modal.inventoryQuantity, "");
modal.addMedicine();
assert.equal(modal.pendingMedicines[0].price, 700);
assert.equal(modal.pendingMedicines[0].inventory_quantity, null);
modal.form.weight_kg = "5";
(async () => {
  await modal.saveRecord();
  assert.match(modal.modalError, /Pet weight changed/);
  modal.pendingMedicines = []; modal.form.weight_kg = ""; modal.selectMedicine(item); modal.addMedicine();
  assert.equal(modal.pendingMedicines.length, 0);
  modal.form.weight_kg = "12.4"; modal.inventoryQuantity = "0.25"; modal.addMedicine();
  assert.equal(modal.pendingMedicines[0].inventory_quantity, 0.25);
  for (const file of ["pages/admin/inventory/sections/products.html", "pages/admin/inventory/sections/stock-in.html"]) {
    const html = fs.readFileSync(file, "utf8");
    assert.match(html, /Inventory Unit/);
    assert.doesNotMatch(html, /x-model="(?:createForm|form)\.(?:dose_unit|customDoseUnit)"/);
    assert.match(html, /ProductForm.isWeightBased/); assert.match(html, /Medicine Type/);
  }
  console.log("Clinic inventory pricing: form transitions, ranges, weight lookup, and staff-entered stock use passed.");
})().catch(error => { console.error(error); process.exitCode = 1; });
