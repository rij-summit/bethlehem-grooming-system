const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const read = (file) => fs.readFileSync(path.join(__dirname, "..", file), "utf8");

function createPage(file, factory, api) {
  let pendingSearch;
  const context = {
    window: {}, console, Date, Intl, InventoryAPI: api,
    ProductForm: { normalizeBarcode: (code) => code },
    setTimeout: (callback) => { pendingSearch = callback; return 1; },
    clearTimeout: () => { pendingSearch = null; },
  };
  vm.runInNewContext(read(file), context);
  return { page: context[factory](), runSearch: () => pendingSearch() };
}

const product = (overrides = {}) => ({
  item_id: 1, item_name: "Pet Shampoo", category: "grooming_supply", unit: "bottle",
  is_active: true, selling_price: "250.00", quantity_on_hand: "8.00",
  unexpired_quantity: 4, saleable_quantity: 4, ...overrides,
});

async function testGroomingSearchAndAdding() {
  const empty = product({ item_id: 2, quantity_on_hand: 0, saleable_quantity: 0 });
  const physicalLimit = product({ item_id: 3, quantity_on_hand: 1, saleable_quantity: 1, unexpired_quantity: 9,
    selling_price: "280.00", reorder_level: 15, low_stock: true });
  const deactivated = product({ item_id: 6, is_active: false });
  const excluded = [
    product({ item_id: 4, category: "medicine" }),
    product({ item_id: 5, category: "vaccine" }),
    product({ item_id: 7, selling_price: null }),
  ];
  const { page, runSearch } = createPage("scripts/components/admin-dashboard.js", "adminDashboard", {
    searchItems: async (...args) => {
      assert.deepEqual(args, ["Pet", true, "grooming"]);
      return { data: [empty, product(), deactivated, ...excluded, physicalLimit] };
    },
  });
  page.paymentModal.open = true;
  page.paymentModal.productQuery = "Pet";
  page.searchPaymentProducts();
  await runSearch();
  assert.deepEqual(Array.from(page.paymentModal.productResults, (item) => item.item_id), [1, 3, 2, 6]);
  assert.equal(page.isEligiblePaymentProduct(empty), true);
  assert.equal(page.hasPaymentProductStock(empty), false);
  for (const item of [empty, deactivated, ...excluded]) page.addPaymentProduct(item);
  assert.equal(page.paymentModal.products.length, 0);
  assert.equal(page.paymentModal.productQuery, "Pet");
  page.addPaymentProduct(product());
  assert.equal(page.paymentModal.products[0].price, 250);
  assert.equal(page.paymentModal.products[0].stock, 4);
  page.addPaymentProduct(physicalLimit);
  assert.equal(page.hasPaymentProductStock(physicalLimit), true);
  assert.equal(page.paymentModal.products[1].price, 280);
  assert.equal(page.paymentModal.products[1].stock, 1);
  page.addPaymentProduct(physicalLimit);
  assert.equal(page.paymentModal.products[1].quantity, 1);
  assert.match(page.paymentModal.productSearchError, /no more available stock/);
}

function testGroomingUnavailableStockLabels() {
  const { page } = createPage("scripts/components/admin-dashboard.js", "adminDashboard", {});
  for (const [item, label] of [
    [product({ quantity_on_hand: 0, saleable_quantity: 0, expired_quantity: 4 }), "Out of stock"],
    [product({ quantity_on_hand: 1, saleable_quantity: 0, expired_quantity: 1, low_stock: true }), "Expired stock"],
    [product({ is_active: false, saleable_quantity: 4 }), "Deactivated"],
    [product({ quantity_on_hand: 1, saleable_quantity: 0, expired_quantity: 0, expiry_unknown_quantity: 1 }), "Unavailable stock"],
    [product({ quantity_on_hand: 1, saleable_quantity: 0, expired_quantity: 0, untracked_quantity: 1 }), "Unavailable stock"],
  ]) {
    assert.equal(page.hasPaymentProductStock(item), false);
    assert.equal(page.paymentProductStockLabel(item), label);
    page.addPaymentProduct(item);
  }
  assert.equal(page.paymentModal.products.length, 0);
}

async function testBarcodeStillChecksStockAndEligibility() {
  for (const item of [product(), product({ saleable_quantity: 0 }), product({ category: "vaccine" }), product({ is_active: false })]) {
    const { page } = createPage("scripts/components/admin-dashboard.js", "adminDashboard", {
      findByBarcode: async (barcode) => {
        assert.equal(barcode, "001234");
        return { data: item };
      },
    });
    page.paymentModal.open = true;
    await page.scanPaymentBarcode("001234");
    const available = item.is_active && item.category !== "vaccine" && item.saleable_quantity > 0;
    assert.equal(page.paymentModal.products.length, available ? 1 : 0);
    if (!available) assert.match(page.paymentModal.productSearchError, /unavailable/);
  }
}

async function testPosUsesSellableStock() {
  const empty = product({ item_id: 2, saleable_quantity: 0, unexpired_quantity: 8 });
  const nonexpiring = product({ item_id: 3, category: "pet_shop", saleable_quantity: "3.00", unexpired_quantity: 0 });
  const { page, runSearch } = createPage("scripts/components/admin-pos.js", "adminPos", {
    searchItems: async () => ({ data: [empty, product(), nonexpiring] }),
    findByBarcode: async () => ({ data: nonexpiring }),
  });
  page.searchQuery = "Pet";
  await page.onSearchInput();
  await runSearch();
  assert.deepEqual(Array.from(page.searchResults, (item) => item.item_id), [1, 3, 2]);
  assert.equal(page.hasProductStock(empty), false);
  assert.equal(page.hasProductStock(nonexpiring), true);
  page.addToCart(empty);
  assert.equal(page.cart.length, 0);
  page.addToCart(product());
  assert.equal(page.cart[0].stock, 4);
  assert.equal(page.cart[0].price, 250);
  await page.scanBarcode("001234");
  assert.equal(page.cart[1].stock, 3);
  assert.equal(page.cart[1].quantity, 1);
  assert.equal(page.cart[1].subtotal, 250);
  for (let i = 0; i < 4; i++) page.addToCart(nonexpiring);
  assert.equal(page.cart[1].quantity, 3);
  assert.equal(page.cart[1].subtotal, 750);
  page.addToCart(product({ item_id: 4, saleable_quantity: 0.5 }));
  assert.equal(page.cart[2].quantity, 0.5);
  assert.equal(page.cart[2].subtotal, 125);
}

function testDisabledResultMarkup() {
  for (const [file, handler, stockCheck] of [
    ["pages/admin/appointments.html", "addPaymentProduct", "hasPaymentProductStock"],
    ["pages/admin/inventory/pos.html", "addToCart", "hasProductStock"],
  ]) {
    const markup = read(file).match(new RegExp(`<button[^>]*@click="${handler}\\(item\\)"[\\s\\S]*?</button>`))[0];
    assert.ok(markup.includes(`:disabled="!${stockCheck}(item)"`));
    assert.ok(markup.includes(`<template x-if="${stockCheck}(item)">`));
    assert.ok(markup.includes(`<template x-if="!${stockCheck}(item)">`));
    if (handler === "addPaymentProduct") {
      assert.match(markup, /text-red-600" x-text="paymentProductStockLabel\(item\)"/);
      const quantityClasses = markup.match(/:class="(item.low_stock[^\"]+)"/)[1];
      assert.match(vm.runInNewContext(quantityClasses, { item: { low_stock: true } }), /text-amber-700/);
      assert.equal(vm.runInNewContext(quantityClasses, { item: { low_stock: false } }), "");
      assert.doesNotMatch(markup, /Low Stock/);
    } else {
      assert.match(markup, /text-red-600">Out of stock/);
    }
    const classes = markup.match(/:class="([^"]+)"/)[1];
    const available = vm.runInNewContext(classes, { [stockCheck]: () => true, item: product() });
    const unavailable = vm.runInNewContext(classes, { [stockCheck]: () => false, item: product() });
    assert.match(available, /hover:/);
    assert.match(unavailable, /cursor-default/);
    assert.doesNotMatch(unavailable, /hover:|cursor-pointer/);
    assert.match(markup, /text-slate-400/);
  }
}

(async () => {
  await testGroomingSearchAndAdding();
  testGroomingUnavailableStockLabels();
  await testBarcodeStillChecksStockAndEligibility();
  await testPosUsesSellableStock();
  testDisabledResultMarkup();
  console.log("Product search regression checks passed.");
})().catch((error) => { console.error(error); process.exitCode = 1; });
