const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const projectRoot = path.resolve(__dirname, "..");
const source = fs.readFileSync(
  path.join(projectRoot, "scripts/components/admin-stock-in.js"),
  "utf8",
);
const inventoryItemsSource = fs.readFileSync(
  path.join(projectRoot, "scripts/components/admin-inventory-items.js"),
  "utf8",
);
const productFormSource = fs.readFileSync(
  path.join(projectRoot, "scripts/components/product-form.js"),
  "utf8",
);
const stockInPage = fs.readFileSync(
  path.join(projectRoot, "pages/admin/inventory/stock-in.html"),
  "utf8",
);

function createPage(inventoryApi = {}, toastMessages = []) {
  const context = {
    InventoryAPI: inventoryApi,
    clearTimeout,
    setTimeout,
    window: {
      showSuccessToast: (message) => toastMessages.push(message),
    },
  };

  vm.createContext(context);
  vm.runInContext(productFormSource, context, {
    filename: "scripts/components/product-form.js",
  });
  context.ProductForm = context.window.ProductForm;
  vm.runInContext(source, context, {
    filename: "scripts/components/admin-stock-in.js",
  });

  const page = context.adminStockIn();
  page.$nextTick = (callback) => callback();
  page.toastMessages = toastMessages;
  return page;
}

function pendingItem() {
  return {
    item_id: 1,
    item_name: "Canine Vaccine",
    unit: "vial",
    category: "vaccine",
    quantity: 2,
    unit_cost: 100,
    reason: "purchase",
    batch_number: "BATCH-001",
    expiry_date: "2030-01-01",
    notes: null,
  };
}

function testClientRejectsDecimalStockInQuantity() {
  const page = createPage();
  page.selected = {
    item_id: 1,
    item_name: "Canine Vaccine",
    unit: "vial",
    category: "vaccine",
  };
  page.qty = "1.5";
  page.expiryDate = page.today;

  page.addToPending();

  assert.equal(page.pending.length, 0);
  assert.match(page.entryError, /whole number/i);
}

function testExpiryDateIsRequired() {
  const page = createPage();
  page.selected = {
    item_id: 1,
    item_name: "Canine Vaccine",
    unit: "vial",
    category: "vaccine",
  };
  page.qty = "1";

  page.addToPending();

  assert.equal(page.pending.length, 0);
  assert.equal(page.entryError, "Expiry date is required.");
}

function testPetShopAndMiscellaneousDoNotRequireAnExpiryDate() {
  for (const category of ["pet_shop", "miscellaneous"]) {
    const page = createPage();
    page.selected = {
      item_id: 1,
      item_name: "Non-expiring product",
      unit: "piece",
      category,
    };
    page.qty = "1";

    page.addToPending();

    assert.equal(page.entryError, "");
    assert.equal(page.pending.length, 1);
    assert.equal(page.pending[0].expiry_date, null);
  }
}

function testAllowsSameProductWithDifferentExpiryDates() {
  const page = createPage();
  page.selected = {
    item_id: 1,
    item_name: "Canine Vaccine",
    unit: "vial",
    category: "vaccine",
  };
  page.pending = [pendingItem()];
  page.qty = "3";
  page.batchNumber = "BATCH-002";
  page.expiryDate = "2031-01-01";

  page.addToPending();

  assert.equal(page.entryError, "");
  assert.equal(page.pending.length, 2);
  assert.equal(page.pending[1].expiry_date, "2031-01-01");
}

function testSearchResultsDisplayWholeNumberQuantity() {
  assert.match(
    stockInPage,
    /x-text="Math\.trunc\(Number\(item\.quantity_on_hand \?\? 0\)\)"/,
  );
}

function testNewProductBarcodeScannerUsesTheSharedScanner() {
  assert.match(stockInPage, /@click="openCreateBarcodeScanner\(\)"/);
  assert.match(stockInPage, /title="Scan with camera"/);
  assert.match(source, /ProductForm\.openBarcodeScanner\(this, "stockin-qr-reader"/);
  assert.match(inventoryItemsSource, /ProductForm\.openBarcodeScanner\(this, "inv-qr-reader"/);

  const page = createPage();
  page.createForm.barcode = "";
  page._scanTarget = "create-form";
  page.onScanned("0012345678901");

  assert.equal(page.createForm.barcode, "0012345678901");
}

function testDeactivatedSearchResultIsRenderedAsAnInertNotice() {
  assert.match(stockInPage, /<template x-if="item\.is_active">/);
  assert.match(stockInPage, /<template x-if="!item\.is_active">/);
  assert.match(stockInPage, /This product is deactivated and cannot receive stock\./);
  assert.match(stockInPage, />DEACTIVATED<\/span>/);
}

async function testNewProductUnitOptionsAndCustomUnit() {
  assert.match(stockInPage, /<select x-model="createForm\.unit" required/);
  assert.match(stockInPage, /x-for="unitOption in ProductForm\.unitOptions"/);
  assert.match(stockInPage, /x-show="createForm\.unit === 'other'"/);

  const createCalls = [];
  const page = createPage({
    createItem: async (payload) => {
      createCalls.push(payload);
      return { data: { item_id: 2, item_name: payload.item_name, unit: payload.unit } };
    },
  });
  page.createForm.item_name = "Custom Supply";
  page.createForm.category = "miscellaneous";
  page.createForm.unit = "other";
  page.createForm.unit_cost = "1.00";
  page.createForm.selling_price = "2.00";
  page.createForm.reorder_level = "0";
  await page.createAndSelect();
  assert.equal(page.createError, "Custom unit is required.");
  assert.equal(createCalls.length, 0);

  page.createForm.customUnit = "Roll";
  await page.createAndSelect();
  assert.equal(createCalls[0].unit, "Roll");
}

async function testNewProductUsesSharedValidationMessages() {
  const page = createPage({ createItem: async () => { throw new Error("must not submit"); } });
  page.createForm = {
    item_name: "Validated product",
    barcode: "0012345678901",
    category: "medicine",
    unit: "pc",
    customUnit: "",
    description: "",
    unit_cost: "1.25",
    selling_price: "0",
    reorder_level: "0",
  };

  await page.createAndSelect();

  assert.equal(page.createError, "Selling price is required and must be at least ₱0.01 with no more than 2 decimal places.");
}

async function testSuccessfulStockInUsesSupplierFreePayloadAndShowsToast() {
  const stockInCalls = [];
  const page = createPage({
    stockIn: async function () {
      stockInCalls.push([...arguments]);
    },
  });
  page.pending = [pendingItem()];

  await page.submit();

  assert.equal(stockInCalls.length, 1);
  assert.equal(stockInCalls[0].length, 1);
  assert.equal(JSON.stringify(stockInCalls[0][0]), JSON.stringify([{
    item_id: 1,
    quantity: 2,
    reason: "purchase",
    unit_cost: 100,
    batch_number: "BATCH-001",
    expiry_date: "2030-01-01",
    notes: null,
  }]));
  assert.equal(page.pending.length, 0);
  assert.deepEqual(page.toastMessages, ["Stock-in recorded successfully."]);
}

async function testFailedStockInDoesNotShowSuccessToast() {
  const page = createPage({
    stockIn: async () => {
      throw new Error("Stock In failed");
    },
  });
  page.pending = [pendingItem()];

  await page.submit();

  assert.deepEqual(page.toastMessages, []);
  assert.equal(page.error, "Stock In failed");
}

(async () => {
  testClientRejectsDecimalStockInQuantity();
  testExpiryDateIsRequired();
  testPetShopAndMiscellaneousDoNotRequireAnExpiryDate();
  testAllowsSameProductWithDifferentExpiryDates();
  testSearchResultsDisplayWholeNumberQuantity();
  testNewProductBarcodeScannerUsesTheSharedScanner();
  testDeactivatedSearchResultIsRenderedAsAnInertNotice();
  await testNewProductUnitOptionsAndCustomUnit();
  await testNewProductUsesSharedValidationMessages();
  await testSuccessfulStockInUsesSupplierFreePayloadAndShowsToast();
  await testFailedStockInDoesNotShowSuccessToast();
  console.log("frontend stock-in regression checks passed");
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
