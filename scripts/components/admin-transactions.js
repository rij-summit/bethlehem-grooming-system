function adminTransactions() {
  const d = new Date();
  const currentDate = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
  const currentMonth = currentDate.slice(0, 7);
  const currentYear = currentDate.slice(0, 4);

  return {
    transactions: [],
    totalCount: 0,
    searchQuery: "",
    transactionPeriod: "day",
    filterDate: "",
    filterMonth: "",
    filterYear: "",
    loading: false,
    errorMessage: "",
    selectedTransaction: null,
    receiptModal: null,
    detailsTrigger: null,
    previousBodyOverflow: "",

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
          period: this.transactionPeriod,
          date:   this.transactionPeriod === "day" ? this.filterDate : "",
          month:  this.transactionPeriod === "month" ? this.filterMonth : "",
          year:   this.transactionPeriod === "year" ? this.filterYear : "",
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

    onPeriodChange() {
      if (this.transactionPeriod === "day" && !this.filterDate) {
        this.filterDate = currentDate;
      }

      if (this.transactionPeriod === "month" && !this.filterMonth) {
        this.filterMonth = currentMonth;
      }

      if (this.transactionPeriod === "year" && !this.filterYear) {
        this.filterYear = currentYear;
      }

      this.loadTransactions();
    },

    onPeriodValueChange() {
      this.loadTransactions();
    },

    clearFilters() {
      this.searchQuery = "";
      this.transactionPeriod = "day";
      this.filterDate = "";
      this.filterMonth = "";
      this.filterYear = "";
      this.loadTransactions();
    },

    get hasActiveFilters() {
      return (
        this.searchQuery ||
        this.transactionPeriod !== "day" ||
        this.filterDate ||
        this.filterMonth ||
        this.filterYear
      );
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

    get collectionTotal() {
      return this.transactions.reduce((sum, tx) => sum + tx.finalPrice, 0);
    },

    get collectionCount() {
      return this.transactions.length;
    },

    get hasSelectedPeriodValue() {
      if (this.transactionPeriod === "month") return Boolean(this.filterMonth);
      if (this.transactionPeriod === "year") return Boolean(this.filterYear);
      return Boolean(this.filterDate);
    },

    get collectionTitle() {
      if (this.transactionPeriod === "month") {
        return this.filterMonth ? `${this.formatMonth(this.filterMonth)} Collection` : "Monthly Collection";
      }

      if (this.transactionPeriod === "year") {
        return this.filterYear ? `${this.filterYear} Collection` : "Yearly Collection";
      }

      return this.filterDate ? `${this.formatShortDate(this.filterDate)} Collection` : "Collection";
    },

    petNames(tx) {
      const names = (tx.paymentSummary?.pets || []).map(pet => pet.pet_name).filter(Boolean);
      return names.length ? names : (tx.petName || "").split(",").map(name => name.trim()).filter(Boolean);
    },

    compactPetNames(tx) {
      const names = this.petNames(tx);
      return names.length > 3 ? `${names.slice(0, 2).join(", ")} +${names.length - 2} more` : names.join(", ") || "—";
    },

    openDetails(tx, trigger) {
      this.selectedTransaction = tx;
      // Build the invoice once from the transaction being inspected.
      this.receiptModal = {
        bookingReference: tx.reference,
        ownerName: tx.ownerName,
        pets: (tx.paymentSummary?.pets || []).map((pet, index) => ({
          id: pet.booking_pet_id ?? index,
          name: pet.pet_name,
          species: pet.pet_species,
          lines: (pet.service_breakdown || []).map((line, lineIndex) => ({
            id: line.booking_service_id ?? lineIndex,
            name: line.label,
            price: line.price_at_booking,
          })),
          subtotal: pet.final_pet_charge ?? pet.original_pet_subtotal,
        })),
        products: tx.productAddons || [],
        groomingSubtotal: tx.groomingServicesTotal,
        productsSubtotal: tx.productAddonsTotal,
        finalPrice: tx.finalPrice,
        amountPaid: tx.amountPaid,
        change: tx.changeGiven,
        paymentMethod: tx.paymentMethodLabel || tx.paymentMethod,
        notes: tx.notes,
        paidAt: tx.paidAt,
      };
      this.detailsTrigger = trigger;
      this.previousBodyOverflow = document.body.style.overflow;
      document.body.style.overflow = "hidden";
      this.$nextTick(() => {
        this.$refs.detailsDialog.showModal();
        this.$refs.detailsBody.scrollTop = 0;
      });
    },

    closeDetails() {
      this.$refs.detailsDialog.close();
      document.body.style.overflow = this.previousBodyOverflow;
      this.selectedTransaction = null;
      this.receiptModal = null;
      this.detailsTrigger?.focus();
      this.detailsTrigger = null;
    },

    destroy() {
      if (this.selectedTransaction) document.body.style.overflow = this.previousBodyOverflow;
    },

    formatTransactionDate(tx) {
      return `${this.formatShortDate(tx.dateKey)} · ${this.formatTime(tx.paidAt)}`;
    },

    formatInvoicePeso(amount) {
      return window.PaymentInvoice.formatPeso(amount);
    },

    formatInvoiceDate(value) {
      return window.PaymentInvoice.formatDate(value);
    },

    formatInvoiceProductUnitPrice(line) {
      return window.PaymentInvoice.formatProductUnitPrice(line);
    },

    printInvoice() {
      if (this.selectedTransaction) window.PaymentInvoice.print();
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

    formatMonth(monthStr) {
      if (!monthStr) return "All dates";

      try {
        return new Intl.DateTimeFormat("en-PH", {
          year: "numeric",
          month: "short",
        }).format(new Date(monthStr + "-01T00:00:00"));
      } catch {
        return monthStr;
      }
    },

    formatShortDate(dateStr) {
      if (!dateStr) return "All dates";

      try {
        return new Intl.DateTimeFormat("en-PH", {
          month: "short",
          day: "numeric",
          year: "numeric",
        }).format(new Date(dateStr + "T00:00:00"));
      } catch {
        return dateStr;
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
