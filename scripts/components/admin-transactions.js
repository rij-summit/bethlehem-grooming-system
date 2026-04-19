function adminTransactions() {
  const currentDate = (() => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
  })();

  return {
    transactions: [],
    totalCount: 0,
    searchQuery: "",
    filterDate: "",
    loading: false,
    errorMessage: "",

    async init() {
      await this.loadTransactions();
      this.refreshIcons();
    },

    async loadTransactions() {
      this.loading      = true;
      this.errorMessage = "";

      try {
        const data = await API.getTransactions({
          search: this.searchQuery.trim(),
          date:   this.filterDate,
        });
        this.transactions = data.transactions || [];
        this.totalCount   = data.total ?? this.transactions.length;
      } catch (error) {
        this.errorMessage = error.message || "Failed to load transactions. Please try again.";
        this.transactions = [];
        this.totalCount   = 0;
      } finally {
        this.loading = false;
        this.refreshIcons();
      }
    },

    onSearchChange() {
      this.loadTransactions();
    },

    onDateChange() {
      this.loadTransactions();
    },

    clearFilters() {
      this.searchQuery = "";
      this.filterDate  = "";
      this.loadTransactions();
    },

    get hasActiveFilters() {
      return this.searchQuery || this.filterDate;
    },

    // Groups transactions: today first, then earlier dates
    get groupedTransactions() {
      const groups = {};
      for (const tx of this.transactions) {
        const key = tx.dateKey || "unknown";
        if (!groups[key]) groups[key] = [];
        groups[key].push(tx);
      }

      const todayKey = currentDate;

      const sortedKeys = Object.keys(groups).sort((a, b) => b.localeCompare(a));

      return sortedKeys.map(dateKey => ({
        dateKey,
        isToday: dateKey === todayKey,
        label:   this.formatGroupDate(dateKey),
        items:   groups[dateKey],
        total:   groups[dateKey].reduce((sum, tx) => sum + tx.finalPrice, 0),
      }));
    },

    get todayTotal() {
      const todayItems = this.transactions.filter(tx => tx.dateKey === currentDate);
      return todayItems.reduce((sum, tx) => sum + tx.finalPrice, 0);
    },

    get todayCount() {
      return this.transactions.filter(tx => tx.dateKey === currentDate).length;
    },

    formatGroupDate(dateStr) {
      if (!dateStr || dateStr === "unknown") return "Unknown Date";
      try {
        const date = new Date(dateStr + "T00:00:00");
        return new Intl.DateTimeFormat("en-PH", {
          weekday: "long",
          year:    "numeric",
          month:   "long",
          day:     "numeric",
        }).format(date);
      } catch {
        return dateStr;
      }
    },

    formatTime(paidAt) {
      if (!paidAt) return "—";
      try {
        return new Intl.DateTimeFormat("en-PH", {
          hour:   "numeric",
          minute: "2-digit",
          hour12: true,
        }).format(new Date(paidAt));
      } catch {
        return paidAt;
      }
    },

    formatPeso(amount) {
      return "₱" + Number(amount).toFixed(2);
    },

    refreshIcons() {
      this.$nextTick(() => {
        if (window.lucide) window.lucide.createIcons();
      });
    },
  };
}
