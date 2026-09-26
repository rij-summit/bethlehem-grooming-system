function adminArchive() {
  const currentYear = new Date().getFullYear().toString();
  const unavailableText = "Data is currently unavailable.";

  return {
    archiveType: "grooming",
    archivedList: [],
    clinicArchivedList: [],
    totalCount: 0,
    clinicTotalCount: 0,
    searchQuery: "",
    sortOrder: "newest",
    selectedYear: currentYear,
    selectedMonth: "",
    loading: false,
    errorMessage: "",
    detailsModalOpen: false,
    detailsBooking: null,
    clinicDetailsModalOpen: false,
    detailsClinicRecord: null,

    async init() {
      await this.loadArchive();
      this.refreshIcons();
    },

    async selectArchiveType(type) {
      if (!['grooming', 'clinic'].includes(type) || this.archiveType === type) return;

      this.archiveType = type;
      this.searchQuery = "";
      this.sortOrder = "newest";
      this.selectedYear = currentYear;
      this.selectedMonth = "";
      this.closeDetails();
      this.closeClinicRecord();
      await this.loadArchive();
    },

    async loadArchive() {
      this.loading = true;
      this.errorMessage = "";

      try {
        if (this.archiveType === "clinic") {
          const data = await API.getArchivedClinicAppointments({
            search: this.searchQuery.trim(),
          });
          this.clinicArchivedList = data.archived || [];
          this.clinicTotalCount = data.total ?? this.clinicArchivedList.length;
        } else {
          const data = await API.getArchivedBookings({
            search: this.searchQuery.trim(),
          });
          this.archivedList = data.archived || [];
          this.totalCount = data.total ?? this.archivedList.length;
        }
        this.selectAvailableYear();
      } catch (error) {
        this.errorMessage = error.message || "Failed to load archive. Please try again.";
        if (this.archiveType === "clinic") {
          this.clinicArchivedList = [];
          this.clinicTotalCount = 0;
        } else {
          this.archivedList = [];
          this.totalCount = 0;
        }
      } finally {
        this.loading = false;
        this.refreshIcons();
      }
    },

    onSearchChange() {
      this.loadArchive();
    },

    onYearChange() {
      this.selectedMonth = "";
    },

    selectAvailableYear() {
      const years = this.availableYears;
      if (years.length > 0 && !years.includes(this.selectedYear)) {
        this.selectedYear = years[0];
        this.selectedMonth = "";
      }
    },

    clearFilters() {
      this.searchQuery = "";
      this.sortOrder = "newest";
      this.selectedYear = currentYear;
      this.selectedMonth = "";
      this.loadArchive();
    },

    get sourceList() {
      return this.archiveType === "clinic" ? this.clinicArchivedList : this.archivedList;
    },

    recordDate(record) {
      return this.archiveType === "clinic"
        ? record?.appointment_date
        : record?.appointmentDate;
    },

    get availableYears() {
      const seen = new Set();
      for (const record of this.sourceList) {
        const date = this.recordDate(record);
        if (date) seen.add(date.slice(0, 4));
      }
      return [...seen].sort().reverse();
    },

    get availableMonths() {
      const seen = new Set();
      for (const record of this.sourceList) {
        const date = this.recordDate(record);
        if (date && date.startsWith(this.selectedYear)) seen.add(date.slice(0, 7));
      }
      return [...seen]
        .sort()
        .reverse()
        .map((month) => ({
          value: month,
          label: new Intl.DateTimeFormat("en-PH", { month: "long" }).format(
            new Date(`${month}-01T00:00:00`),
          ),
        }));
    },

    get filteredList() {
      let list = this.sourceList;

      if (this.selectedYear) {
        list = list.filter((record) => (this.recordDate(record) || "").startsWith(this.selectedYear));
      }

      if (this.selectedMonth) {
        list = list.filter((record) => (this.recordDate(record) || "").startsWith(this.selectedMonth));
      }

      return [...list].sort((a, b) => {
        const aDate = this.recordDate(a) || "";
        const bDate = this.recordDate(b) || "";
        switch (this.sortOrder) {
          case "az": return (a.ownerName || "").localeCompare(b.ownerName || "");
          case "za": return (b.ownerName || "").localeCompare(a.ownerName || "");
          case "oldest": return aDate.localeCompare(bDate);
          default: return bDate.localeCompare(aDate);
        }
      });
    },

    get groupedByDate() {
      const groups = {};
      for (const record of this.filteredList) {
        const date = this.recordDate(record) || "unknown";
        if (!groups[date]) groups[date] = [];
        groups[date].push(record);
      }

      const dateKeys = Object.keys(groups).sort((a, b) =>
        this.sortOrder === "oldest" ? a.localeCompare(b) : b.localeCompare(a),
      );

      return dateKeys.map((date) => ({
        date,
        label: this.formatGroupDate(date),
        bookings: groups[date],
      }));
    },

    get filteredCount() {
      return this.filteredList.length;
    },

    get hasActiveFilters() {
      return Boolean(
        this.searchQuery ||
        this.selectedMonth ||
        this.sortOrder !== "newest" ||
        this.selectedYear !== currentYear
      );
    },

    get emptyStateTitle() {
      if (this.hasActiveFilters) return "No records match your filters.";
      return this.archiveType === "clinic"
        ? "No clinic archive data is currently available."
        : "No archived records yet.";
    },

    get emptyStateMessage() {
      if (this.hasActiveFilters) return "Try adjusting your search or clearing the filters.";
      return this.archiveType === "clinic"
        ? "Completed, cancelled, and no-show clinic visits will appear here."
        : "Completed grooming sessions will appear here after they are archived from the dashboard.";
    },

    viewDetails(booking) {
      this.detailsBooking = booking;
      this.detailsModalOpen = true;
      this.refreshIcons();
    },

    closeDetails() {
      this.detailsModalOpen = false;
      this.detailsBooking = null;
    },

    viewClinicRecord(record) {
      this.detailsClinicRecord = record;
      this.clinicDetailsModalOpen = true;
      this.refreshIcons();
    },

    closeClinicRecord() {
      this.clinicDetailsModalOpen = false;
      this.detailsClinicRecord = null;
    },

    displayArchiveValue(value, fallback = null) {
      fallback ??= this.clinicDetailsModalOpen ? "Not recorded" : unavailableText;
      if (value === null || value === undefined) return fallback;
      const text = String(value).trim();
      return !text || text === "—" ? fallback : text;
    },

    isArchiveValueMissing(value) {
      if (value === null || value === undefined) return true;
      const text = String(value).trim();
      return !text || text === "—";
    },

    formatGroupDate(dateStr) {
      if (!dateStr || dateStr === "unknown") return "Unknown Date";
      try {
        return new Intl.DateTimeFormat("en-PH", {
          weekday: "long",
          year: "numeric",
          month: "long",
          day: "numeric",
        }).format(new Date(`${dateStr}T00:00:00`));
      } catch {
        return dateStr;
      }
    },

    formatAppointmentDate(dateStr, fallback = null) {
      fallback ??= this.clinicDetailsModalOpen ? "Not recorded" : unavailableText;
      if (!dateStr) return fallback;
      try {
        return new Intl.DateTimeFormat("en-PH", {
          weekday: "short",
          year: "numeric",
          month: "short",
          day: "numeric",
        }).format(new Date(`${dateStr}T00:00:00`));
      } catch {
        return dateStr;
      }
    },

    formatDateTime(value, fallback = null) {
      fallback ??= this.clinicDetailsModalOpen ? "Not recorded" : unavailableText;
      if (!value) return fallback;
      try {
        return new Intl.DateTimeFormat("en-PH", {
          year: "numeric",
          month: "short",
          day: "numeric",
          hour: "numeric",
          minute: "2-digit",
        }).format(new Date(value));
      } catch {
        return this.displayArchiveValue(value, fallback);
      }
    },

    formatClinicVisit(record, fallback = null) {
      fallback ??= this.clinicDetailsModalOpen ? "Not recorded" : unavailableText;
      const appointmentDate = record?.appointment_date;
      const time = record?.checked_in_at
        ? new Intl.DateTimeFormat("en-PH", { hour: "numeric", minute: "2-digit" }).format(new Date(record.checked_in_at))
        : record?.time_window?.window_label;

      if (!appointmentDate && !time) return fallback;
      if (!appointmentDate) return this.displayArchiveValue(time, fallback);

      const date = this.formatAppointmentDate(appointmentDate, fallback);
      return time ? `${date} • ${time}` : date;
    },

    formatClinicWeight(record, fallback = null) {
      fallback ??= this.clinicDetailsModalOpen ? "Not recorded" : unavailableText;
      const weight = record?.vitals?.weight_kg ?? record?.pet?.weight;
      return weight === null || weight === undefined || weight === ""
        ? fallback
        : `${weight} kg`;
    },

    formatClinicAmount(amount, fallback = null) {
      fallback ??= this.clinicDetailsModalOpen ? "Not recorded" : unavailableText;
      return amount === null || amount === undefined || amount === ""
        ? fallback
        : this.formatPeso(amount);
    },

    clinicStatusClass(status) {
      if (status === "completed") return "bg-emerald-50 text-emerald-700 border-emerald-200";
      if (status === "cancelled") return "bg-rose-50 text-rose-700 border-rose-200";
      if (status === "no_show") return "bg-amber-50 text-amber-700 border-amber-200";
      return "bg-slate-100 text-slate-600 border-slate-200";
    },

    formatMobileNumber(value, fallback = null) {
      fallback ??= this.clinicDetailsModalOpen ? "Not recorded" : unavailableText;
      const text = String(value ?? "").trim();
      if (!text || text === "—") return fallback;

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

    servicesForPet(pet, booking = this.detailsBooking) {
      return (booking?.services ?? []).filter((service) =>
        String(service.bookingPetId ?? service.booking_pet_id) === String(pet.bookingPetId ?? pet.booking_pet_id),
      );
    },

    servicesByKind(services, kind) {
      return services.filter((service) => (service.serviceKind ?? 'ala_carte') === kind);
    },

    paymentMethod(booking = this.detailsBooking) {
      const method = booking?.payment?.paymentMethod ?? booking?.payment?.payment_method;
      return method ? method.charAt(0).toUpperCase() + method.slice(1) : 'Not recorded';
    },

    paymentAmount(booking = this.detailsBooking) {
      const amount = booking?.payment?.finalPrice ?? booking?.payment?.final_price;
      return amount === null || amount === undefined ? 'Not recorded' : this.formatPeso(amount);
    },

    getServicesAvailedTotal(booking = this.detailsBooking) {
      const paymentTotal = Number(
        booking?.paidAmount ??
        booking?.paid_amount ??
        booking?.payment?.finalPrice ??
        booking?.payment?.final_price,
      );

      if (Number.isFinite(paymentTotal) && paymentTotal > 0) return paymentTotal;

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
