function adminInventoryDashboard() {
  return {
    loading: true,
    error: "",

    // Summary data
    totalItems: 0,
    lowStockCount: 0,
    expiryCount: 0,
    recentTransactions: [],
    recentTransactionsPage: 1,
    recentTransactionsLastPage: 1,
    recentTransactionsTotal: 0,
    topUsed: [],

    // Alert lists
    lowStockItems: [],
    expiryItems: [],
    activeTab: "low_stock",  // "low_stock" | "expiry"

    async init() {
      await this.load();
      this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
    },

    async load(page = 1) {
      this.loading = true;
      this.error   = "";
      try {
        const [summary, lowStock, expiry] = await Promise.all([
          InventoryAPI.getSummary({ page }),
          InventoryAPI.getLowStock(),
          InventoryAPI.getExpiryAlerts(),
        ]);

        this.totalItems          = summary.total_items;
        this.lowStockCount       = summary.low_stock_count;
        this.expiryCount         = summary.expiry_alert_count;
        this.recentTransactions  = summary.recent_transactions ?? [];
        this.recentTransactionsPage = summary.recent_transactions_page ?? 1;
        this.recentTransactionsLastPage = summary.recent_transactions_last_page ?? 1;
        this.recentTransactionsTotal = summary.recent_transactions_total ?? this.recentTransactions.length;
        this.topUsed             = summary.top_used_30_days ?? [];

        this.lowStockItems = lowStock.data ?? [];
        this.expiryItems   = expiry.data   ?? [];
      } catch (err) {
        this.error = err.message || "Failed to load dashboard.";
      } finally {
        this.loading = false;
        this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
      }
    },

    prevRecentTransactionsPage() {
      if (this.recentTransactionsPage > 1) this.load(this.recentTransactionsPage - 1);
    },

    nextRecentTransactionsPage() {
      if (this.recentTransactionsPage < this.recentTransactionsLastPage) this.load(this.recentTransactionsPage + 1);
    },

    // ── Helpers ──────────────────────────────────────────────────────────────

    expiryStyle(item) {
      if (item.is_expired)               return "background-color:#fee2e2;color:#b91c1c";
      if (item.days_until_expiry <= 7)   return "background-color:#fee2e2;color:#b91c1c";
      if (item.days_until_expiry <= 14)  return "background-color:#fef3c7;color:#92400e";
      return "background-color:#fef9c3;color:#a16207";
    },

    expiryLabel(item) {
      if (item.is_expired)   return "Expired";
      if (item.days_until_expiry === 0) return "Expires today";
      return `${item.days_until_expiry}d left`;
    },

    typeStyle(type) {
      return type === "stock_in"
        ? "background-color:#dcfce7;color:#166534"
        : "background-color:#fee2e2;color:#b91c1c";
    },

    fmtDate(d) {
      if (!d) return "—";
      return new Date(d).toLocaleString("en-PH", { dateStyle: "medium", timeStyle: "short" });
    },

    fmtQty(n) {
      const v = parseFloat(n ?? 0);
      return v % 1 === 0 ? v.toLocaleString() : v.toFixed(2);
    },
  };
}
