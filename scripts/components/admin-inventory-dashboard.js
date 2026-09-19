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
    recentTransactionsLoading: false,
    topUsed: [],

    // Alert lists
    lowStockItems: [],
    lowStockPage: 1,
    lowStockLastPage: 1,
    lowStockTotal: 0,
    lowStockLoading: false,
    expiryItems: [],
    expiryPage: 1,
    expiryLastPage: 1,
    expiryTotal: 0,
    expiryLoading: false,
    activeTab: "low_stock",  // "low_stock" | "expiry"

    async init() {
      await this.load();
      this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
    },

    async load() {
      this.loading = true;
      this.error   = "";
      try {
        const [summary, lowStock, expiry] = await Promise.all([
          InventoryAPI.getSummary({ page: 1 }),
          InventoryAPI.getLowStock({ page: 1 }),
          InventoryAPI.getExpiryAlerts({ page: 1 }),
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
        this.lowStockPage = lowStock.page ?? 1;
        this.lowStockLastPage = lowStock.last_page ?? 1;
        this.lowStockTotal = lowStock.total ?? lowStock.count ?? this.lowStockItems.length;
        this.expiryItems   = expiry.data   ?? [];
        this.expiryPage = expiry.page ?? 1;
        this.expiryLastPage = expiry.last_page ?? 1;
        this.expiryTotal = expiry.total ?? expiry.count ?? this.expiryItems.length;
      } catch (err) {
        this.error = err.message || "Failed to load dashboard.";
      } finally {
        this.loading = false;
        this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
      }
    },

    async goToLowStockPage(page) {
      if (this.lowStockLoading || page < 1 || page > this.lowStockLastPage || page === this.lowStockPage) return;
      this.lowStockLoading = true;
      this.error = "";
      try {
        const response = await InventoryAPI.getLowStock({ page });
        this.lowStockItems = response.data ?? [];
        this.lowStockPage = response.page ?? page;
        this.lowStockLastPage = response.last_page ?? 1;
        this.lowStockTotal = response.total ?? response.count ?? this.lowStockItems.length;
      } catch (err) {
        this.error = err.message || "Failed to load low stock items.";
      } finally {
        this.lowStockLoading = false;
        this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
      }
    },

    async goToExpiryPage(page) {
      if (this.expiryLoading || page < 1 || page > this.expiryLastPage || page === this.expiryPage) return;
      this.expiryLoading = true;
      this.error = "";
      try {
        const response = await InventoryAPI.getExpiryAlerts({ page });
        this.expiryItems = response.data ?? [];
        this.expiryPage = response.page ?? page;
        this.expiryLastPage = response.last_page ?? 1;
        this.expiryTotal = response.total ?? response.count ?? this.expiryItems.length;
      } catch (err) {
        this.error = err.message || "Failed to load expiry alerts.";
      } finally {
        this.expiryLoading = false;
        this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
      }
    },

    async goToRecentTransactionsPage(page) {
      if (this.recentTransactionsLoading || page < 1 || page > this.recentTransactionsLastPage || page === this.recentTransactionsPage) return;
      this.recentTransactionsLoading = true;
      this.error = "";
      try {
        const response = await InventoryAPI.getTransactions({ page, per_page: 10 });
        this.recentTransactions = response.data ?? [];
        this.recentTransactionsPage = response.page ?? page;
        this.recentTransactionsLastPage = response.last_page ?? 1;
        this.recentTransactionsTotal = response.total ?? this.recentTransactions.length;
      } catch (err) {
        this.error = err.message || "Failed to load recent transactions.";
      } finally {
        this.recentTransactionsLoading = false;
        this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
      }
    },

    paginationPages(currentPage, lastPage) {
      const visiblePages = Math.min(5, lastPage);
      const firstPage = Math.min(
        Math.max(1, currentPage - Math.floor(visiblePages / 2)),
        Math.max(1, lastPage - visiblePages + 1),
      );
      return Array.from({ length: visiblePages }, (_, index) => firstPage + index);
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
