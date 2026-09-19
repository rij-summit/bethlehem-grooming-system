const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const projectRoot = path.resolve(__dirname, "..");
const source = fs.readFileSync(
  path.join(projectRoot, "scripts/components/admin-inventory-dashboard.js"),
  "utf8",
);

function createPage(inventoryApi) {
  const context = {
    InventoryAPI: inventoryApi,
    window: {},
  };

  vm.createContext(context);
  vm.runInContext(source, context, {
    filename: "scripts/components/admin-inventory-dashboard.js",
  });

  const page = context.adminInventoryDashboard();
  page.$nextTick = (callback) => callback();
  return page;
}

function paginated(data, page, lastPage, total) {
  return { data, page, last_page: lastPage, total, count: total };
}

async function testEachDashboardTableLoadsItsPageIndependently() {
  const calls = [];
  const page = createPage({
    getSummary: async () => {
      calls.push(["summary"]);
      return {
        total_items: 42,
        low_stock_count: 12,
        expiry_alert_count: 13,
        recent_transactions: [{ transaction_id: 1 }],
        recent_transactions_page: 1,
        recent_transactions_last_page: 2,
        recent_transactions_total: 11,
        top_used_30_days: [{ item_id: 99 }],
      };
    },
    getLowStock: async ({ page: requestedPage }) => {
      calls.push(["low-stock", requestedPage]);
      return paginated([{ item_id: requestedPage }], requestedPage, 2, 12);
    },
    getExpiryAlerts: async ({ page: requestedPage }) => {
      calls.push(["expiry", requestedPage]);
      return paginated([{ item_id: requestedPage + 10 }], requestedPage, 2, 13);
    },
    getTransactions: async (options) => {
      calls.push(["transactions", options.page, options.per_page]);
      return paginated([{ transaction_id: options.page }], options.page, 2, 11);
    },
  });

  await page.load();
  assert.deepEqual(calls, [["summary"], ["low-stock", 1], ["expiry", 1]]);

  await page.goToLowStockPage(2);
  assert.deepEqual(calls.at(-1), ["low-stock", 2]);
  assert.equal(page.lowStockPage, 2);
  assert.equal(page.expiryPage, 1);
  assert.equal(page.recentTransactionsPage, 1);

  await page.goToExpiryPage(2);
  assert.deepEqual(calls.at(-1), ["expiry", 2]);
  assert.equal(page.lowStockPage, 2);
  assert.equal(page.expiryPage, 2);
  assert.equal(page.recentTransactionsPage, 1);

  await page.goToRecentTransactionsPage(2);
  assert.deepEqual(calls.at(-1), ["transactions", 2, 10]);
  assert.equal(page.lowStockPage, 2);
  assert.equal(page.expiryPage, 2);
  assert.equal(page.recentTransactionsPage, 2);

  assert.equal(calls.filter(([name]) => name === "summary").length, 1);
  assert.equal(page.totalItems, 42);
  assert.equal(page.lowStockCount, 12);
  assert.equal(page.expiryCount, 13);
  assert.equal(page.topUsed.length, 1);
}

function testPaginationPageNumbersStaySmallAndIncludeTheCurrentPage() {
  const page = createPage({});

  assert.deepEqual(Array.from(page.paginationPages(1, 8)), [1, 2, 3, 4, 5]);
  assert.deepEqual(Array.from(page.paginationPages(4, 8)), [2, 3, 4, 5, 6]);
  assert.deepEqual(Array.from(page.paginationPages(8, 8)), [4, 5, 6, 7, 8]);
}

(async () => {
  await testEachDashboardTableLoadsItsPageIndependently();
  testPaginationPageNumbersStaySmallAndIncludeTheCurrentPage();
  console.log("frontend inventory dashboard regression checks passed");
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
