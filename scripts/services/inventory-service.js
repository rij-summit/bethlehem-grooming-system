// inventory-service.js — HTTP layer for all inventory and supplier endpoints.
// Loaded as a plain <script> before Alpine. Exposes global InventoryAPI.

var InventoryAPI = (() => {
  const TOKEN_KEY = "admin_token";

  function getBaseUrl() {
    const sharedBase = typeof API !== "undefined" && typeof API.getBaseUrl === "function"
      ? API.getBaseUrl()
      : "/api";

    return String(sharedBase || "/api").replace(/\/+$/, "");
  }

  function getToken() {
    if (typeof API !== "undefined" && typeof API.getAdminToken === "function") {
      return API.getAdminToken();
    }

    return localStorage.getItem(TOKEN_KEY);
  }

  async function request(method, endpoint, body = null) {
    const token = getToken();
    const headers = {
      "Content-Type": "application/json",
      Accept: "application/json",
    };
    if (token) headers.Authorization = `Bearer ${token}`;

    const options = { method, headers };
    if (body !== null) options.body = JSON.stringify(body);

    let response;
    try {
      response = await fetch(`${getBaseUrl()}${endpoint}`, options);
    } catch {
      const err = new Error("Unable to reach the server. Check your connection.");
      err.status = 0;
      throw err;
    }

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
      const code = data.code || null;
      const responseBelongsToCurrentSession = !!token
        && typeof API !== "undefined"
        && typeof API.getAdminToken === "function"
        && API.getAdminToken() === token;
      const shouldInvalidateSession = responseBelongsToCurrentSession && (
        response.status === 401 ||
        (response.status === 403 && ["account_disabled", "email_not_verified"].includes(code))
      );

      if (shouldInvalidateSession && typeof API !== "undefined" && typeof API.invalidateSession === "function") {
        API.invalidateSession("admin", code || "expired");
      }

      const err = new Error(data.message || "Something went wrong.");
      err.status  = response.status;
      err.errors  = data.errors || null;
      err.code    = code;
      throw err;
    }

    return data;
  }

  // ── Items ─────────────────────────────────────────────────────────────────

  function getItems({ q = "", category = "", low_stock = false, include_inactive = false, page = 1 } = {}) {
    const p = new URLSearchParams({ page });
    if (q)                p.set("q", q);
    if (category)         p.set("category", category);
    if (low_stock)        p.set("low_stock", "1");
    if (include_inactive) p.set("include_inactive", "1");
    return request("GET", `/inventory/items?${p}`);
  }

  function getItem(id) {
    return request("GET", `/inventory/items/${id}`);
  }

  function createItem(payload) {
    return request("POST", "/inventory/items", payload);
  }

  function updateItem(id, payload) {
    return request("PUT", `/inventory/items/${id}`, payload);
  }

  function deactivateItem(id) {
    return request("POST", `/inventory/items/${id}/deactivate`);
  }

  function reactivateItem(id) {
    return request("POST", `/inventory/items/${id}/reactivate`);
  }

  // ── Barcode & search ──────────────────────────────────────────────────────

  function findByBarcode(barcode) {
    return request("GET", `/inventory/barcode/${encodeURIComponent(barcode)}`);
  }

  function searchItems(q) {
    return request("GET", `/inventory/search?q=${encodeURIComponent(q)}`);
  }

  // ── Stock movements ───────────────────────────────────────────────────────

  function stockIn(items, supplierId = null) {
    // items: [{ item_id, quantity, reason, unit_cost?, batch_number?, expiry_date?, notes? }]
    return request("POST", "/inventory/stock-in", {
      items,
      supplier_id: supplierId,
    });
  }

  function stockOut(items) {
    return request("POST", "/inventory/stock-out", { items });
  }

  // ── Alerts & history ──────────────────────────────────────────────────────

  function getLowStock() {
    return request("GET", "/inventory/low-stock");
  }

  function getExpiryAlerts() {
    return request("GET", "/inventory/alerts/expiry");
  }

  function getAlertBadge() {
    return request("GET", "/inventory/alerts/badge");
  }

  function getTransactions({ item_id = "", type = "", category = "", date_from = "", date_to = "", page = 1 } = {}) {
    const p = new URLSearchParams({ page });
    if (item_id)   p.set("item_id", item_id);
    if (type)      p.set("type", type);
    if (category)  p.set("category", category);
    if (date_from) p.set("date_from", date_from);
    if (date_to)   p.set("date_to", date_to);
    return request("GET", `/inventory/transactions?${p}`);
  }

  function getSummary() {
    return request("GET", "/inventory/summary");
  }

  // ── Suppliers ─────────────────────────────────────────────────────────────

  function getSuppliers({ q = "", paginate = false, page = 1 } = {}) {
    const p = new URLSearchParams();
    if (q)       p.set("q", q);
    if (paginate) { p.set("paginate", "1"); p.set("page", page); }
    return request("GET", `/inventory/suppliers?${p}`);
  }

  function createSupplier(payload) {
    return request("POST", "/inventory/suppliers", payload);
  }

  function updateSupplier(id, payload) {
    return request("PUT", `/inventory/suppliers/${id}`, payload);
  }

  function deactivateSupplier(id) {
    return request("POST", `/inventory/suppliers/${id}/deactivate`);
  }

  // ── POS ───────────────────────────────────────────────────────────────────

  function processSale(payload) {
    return request("POST", "/pos/transactions", payload);
  }

  function getReceipt(posId) {
    return request("GET", `/pos/transactions/${posId}`);
  }

  function getPosTransactions({ cashier_id = "", date_from = "", date_to = "", page = 1 } = {}) {
    const p = new URLSearchParams({ page });
    if (cashier_id) p.set("cashier_id", cashier_id);
    if (date_from)  p.set("date_from", date_from);
    if (date_to)    p.set("date_to", date_to);
    return request("GET", `/pos/transactions?${p}`);
  }

  return {
    // items
    getItems, getItem, createItem, updateItem, deactivateItem, reactivateItem,
    // barcode
    findByBarcode, searchItems,
    // stock
    stockIn, stockOut,
    // alerts + history
    getLowStock, getExpiryAlerts, getAlertBadge, getTransactions, getSummary,
    // suppliers
    getSuppliers, createSupplier, updateSupplier, deactivateSupplier,
    // pos
    processSale, getReceipt, getPosTransactions,
  };
})();
