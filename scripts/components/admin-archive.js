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
      this._restoreOnPageShow = (event) => {
        if (event.persisted) this.restoreHistoryContext(window.history.state?.groomingHistory);
      };
      window.addEventListener("pageshow", this._restoreOnPageShow);
      const context = window.history.state?.groomingHistory;
      if (context) this.searchQuery = context.searchQuery;
      await this.loadArchive();
      this.restoreHistoryContext(context);
      this.refreshIcons();
    },

    destroy() {
      window.removeEventListener("pageshow", this._restoreOnPageShow);
    },

    restoreHistoryContext(context) {
      if (context) {
        this.searchQuery = context.searchQuery;
        this.sortOrder = context.sortOrder;
        this.selectedYear = context.selectedYear;
        this.selectedMonth = context.selectedMonth;
        const booking = this.filteredList.find((record) => String(record.id) === String(context.bookingId));
        if (booking) this.viewDetails(booking, null, context.modalScroll);
        this.$nextTick(() => {
          window.scrollTo(0, context.scrollY);
          if (booking) this._detailsTrigger = document.querySelector(`[data-history-booking="${booking.id}"]`);
        });
        window.history.replaceState({ ...window.history.state, groomingHistory: null }, "");
      }
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
        this.errorMessage = error.message || "Failed to load history. Please try again.";
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
        ? "No clinic history data is currently available."
        : "No grooming history yet.";
    },

    get emptyStateMessage() {
      if (this.hasActiveFilters) return "Try adjusting your search or clearing the filters.";
      return this.archiveType === "clinic"
        ? "Completed, cancelled, and expired clinic visits will appear here."
        : "Completed grooming sessions will appear here after staff add them to history.";
    },

    viewDetails(booking, trigger = null, scrollTop = 0) {
      this._detailsTrigger = trigger;
      this.detailsBooking = booking;
      this.detailsModalOpen = true;
      this.$nextTick(() => {
        // Resolve the rendered dialog after startup/Back, as well as a row click.
        const dialog = document.querySelector('[aria-labelledby="grooming-record-title"]');
        dialog.querySelector('[aria-label="Close grooming record"]').focus({ preventScroll: true });
        dialog.querySelector('[aria-label="Grooming record details"]').scrollTop = scrollTop;
      });
      this.refreshIcons();
    },

    closeDetails() {
      if (!this.detailsModalOpen) return;
      this.detailsModalOpen = false;
      this.detailsBooking = null;
      this._detailsTrigger?.focus();
      this._detailsTrigger = null;
    },

    trapDetailsFocus(event) {
      const targets = [...this.$refs.groomingRecordDialog.querySelectorAll('button, a[href], [tabindex="0"]')]
        .filter((element) => !element.disabled && element.getClientRects().length);
      const first = targets[0];
      const last = targets[targets.length - 1];
      if (event.shiftKey && event.target === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && event.target === last) {
        event.preventDefault();
        first.focus();
      }
    },

    showSessionTime(kind, booking = this.detailsBooking) {
      const start = kind === "start";
      const iso = booking?.[start ? "startedAtIso" : "completedAtIso"];
      const time = booking?.[start ? "startedAt" : "completedAt"];
      if (this.isArchiveValueMissing(iso) && this.isArchiveValueMissing(time)) return false;
      return !(booking?.pets || []).some((pet) => {
        const petIso = pet[start ? "groomingStartedAtIso" : "groomingFinishedAtIso"];
        const petTime = pet[start ? "groomingStartedAt" : "groomingFinishedAt"];
        if (iso && petIso) return Number.isFinite(Date.parse(iso)) && Date.parse(iso) === Date.parse(petIso);
        return !iso && !petIso && !this.isArchiveValueMissing(time) && time === petTime;
      });
    },

    petSize(pet) {
      // Verification belongs to this booking, not the pet's current profile.
      const confirmed = !this.isArchiveValueMissing(pet.confirmedSize);
      const registered = !this.isArchiveValueMissing(pet.registeredSize);
      const value = confirmed ? pet.confirmedSize : registered ? pet.registeredSize : pet.size;
      if (this.isArchiveValueMissing(value)) return "Not recorded";
      const label = String(value).charAt(0).toUpperCase() + String(value).slice(1);
      return label + (confirmed ? " · Clinic verified" : registered ? "" : " · Profile size (session size not recorded)");
    },

    productAddonsTotal(booking = this.detailsBooking) {
      return (booking?.productAddons || []).reduce((sum, line) => sum + Number(line.subtotal || 0), 0);
    },

    showProductSubtotal(line) {
      return Number(line.quantity) > 1 || Number(line.subtotal) !== Number(line.priceAtSale);
    },

    transactionUrl(booking = this.detailsBooking) {
      if (!booking?.payment) return "";
      const params = new URLSearchParams();
      if (booking.payment.id != null) params.set("payment", booking.payment.id);
      if (booking.bookingReference) params.set("reference", booking.bookingReference);
      return params.size ? `./transactions.html?${params}` : "";
    },

    rememberHistoryContext(event) {
      if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      window.history.replaceState({ ...window.history.state, groomingHistory: {
        searchQuery: this.searchQuery, sortOrder: this.sortOrder,
        selectedYear: this.selectedYear, selectedMonth: this.selectedMonth,
        bookingId: this.detailsBooking.id, scrollY: window.scrollY,
        modalScroll: this.$refs.groomingRecordBody.scrollTop,
      } }, "");
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
      if (["no_show", "expired"].includes(status)) return "bg-slate-100 text-slate-600 border-slate-200";
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

    petSummary(booking) {
      const pets = booking?.pets ?? [];
      if (!pets.length) return this.displayArchiveValue(booking?.petName, 'Pets not recorded');
      const names = pets.slice(0, 2).map((pet) => this.displayArchiveValue(pet.petName, 'Unnamed pet'));
      const remaining = pets.length - names.length;
      return names.join(', ') + (remaining ? ` +${remaining} pet${remaining === 1 ? '' : 's'}` : '');
    },

    serviceSummary(booking) {
      const names = [...new Set((booking?.services ?? []).map((service) => service.name).filter(Boolean))];
      if (!names.length) return this.displayArchiveValue(booking?.serviceLabel, 'Services not recorded');
      return names[0] + (names.length > 1 ? ` +${names.length - 1} more` : '');
    },

    sessionTime(booking) {
      if (booking?.startedAt && booking?.completedAt) {
        const crossesDate = booking.startedAtIso && booking.completedAtIso
          && booking.startedAtIso.slice(0, 10) !== booking.completedAtIso.slice(0, 10);
        const finishDate = crossesDate ? ` (${this.formatAppointmentDate(booking.completedAtIso.slice(0, 10))})` : '';
        return `${booking.startedAt} – ${booking.completedAt}${finishDate}`;
      }
      if (booking?.startedAt) return `Started ${booking.startedAt}`;
      if (booking?.completedAt) return `Finished ${booking.completedAt}`;
      if (booking?.dropOffTime) return `Dropped off ${booking.dropOffTime}`;
      return 'Time not recorded';
    },

    getServiceAvailedAmount(service) {
      const recorded =
        service?.paidPrice ??
        service?.paid_price ??
        service?.finalPrice ??
        service?.final_price ??
        service?.priceAtBooking ??
        service?.price_at_booking;
      if (recorded === null || recorded === undefined || recorded === '') return null;
      const amount = Number(recorded);

      return Number.isFinite(amount) && amount >= 0 ? amount : null;
    },

    formatServiceAvailedPrice(service) {
      const amount = this.getServiceAvailedAmount(service);
      return amount !== null ? this.formatPeso(amount) : "Price unavailable";
    },

    servicesForPet(pet, booking = this.detailsBooking) {
      const bookingPetId = pet?.bookingPetId ?? pet?.booking_pet_id;
      if (bookingPetId === null || bookingPetId === undefined) return [];
      return (booking?.services ?? []).filter((service) =>
        String(service.bookingPetId ?? service.booking_pet_id) === String(bookingPetId),
      );
    },

    paymentMethod(booking = this.detailsBooking) {
      const method = booking?.payment?.paymentMethod ?? booking?.payment?.payment_method;
      return method ? method.charAt(0).toUpperCase() + method.slice(1) : 'Not recorded';
    },

    paymentAmount(booking = this.detailsBooking) {
      const amount = booking?.payment?.finalPrice ?? booking?.payment?.final_price
        ?? booking?.paidAmount ?? booking?.paid_amount;
      return amount === null || amount === undefined || amount === '' ? 'Not recorded' : this.formatPeso(amount);
    },

    getServicesAvailedTotal(booking = this.detailsBooking) {
      const productTotal = this.productAddonsTotal(booking);
      const recordedTotal =
        booking?.payment?.finalPrice ??
        booking?.payment?.final_price ??
        booking?.paidAmount ??
        booking?.paid_amount;
      const paymentTotal = recordedTotal === null || recordedTotal === undefined || recordedTotal === ''
        ? NaN : Number(recordedTotal);

      if (Number.isFinite(paymentTotal) && paymentTotal >= 0) return paymentTotal - productTotal;

      const services = Array.isArray(booking?.services) ? booking.services : [];
      const serviceAmounts = services.map((service) => this.getServiceAvailedAmount(service));

      return serviceAmounts.length > 0 && serviceAmounts.every((amount) => amount !== null)
        ? serviceAmounts.reduce((sum, amount) => sum + amount, 0)
        : null;
    },

    formatServicesAvailedTotal(booking = this.detailsBooking) {
      const total = this.getServicesAvailedTotal(booking);
      return Number.isFinite(total) && total >= 0
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
