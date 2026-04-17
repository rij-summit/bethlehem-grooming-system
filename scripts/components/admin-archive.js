function adminArchive() {
  return {
    archivedList: [],
    totalCount: 0,
    searchQuery: "",
    selectedDate: "",
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
          date:   this.selectedDate,
        });

        this.archivedList = data.archived  || [];
        this.totalCount   = data.total     ?? this.archivedList.length;
      } catch (error) {
        this.errorMessage = error.message || "Failed to load archive. Please try again.";
        this.archivedList = [];
        this.totalCount   = 0;
      } finally {
        this.loading = false;
        this.refreshIcons();
      }
    },

    onFilterChange() {
      this.loadArchive();
    },

    clearFilters() {
      this.searchQuery  = "";
      this.selectedDate = "";
      this.loadArchive();
    },

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

    formatDateLabel(dateStr) {
      if (!dateStr) return "";
      try {
        return new Intl.DateTimeFormat("en-PH", {
          year:  "numeric",
          month: "long",
          day:   "numeric",
        }).format(new Date(dateStr + "T00:00:00"));
      } catch {
        return dateStr;
      }
    },

    refreshIcons() {
      this.$nextTick(() => {
        if (window.lucide) window.lucide.createIcons();
      });
    },
  };
}
