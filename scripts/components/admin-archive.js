function adminArchive() {
  const currentYear = new Date().getFullYear().toString();

  return {
    archivedList: [],
    totalCount: 0,
    searchQuery: "",
    sortOrder: "newest",
    selectedYear: currentYear,   // defaults to current year
    selectedMonth: "",           // "YYYY-MM" or "" — resets when year changes
    loading: false,
    errorMessage: "",
    detailsModalOpen: false,
    detailsBooking: null,

    async init() {
      await this.loadArchive();
      this.refreshIcons();
    },

    async loadArchive() {
      this.loading      = true;
      this.errorMessage = "";

      try {
        const data = await API.getArchivedBookings({
          search: this.searchQuery.trim(),
        });
        this.archivedList = data.archived || [];
        this.totalCount   = data.total ?? this.archivedList.length;
      } catch (error) {
        this.errorMessage = error.message || "Failed to load archive. Please try again.";
        this.archivedList = [];
        this.totalCount   = 0;
      } finally {
        this.loading = false;
        this.refreshIcons();
      }
    },

    onSearchChange() {
      this.loadArchive();
    },

    onYearChange() {
      this.selectedMonth = ""; // reset month when year switches
    },

    clearFilters() {
      this.searchQuery  = "";
      this.sortOrder    = "newest";
      this.selectedYear  = currentYear;
      this.selectedMonth = "";
      this.loadArchive();
    },

    // ── Client-side derived state ─────────────────────────

    // Years that have at least one record
    get availableYears() {
      const seen = new Set();
      for (const b of this.archivedList) {
        if (b.appointmentDate) seen.add(b.appointmentDate.slice(0, 4));
      }
      return [...seen].sort().reverse();
    },

    // Months within the selected year that have records
    get availableMonths() {
      const seen = new Set();
      for (const b of this.archivedList) {
        if (b.appointmentDate && b.appointmentDate.startsWith(this.selectedYear)) {
          seen.add(b.appointmentDate.slice(0, 7));
        }
      }
      return [...seen]
        .sort()
        .reverse()
        .map(m => ({
          value: m,
          label: new Intl.DateTimeFormat("en-PH", { month: "long" }).format(
            new Date(m + "-01T00:00:00"),
          ),
        }));
    },

    get filteredList() {
      let list = this.archivedList;

      // Year filter (always active — defaults to current year)
      if (this.selectedYear) {
        list = list.filter(b => (b.appointmentDate || "").startsWith(this.selectedYear));
      }

      // Month filter (optional)
      if (this.selectedMonth) {
        list = list.filter(b => (b.appointmentDate || "").startsWith(this.selectedMonth));
      }

      return [...list].sort((a, b) => {
        switch (this.sortOrder) {
          case "az":      return (a.ownerName || "").localeCompare(b.ownerName || "");
          case "za":      return (b.ownerName || "").localeCompare(a.ownerName || "");
          case "oldest":  return (a.appointmentDate || "").localeCompare(b.appointmentDate || "");
          default:        return (b.appointmentDate || "").localeCompare(a.appointmentDate || "");
        }
      });
    },

    get groupedByDate() {
      const groups = {};
      for (const booking of this.filteredList) {
        const date = booking.appointmentDate || "unknown";
        if (!groups[date]) groups[date] = [];
        groups[date].push(booking);
      }

      const dateKeys = Object.keys(groups).sort((a, b) =>
        this.sortOrder === "oldest" ? a.localeCompare(b) : b.localeCompare(a),
      );

      return dateKeys.map(date => ({
        date,
        label: this.formatGroupDate(date),
        bookings: groups[date],
      }));
    },

    get filteredCount() {
      return this.filteredList.length;
    },

    get hasActiveFilters() {
      return (
        this.searchQuery ||
        this.selectedMonth ||
        this.sortOrder !== "newest" ||
        this.selectedYear !== currentYear
      );
    },

    // ── Actions ───────────────────────────────────────────

    viewDetails(booking) {
      this.detailsBooking   = booking;
      this.detailsModalOpen = true;
      this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
    },

    closeDetails() {
      this.detailsModalOpen = false;
      this.detailsBooking   = null;
    },

    // ── Formatting helpers ────────────────────────────────

    formatGroupDate(dateStr) {
      if (!dateStr || dateStr === "unknown") return "Unknown Date";
      try {
        return new Intl.DateTimeFormat("en-PH", {
          weekday: "long",
          year:    "numeric",
          month:   "long",
          day:     "numeric",
        }).format(new Date(dateStr + "T00:00:00"));
      } catch {
        return dateStr;
      }
    },

    formatAppointmentDate(dateStr) {
      if (!dateStr) return "—";
      try {
        return new Intl.DateTimeFormat("en-PH", {
          weekday: "short",
          year:    "numeric",
          month:   "short",
          day:     "numeric",
        }).format(new Date(dateStr + "T00:00:00"));
      } catch {
        return dateStr;
      }
    },

    formatMobileNumber(value) {
      const text = String(value ?? "").trim();
      if (!text || text === "—") return "—";

      const digits = text.replace(/\D/g, "");
      if (digits.length === 11) {
        return `${digits.slice(0, 4)}-${digits.slice(4, 7)}-${digits.slice(7)}`;
      }

      return text;
    },

    formatPeso(amount) {
      return `\u20b1${Number(amount || 0).toFixed(2)}`;
    },

    getServiceAvailedAmount(service) {
      const amount = Number(
        service?.paidPrice ??
        service?.paid_price ??
        service?.finalPrice ??
        service?.final_price,
      );

      return Number.isFinite(amount) && amount > 0 ? amount : null;
    },

    formatServiceAvailedPrice(service) {
      const amount = this.getServiceAvailedAmount(service);

      return amount ? this.formatPeso(amount) : "Price unavailable";
    },

    getServicesAvailedTotal(booking = this.detailsBooking) {
      const paymentTotal = Number(
        booking?.paidAmount ??
        booking?.paid_amount ??
        booking?.payment?.finalPrice ??
        booking?.payment?.final_price,
      );

      if (Number.isFinite(paymentTotal) && paymentTotal > 0) {
        return paymentTotal;
      }

      const services = Array.isArray(booking?.services) ? booking.services : [];
      const serviceAmounts = services
        .map((service) => this.getServiceAvailedAmount(service))
        .filter((amount) => Number.isFinite(amount) && amount > 0);

      return serviceAmounts.length > 0
        ? serviceAmounts.reduce((sum, amount) => sum + amount, 0)
        : null;
    },

    formatServicesAvailedTotal(booking = this.detailsBooking) {
      const total = this.getServicesAvailedTotal(booking);

      return Number.isFinite(total) && total > 0
        ? this.formatPeso(total)
        : "Price unavailable";
    },

    refreshIcons() {
      this.$nextTick(() => {
        if (window.lucide) window.lucide.createIcons();
      });
    },
  };
}
