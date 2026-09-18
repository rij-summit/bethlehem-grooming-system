const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const projectRoot = path.resolve(__dirname, "..");
const source = fs.readFileSync(
  path.join(projectRoot, "scripts/components/admin-inventory-items.js"),
  "utf8",
);

function createPage(inventoryApi = {}) {
  const context = {
    InventoryAPI: inventoryApi,
    clearTimeout,
    setTimeout,
    window: {},
  };

  vm.createContext(context);
  vm.runInContext(source, context, {
    filename: "scripts/components/admin-inventory-items.js",
  });

  const page = context.adminInventoryItems();
  page.$nextTick = (callback) => callback();
  return page;
}

function validForm(overrides = {}) {
  return {
    item_id: null,
    item_name: "Validated product",
    barcode: "0012345678901",
    category: "medicine",
    unit: "piece",
    description: "",
    unit_cost: "1.25",
    selling_price: "2.50",
    reorder_level: "0",
    ...overrides,
  };
}

async function testClientRejectsInvalidProductFormValuesWithoutSubmitting() {
  const invalidForms = [
    [validForm({ barcode: "01234567890123" }), /Barcode must contain 1 to 13 digits/],
    [validForm({ unit_cost: "" }), /Unit cost is required/],
    [validForm({ selling_price: "0" }), /Selling price is required/],
    [validForm({ reorder_level: "1.5" }), /Minimum stock is required/],
    [validForm({ reorder_level: "" }), /Minimum stock is required/],
  ];

  for (const [form, expectedError] of invalidForms) {
    let submitted = false;
    const page = createPage({
      createItem: async () => { submitted = true; },
    });
    page.form = form;

    await page.submitForm();

    assert.equal(submitted, false);
    assert.match(page.modal.error, expectedError);
  }
}

function testBarcodeInputKeepsOnlyThirteenDigits() {
  const page = createPage();
  const input = { value: "00ab12345678901234" };

  page.handleBarcodeInput({ target: input });

  assert.equal(page.form.barcode, "0012345678901");
  assert.equal(input.value, "0012345678901");
}

async function testEditingAnIntegerMinimumStockStoredAsDecimalCanBeSavedUnchanged() {
  const updates = [];
  const page = createPage({
    updateItem: async (_id, payload) => { updates.push(payload); },
    getItems: async () => ({ data: [], page: 1, last_page: 1, total: 0 }),
  });

  page.openEdit({
    item_id: 7,
    item_name: "Stored decimal minimum stock",
    barcode: "0012345678901",
    category: "medicine",
    unit: "piece",
    description: null,
    unit_cost: "1.25",
    selling_price: "2.50",
    reorder_level: "5.00",
  });

  await page.submitForm();

  assert.equal(page.form.reorder_level, "5");
  assert.equal(updates[0].reorder_level, 5);
}

async function testClientPreservesLeadingZeroBarcodeAndReloadsTheLastValidPage() {
  const payloads = [];
  const requestedPages = [];
  const page = createPage({
    createItem: async (payload) => { payloads.push(payload); },
    getItems: async ({ page: requestedPage }) => {
      requestedPages.push(requestedPage);
      if (requestedPage === 2) {
        return { data: [], page: 2, last_page: 1, total: 15 };
      }
      return { data: [{ item_id: 1 }], page: 1, last_page: 1, total: 15 };
    },
  });

  page.form = {
    item_id: null,
    item_name: "Leading zero barcode",
    barcode: "0012345678901",
    category: "medicine",
    unit: "piece",
    description: "",
    unit_cost: "1.25",
    selling_price: "2.50",
    reorder_level: "0",
  };

  await page.submitForm();
  await page.load(2);

  assert.equal(payloads[0].barcode, "0012345678901");
  assert.deepEqual(requestedPages, [1, 2, 1]);
  assert.equal(page.currentPage, 1);
  assert.equal(page.total, 15);
}

async function testPageRecoveryKeepsLoadingUntilTheFallbackResponseArrives() {
  let requestCount = 0;
  let resolveFallback;
  const page = createPage({
    getItems: async () => {
      requestCount += 1;
      if (requestCount === 1) {
        return { data: [], page: 2, last_page: 1, total: 15 };
      }
      return new Promise((resolve) => { resolveFallback = resolve; });
    },
  });

  const loadPromise = page.load(2);
  await new Promise((resolve) => setImmediate(resolve));

  assert.equal(requestCount, 2);
  assert.equal(page.loading, true);

  resolveFallback({ data: [{ item_id: 1 }], page: 1, last_page: 1, total: 15 });
  await loadPromise;

  assert.equal(page.loading, false);
}

(async () => {
  await testClientRejectsInvalidProductFormValuesWithoutSubmitting();
  testBarcodeInputKeepsOnlyThirteenDigits();
  await testEditingAnIntegerMinimumStockStoredAsDecimalCanBeSavedUnchanged();
  await testClientPreservesLeadingZeroBarcodeAndReloadsTheLastValidPage();
  await testPageRecoveryKeepsLoadingUntilTheFallbackResponseArrives();
  console.log("frontend inventory item regression checks passed");
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
