const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const context = { window: {}, document: { addEventListener() {} } };
vm.createContext(context);
vm.runInContext(fs.readFileSync('scripts/components/product-form.js', 'utf8'), context);
vm.runInContext(fs.readFileSync('scripts/components/admin-clinic.js', 'utf8'), context);
const pf = context.window.ProductForm;
const fixtures = JSON.parse(fs.readFileSync('tests/Fixtures/weight-rules.json'));
for (const example of Object.values(fixtures.pricing)) {
  const form = pf.empty({ item_name: 'Deworming', category: 'medicine', medicine_type: 'deworming', unit: 'bottle', dose_unit: 'mL', unit_cost: '73.25', reorder_level: '0',
    weight_prices: example.tiers.map(([min_weight_kg, , price]) => ({ min_weight_kg, price })), weight_limit: example.tiers.at(-1)[1] ?? '' });
  assert.equal(pf.validate(form), '');
  const payload = pf.payload(form);
  assert.equal(payload.unit_cost, 73.25);
  assert.equal(payload.selling_price, null);
  assert.equal(payload.weight_pricing_mode, 'threshold');
  assert.equal(pf.validateRanges(payload.weight_prices), '');
  const edit = { ...form, ...pf.clinicFields(payload) };
  assert.equal(pf.validate(edit), '');
  assert.deepEqual(JSON.parse(JSON.stringify(pf.payload(edit))), JSON.parse(JSON.stringify(payload)));
  for (const [weight, price] of example.examples) {
    const quote = context.clinicMedicinePrice(payload, weight);
    if (price === null) assert.ok(quote.error);
    else assert.equal(quote.price, price);
  }
}
const catForm = pf.empty({ weight_prices: [{ min_weight_kg: 0 }, { min_weight_kg: 2 }, { min_weight_kg: 4 }, { min_weight_kg: 6 }], weight_limit: 10 });
assert.deepEqual(Array.from(catForm.weight_prices, (_, index) => pf.tierLabel(catForm, index)), ['Below 2 kg', '2 kg to below 4 kg', '4 kg to below 6 kg', '6 kg to 10 kg']);
const tier = (min, max, price = 50) => ({ min_weight_kg: min, max_weight_kg: max, price });
for (const invalid of [[], [tier(1, null)], [tier(0, 0)], [tier(0, -1)], [tier(0, 5, 0)], [tier(0, 5, -1)], [tier(0, 5, 'abc')], [tier(0, 5, '1.001')],
  [tier(0, 5), tier(5, 4)], [tier(0, 5), tier(5, 10), tier(5, null)], [tier(0, 5), tier(4, null)], [tier(0, 5), tier(6, null)],
  [tier(0, 6), tier(6, 5), tier(5, null)], [tier(0, null), tier(5, null)]]) assert.ok(pf.validateRanges(invalid));
assert.equal(pf.validateRanges([tier(0, 2.25), tier(2.25, 4.5), tier(4.5, null)]), '');
assert.equal(pf.validateRanges([tier(0, 10)]), '');
assert.equal(pf.validateRanges([tier(0, null)]), '');
const legacy = [tier(1, 5), tier(5.1, null, 75)];
const edit = pf.empty({ ...pf.clinicFields({ category: 'medicine', pricing_method: 'weight_based', weight_prices: legacy }) });
edit.category = 'medicine';
assert.deepEqual(JSON.parse(JSON.stringify(pf.payload(edit).weight_prices)), legacy);
pf.refineTiers(edit);
assert.equal(edit.legacyWeightPrices, null);
assert.equal(pf.tierRanges(edit)[0].max_weight_kg, 5.1);
assert.equal(pf.tierRanges(edit)[0].min_weight_kg, 0);
pf.removeRange(edit, 0);
assert.equal(edit.weight_prices[0].min_weight_kg, 0);
for (const file of ['pages/admin/inventory/sections/products.html', 'pages/admin/inventory/sections/stock-in.html']) {
  const html = fs.readFileSync(file, 'utf8');
  assert.match(html, /Unit Cost/);
  assert.match(html, /Starting weight \(kg\)/);
  assert.match(html, /Final maximum weight \(kg\)/);
  assert.doesNotMatch(html, /Bounds are inclusive|From \(kg\)|To \(kg\)|Dog Pricing|Cat Pricing|applicable_species/);
}
console.log('Product threshold tiers, boundary lookup, Add/Edit round trips, legacy preservation, labels and Unit Cost passed.');
