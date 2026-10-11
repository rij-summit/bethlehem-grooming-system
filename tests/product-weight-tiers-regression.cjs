const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const context = { window: {}, document: { addEventListener() {} } };
vm.createContext(context);
for (const name of ['product-form', 'admin-clinic', 'admin-inventory-items', 'admin-stock-in'])
  vm.runInContext(fs.readFileSync(`scripts/components/${name}.js`, 'utf8'), context);
const pf = context.ProductForm = context.window.ProductForm;
const fixtures = JSON.parse(fs.readFileSync('tests/Fixtures/weight-rules.json'));
const clean = value => JSON.parse(JSON.stringify(value));
const range = (min_weight_kg, max_weight_kg, price = 50) => ({ min_weight_kg, max_weight_kg, price });
const validForm = ranges => pf.empty({ item_name: 'Clinic medicine', category: 'medicine', medicine_type: 'deworming', unit: 'bottle', dose_unit: 'mL', unit_cost: '73.25', reorder_level: '0',
  ...pf.clinicFields({category:'medicine', medicine_type:'deworming', dose_unit:'mL', pricing_method:'weight_based', weight_pricing_mode:'threshold', weight_prices:ranges}) });
const detailsExpression = fs.readFileSync('pages/admin/inventory/sections/products.html', 'utf8').match(/x-text="(ProductForm.rangeLabel[^"]+)"/)[1];
for (const example of Object.values(fixtures.pricing)) {
  const form = validForm(example.ranges.map(values => range(...values)));
  assert.equal(pf.validate(form), '');
  const payload = pf.payload(form);
  assert.equal(payload.unit_cost, 73.25);
  assert.equal(payload.selling_price, null);
  assert.equal(payload.weight_pricing_mode, 'threshold');
  assert.equal(pf.validateRanges(payload.weight_prices), '');
  const edit = {...form, ...pf.clinicFields(payload)};
  assert.equal(pf.validate(edit), '');
  assert.deepEqual(clean(pf.payload(edit)), clean(payload));
  for (const [weight, price] of example.examples) {
    const quote = context.clinicMedicinePrice(payload, weight);
    if (price === null) assert.match(quote.error, /No price is configured/);
    else assert.equal(quote.price, price);
  }
  assert.deepEqual(Array.from(payload.weight_prices, (_, index) => pf.scheduleLabel(form, index)), example.labels);
  for (let index = 0; index < payload.weight_prices.length; index++) {
    context.details = {item:payload}; context.range = payload.weight_prices[index]; context.index = index;
    assert.equal(vm.runInContext(detailsExpression, context), example.labels[index]);
  }
}
for (const invalid of [[], [range(0, null)], [range(-1, 5)], [range(1, 0)], [range(1, 1)], [range(5, 4)],
  [range(1, 5, 0)], [range(1, 5, -1)], [range(1, 5, 'abc')], [range(1, 5, '1.001')],
  [range(1, 5), range(4, null)], [range(1, 5), range(1, null)], [range(6, 10), range(1, 5)],
  [range('1.000', 5)], [range(1, '5.000000')], [range('1e1', 20)], [range(1, null), range(5, null)]])
  assert.ok(pf.validateRanges(invalid));
assert.equal(pf.validateRanges([range(0, 1), range(1, 5), range(5, null)]), '');
assert.equal(pf.validateRanges([range('0.50', '4.21'), range('4.21', '10.')]), '');
assert.equal(pf.empty().final_range, 'capped');
assert.equal(pf.clinicFields({category:'medicine',weight_prices:[]}).final_range, 'capped');
const draft = validForm([range('1.200000', '5.555', '75')]);
assert.ok(pf.validate(draft));
assert.equal(pf.payload(draft).weight_prices[0].min_weight_kg, '1.200000');
assert.equal(pf.payload(draft).weight_prices[0].max_weight_kg, '5.555');
const schedule = validForm([range('1.0', '5.0', '250')]);
pf.addBelowRange(schedule);
assert.equal(schedule.weight_prices[0].band, 'below');
assert.equal(schedule.weight_prices[0].min_weight_kg, '1.0');
schedule.weight_prices[0].price = '200';
schedule.weight_prices[1].min_weight_kg = '1.20'; pf.syncBelowStart(schedule, 1);
assert.equal(schedule.weight_prices[0].min_weight_kg, '1.20');
schedule.weight_prices[0].min_weight_kg = '1.200000'; pf.syncBelowStart(schedule, 0);
assert.equal(schedule.weight_prices[1].min_weight_kg, '1.200000');
assert.ok(pf.validate(schedule));
schedule.weight_prices[0].min_weight_kg = '1.0'; pf.syncBelowStart(schedule, 0);
pf.addRange(schedule);
schedule.weight_prices[2].min_weight_kg = '5'; schedule.weight_prices[2].price = '300';
schedule.final_range = 'open';
assert.equal(pf.validate(schedule), '');
assert.equal(pf.scheduleLabel(schedule, 1), '1 to below 5 kg');
assert.equal(pf.scheduleLabel(schedule, 2), '5 kg and above');
pf.removeRange(schedule, 1);
assert.equal(schedule.weight_prices[0].min_weight_kg, '5'); // Below follows the new first Starts row.
pf.removeRange(schedule, 0);
assert.equal(schedule.weight_prices[0].min_weight_kg, '5'); // Removing Below never changes the Starts weight.
const belowOnly = pf.empty(); pf.addBelowRange(belowOnly);
belowOnly.weight_prices[0].min_weight_kg = '1'; belowOnly.weight_prices[0].price = '200';
assert.equal(pf.validateRanges(pf.scheduleRanges(belowOnly)), '');
pf.addRange(belowOnly);
assert.equal(belowOnly.weight_prices[1].min_weight_kg, '1');
const historical = {category:'medicine', medicine_type:'deworming', dose_unit:'mL', pricing_method:'weight_based', weight_pricing_mode:'ranges', weight_prices:[range(1,5,1750),range(5.1,6,1900)]};
assert.match(context.clinicMedicinePrice(historical, 5.05).error, /No price is configured/);
const converted = validForm([]); Object.assign(converted, pf.clinicFields(historical));
assert.equal(converted.final_range, 'capped'); assert.equal(converted.weight_limit, 6);
assert.equal(context.clinicMedicinePrice(pf.payload(converted), 5.05).price, 1750);
assert.match(context.clinicMedicinePrice(pf.payload(converted), 6.01).error, /No price is configured/);
for (const file of ['pages/admin/inventory/sections/products.html', 'pages/admin/inventory/sections/stock-in.html']) {
  const html = fs.readFileSync(file, 'utf8');
  for (const text of ['Unit Cost','Weight-based Pricing','Weight band (kg)','Price (₱)','Add weight range','Add Below range','Capped','And above']) assert.ok(html.includes(text));
  assert.match(html, /aria-describedby="(?:form|createForm)-weight-schedule-error"/);
  assert.match(html, /type="text" inputmode="decimal"/);
  assert.doesNotMatch(html, /From \(kg\)|To \(kg\)|Remove tier|Lowest weight tier|Price tier [0-9]|Dog Pricing|Cat Pricing|applicable_species/);
}
(async () => {
  let created, updated;
  context.InventoryAPI = {
    createItem: async payload => {created = clean(payload); return {data:{...payload,item_id:1}};},
    updateItem: async (id, payload) => {assert.equal(id,1); updated=clean(payload);return {data:payload};},
  };
  const page = context.adminInventoryItems();
  page.$nextTick = () => {}; page.load = async () => {}; page.showToast = () => {};
  page.openAdd(); Object.assign(page.form, validForm([range('0','1.0','200'), range('1.0','5.0','250'), range('5.0',null,'300')]));
  await page.submitForm();
  assert.equal(page.modal.open, false);
  assert.equal(created.weight_prices[0].min_weight_kg, '0');
  assert.equal(created.weight_prices[0].max_weight_kg, '1.0');
  assert.equal(created.weight_prices[2].max_weight_kg, null);
  page.openEdit({...created,item_id:1,unit_cost:'73.25',reorder_level:'0.00'});
  page.form.final_range = 'capped'; page.form.weight_limit = '50.00';
  await page.submitForm();
  assert.equal(updated.weight_prices[2].max_weight_kg, '50.00');
  assert.equal(updated.unit_cost,73.25);
  page.openEdit({...created,item_id:1,reorder_level:'0'});
  page.form.weight_prices[2].min_weight_kg='5.000'; updated=null;
  await page.submitForm();
  assert.equal(updated,null); assert.equal(page.form.weight_prices[2].min_weight_kg,'5.000'); assert.ok(page.modal.error);
  const stock=context.adminStockIn(); stock.$nextTick=()=>{};
  stock.createForm=validForm([range('1','5','250'),range('5',null,'300')]);
  await stock.createAndSelect();
  assert.equal(stock.selected.weight_prices[1].max_weight_kg,null);
  assert.equal(stock.selected.unit_cost,73.25); assert.equal(stock.unitCost,73.25);
  console.log('Threshold schedules: decimal lookup, Below, open/capped finals, Add/Edit/Stock In, Product Details and Unit Cost passed.');
})().catch(error=>{console.error(error);process.exitCode=1;});
