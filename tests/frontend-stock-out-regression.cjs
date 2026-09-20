const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const projectRoot = path.resolve(__dirname, "..");
const source = fs.readFileSync(
  path.join(projectRoot, "scripts/components/admin-stock-out.js"),
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
  vm.runInContext(source, context, {
    filename: "scripts/components/admin-stock-out.js",
  });

  const page = context.adminStockOut();
  page.$nextTick = (callback) => callback();
  page.$el = { querySelector: () => null };
  page.toastMessages = toastMessages;
  return page;
}

function product() {
  return {
    item_id: 1,
    item_name: "Dog Food",
    unit: "pack",
    category: "food",
    quantity_on_hand: "13.00",
    selling_price: "100.00",
  };
}

function testPendingStockOutReducesDisplayedAndValidatedAvailability() {
  const page = createPage();
  const item = product();

  page.pickItem(item);
  assert.equal(page.availableForReason(), 13);

  page.qty = "10";
  page.addToPending();
  assert.equal(page.pending.length, 1);
  assert.equal(page.pending[0].quantity, 10);

  page.pickItem(item);
  assert.equal(page.availableForReason(), 3);

  page.qty = "4";
  page.addToPending();
  assert.equal(page.pending.length, 1);
  assert.equal(page.entryError, "Insufficient stock. Please check available inventory.");

  page.qty = "3";
  page.addToPending();
  assert.equal(page.pending.length, 1);
  assert.equal(page.pending[0].quantity, 13);

  page.pickItem(item);
  assert.equal(page.availableForReason(), 0);
}

async function testSuccessfulStockOutShowsReusableSuccessToast() {
  const page = createPage({ stockOut: async () => {} });
  page.pending = [{ item_id: 1, quantity: 2, reason: "used", selling_price: null, notes: null }];

  await page.submit();

  assert.deepEqual(page.toastMessages, ["Stock-out recorded for 1 item(s)."]);
  assert.equal(page.pending.length, 0);
}

(async () => {
  testPendingStockOutReducesDisplayedAndValidatedAvailability();
  await testSuccessfulStockOutShowsReusableSuccessToast();
  console.log("frontend stock-out regression checks passed");
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
