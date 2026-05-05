function adminReports() {
  const d = new Date();
  const currentDate = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
  const currentMonth = currentDate.slice(0, 7);
  const currentYear = currentDate.slice(0, 4);
  const currentWeek = getIsoWeekValue(d);

  function getIsoWeekValue(date) {
    const weekDate = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    const day = weekDate.getDay() || 7;
    weekDate.setDate(weekDate.getDate() + 4 - day);

    const isoYear = weekDate.getFullYear();
    const yearStart = new Date(isoYear, 0, 1);
    const week = Math.ceil((((weekDate - yearStart) / 86400000) + 1) / 7);

    return `${isoYear}-W${String(week).padStart(2, "0")}`;
  }

  return {
    activeSection: "services",
    servicesPeriod: "day",
    selectedDate: currentDate,
    selectedWeek: currentWeek,
    selectedMonth: currentMonth,
    selectedYear: currentYear,
    selectedServiceFilter: "all",
    loadingServices: false,
    servicesError: "",
    servicesReport: {
      period: "day",
      totalCompleted: 0,
      completedAppointments: 0,
      periodLabel: "Today",
      serviceBreakdown: [],
    },
    customerPeriod: "day",
    customerSelectedDate: currentDate,
    customerSelectedWeek: currentWeek,
    customerSelectedMonth: currentMonth,
    customerSelectedYear: currentYear,
    customerActivitySubsection: "all",
    customerActivityLoaded: false,
    loadingCustomerActivity: false,
    customerActivityError: "",
    customerActivityNavItems: [
      { key: "all", label: "All customers", icon: "users" },
      { key: "new", label: "New customers", icon: "user-plus" },
      { key: "returning", label: "Returning customers", icon: "repeat-2" },
      { key: "noShows", label: "No-show customers", icon: "user-x" },
    ],
    customerActivityReport: {
      period: "day",
      totalUniqueCustomers: 0,
      completedVisits: 0,
      periodLabel: "Today",
      topCustomer: null,
      allCustomers: [],
      newCustomers: [],
      returningCustomers: [],
      noShowCount: 0,
      noShowRate: 0,
      scheduledBookings: 0,
      noShowCustomers: [],
    },

    async init() {
      await this.loadServicesPerformed();
      this.refreshIcons();
    },

    async loadServicesPerformed() {
      this.loadingServices = true;
      this.servicesError = "";

      try {
        const data = await API.getServicesPerformedReport({
          period: this.servicesPeriod,
          date: this.servicesPeriod === "day" ? this.selectedDate : "",
          week: this.servicesPeriod === "week" ? this.selectedWeek : "",
          month: this.servicesPeriod === "month" ? this.selectedMonth : "",
          year: this.servicesPeriod === "year" ? this.selectedYear : "",
        });

        this.servicesReport = {
          period: data.period || this.servicesPeriod,
          totalCompleted: Number(data.totalCompleted ?? 0),
          completedAppointments: Number(data.completedAppointments ?? 0),
          periodLabel: data.periodLabel || this.servicesPeriodLabel,
          serviceBreakdown: Array.isArray(data.serviceBreakdown)
            ? data.serviceBreakdown
            : [],
        };
      } catch (error) {
        this.servicesError = error.message || "Failed to load services report. Please try again.";
        this.servicesReport = {
          period: this.servicesPeriod,
          totalCompleted: 0,
          completedAppointments: 0,
          periodLabel: this.servicesPeriodLabel,
          serviceBreakdown: [],
        };
      } finally {
        this.loadingServices = false;
        this.refreshIcons();
      }
    },

    async loadCustomerActivity() {
      this.loadingCustomerActivity = true;
      this.customerActivityError = "";

      try {
        const data = await API.getCustomerActivityReport({
          period: this.customerPeriod,
          date: this.customerPeriod === "day" ? this.customerSelectedDate : "",
          week: this.customerPeriod === "week" ? this.customerSelectedWeek : "",
          month: this.customerPeriod === "month" ? this.customerSelectedMonth : "",
          year: this.customerPeriod === "year" ? this.customerSelectedYear : "",
        });

        this.customerActivityReport = {
          period: data.period || this.customerPeriod,
          totalUniqueCustomers: Number(data.totalUniqueCustomers ?? 0),
          completedVisits: Number(data.completedVisits ?? 0),
          periodLabel: data.periodLabel || this.localCustomerActivityPeriodLabel,
          topCustomer: data.topCustomer || null,
          allCustomers: Array.isArray(data.allCustomers)
            ? data.allCustomers
            : [],
          newCustomers: Array.isArray(data.newCustomers)
            ? data.newCustomers
            : [],
          returningCustomers: Array.isArray(data.returningCustomers)
            ? data.returningCustomers
            : [],
          noShowCount: Number(data.noShowCount ?? 0),
          noShowRate: Number(data.noShowRate ?? 0),
          scheduledBookings: Number(data.scheduledBookings ?? 0),
          noShowCustomers: Array.isArray(data.noShowCustomers)
            ? data.noShowCustomers
            : [],
        };
      } catch (error) {
        this.customerActivityError = error.message || "Failed to load customer activity. Please try again.";
        this.customerActivityReport = {
          period: this.customerPeriod,
          totalUniqueCustomers: 0,
          completedVisits: 0,
          periodLabel: this.localCustomerActivityPeriodLabel,
          topCustomer: null,
          allCustomers: [],
          newCustomers: [],
          returningCustomers: [],
          noShowCount: 0,
          noShowRate: 0,
          scheduledBookings: 0,
          noShowCustomers: [],
        };
      } finally {
        this.loadingCustomerActivity = false;
        this.customerActivityLoaded = true;
        this.refreshIcons();
      }
    },

    switchSection(section) {
      this.activeSection = section;
      if (section === "customers" && !this.customerActivityLoaded) {
        this.loadCustomerActivity();
      }
      this.refreshIcons();
    },

    onServicesPeriodChange() {
      if (this.servicesPeriod === "day" && !this.selectedDate) {
        this.selectedDate = currentDate;
      }

      if (this.servicesPeriod === "week" && !this.selectedWeek) {
        this.selectedWeek = currentWeek;
      }

      if (this.servicesPeriod === "month" && !this.selectedMonth) {
        this.selectedMonth = currentMonth;
      }

      if (this.servicesPeriod === "year" && !this.selectedYear) {
        this.selectedYear = currentYear;
      }

      this.loadServicesPerformed();
    },

    onCustomerPeriodChange() {
      if (this.customerPeriod === "day" && !this.customerSelectedDate) {
        this.customerSelectedDate = currentDate;
      }

      if (this.customerPeriod === "week" && !this.customerSelectedWeek) {
        this.customerSelectedWeek = currentWeek;
      }

      if (this.customerPeriod === "month" && !this.customerSelectedMonth) {
        this.customerSelectedMonth = currentMonth;
      }

      if (this.customerPeriod === "year" && !this.customerSelectedYear) {
        this.customerSelectedYear = currentYear;
      }

      this.loadCustomerActivity();
    },

    onCustomerPeriodValueChange() {
      this.loadCustomerActivity();
    },

    onServicesPeriodValueChange() {
      this.loadServicesPerformed();
    },

    clearServicesPeriod() {
      if (this.servicesPeriod === "day") {
        this.selectedDate = "";
      } else if (this.servicesPeriod === "week") {
        this.selectedWeek = "";
      } else if (this.servicesPeriod === "month") {
        this.selectedMonth = "";
      } else if (this.servicesPeriod === "year") {
        this.selectedYear = "";
      }

      this.loadServicesPerformed();
    },

    clearCustomerPeriod() {
      if (this.customerPeriod === "day") {
        this.customerSelectedDate = "";
      } else if (this.customerPeriod === "week") {
        this.customerSelectedWeek = "";
      } else if (this.customerPeriod === "month") {
        this.customerSelectedMonth = "";
      } else if (this.customerPeriod === "year") {
        this.customerSelectedYear = "";
      }

      this.loadCustomerActivity();
    },

    get hasSelectedPeriodValue() {
      if (this.servicesPeriod === "week") {
        return Boolean(this.selectedWeek);
      }

      if (this.servicesPeriod === "month") {
        return Boolean(this.selectedMonth);
      }

      if (this.servicesPeriod === "year") {
        return Boolean(this.selectedYear);
      }

      return Boolean(this.selectedDate);
    },

    get hasSelectedCustomerPeriodValue() {
      if (this.customerPeriod === "week") {
        return Boolean(this.customerSelectedWeek);
      }

      if (this.customerPeriod === "month") {
        return Boolean(this.customerSelectedMonth);
      }

      if (this.customerPeriod === "year") {
        return Boolean(this.customerSelectedYear);
      }

      return Boolean(this.customerSelectedDate);
    },

    get servicesPeriodLabel() {
      return this.servicesReport.periodLabel || this.localServicesPeriodLabel;
    },

    get localServicesPeriodLabel() {
      if (this.servicesPeriod === "week") {
        return this.selectedWeek ? this.formatReportWeek(this.selectedWeek) : "All dates";
      }

      if (this.servicesPeriod === "month") {
        return this.selectedMonth ? this.formatReportMonth(this.selectedMonth) : "All dates";
      }

      if (this.servicesPeriod === "year") {
        return this.selectedYear || "All dates";
      }

      return this.selectedDate ? this.formatReportDate(this.selectedDate) : "All dates";
    },

    get customerActivityPeriodLabel() {
      const label = this.customerActivityReport.periodLabel || this.localCustomerActivityPeriodLabel;
      return label === "All dates" ? "All Dates" : label;
    },

    get localCustomerActivityPeriodLabel() {
      if (this.customerPeriod === "week") {
        return this.customerSelectedWeek ? this.formatReportWeek(this.customerSelectedWeek) : "All Dates";
      }

      if (this.customerPeriod === "month") {
        return this.customerSelectedMonth ? this.formatReportMonth(this.customerSelectedMonth) : "All Dates";
      }

      if (this.customerPeriod === "year") {
        return this.customerSelectedYear || "All Dates";
      }

      return this.customerSelectedDate ? this.formatReportDate(this.customerSelectedDate) : "All Dates";
    },

    get servicesSubtitle() {
      const count = this.servicesReport.completedAppointments;
      const preposition = this.periodPreposition(this.servicesPeriod);
      const period = this.servicesPeriodLabel === "All dates"
        ? "across all dates"
        : `${preposition} ${this.servicesPeriodLabel}`;

      return `${this.formatWholeNumber(count)} completed session${count === 1 ? "" : "s"} ${period}`;
    },

    get customerActivitySubtitle() {
      const count = this.customerActivityReport.completedVisits;
      const preposition = this.periodPreposition(this.customerPeriod);
      const period = this.customerActivityPeriodLabel === "All Dates"
        ? "across all dates"
        : `${preposition} ${this.customerActivityPeriodLabel}`;

      return `${this.formatWholeNumber(count)} completed session${count === 1 ? "" : "s"} ${period}`;
    },

    get topService() {
      return this.servicesReport.serviceBreakdown[0] || null;
    },

    get topCustomer() {
      return this.customerActivityReport.topCustomer || null;
    },

    get allCustomerActivityCustomers() {
      return this.customerActivityReport.allCustomers || [];
    },

    get newCustomerActivityCustomers() {
      return this.customerActivityReport.newCustomers || [];
    },

    get returningCustomerActivityCustomers() {
      return this.customerActivityReport.returningCustomers || [];
    },

    get noShowCustomerActivityCustomers() {
      return this.customerActivityReport.noShowCustomers || [];
    },

    get allCustomersEmptyMessage() {
      return "No customers with completed grooming visits yet.";
    },

    get newCustomersEmptyMessage() {
      return `No first-time customer for ${this.customerActivityPeriodLabel}.`;
    },

    get returningCustomersEmptyMessage() {
      return `No returning customer for ${this.customerActivityPeriodLabel}.`;
    },

    get noShowCustomersEmptyMessage() {
      return `No customer no-shows for ${this.customerActivityPeriodLabel}.`;
    },

    get noShowSubtitle() {
      const count = this.customerActivityReport.scheduledBookings;
      const preposition = this.periodPreposition(this.customerPeriod);
      const period = this.customerActivityPeriodLabel === "All Dates"
        ? "across all dates"
        : `${preposition} ${this.customerActivityPeriodLabel}`;

      return `${this.formatWholeNumber(count)} scheduled visit${count === 1 ? "" : "s"} ${period}`;
    },

    get filteredServiceBreakdown() {
      if (this.selectedServiceFilter === "all") {
        return this.servicesReport.serviceBreakdown;
      }

      return this.servicesReport.serviceBreakdown.filter(
        service => service.serviceName === this.selectedServiceFilter,
      );
    },

    get selectedServiceFilterLabel() {
      if (this.selectedServiceFilter === "all") {
        return "All Services";
      }

      return this.selectedServiceFilter;
    },

    get serviceBreakdownEmptyMessage() {
      if (this.selectedServiceFilter === "all") {
        return "No completed grooming services for this period.";
      }

      return `No completed ${this.selectedServiceFilter} services for this period.`;
    },

    formatWholeNumber(value) {
      return new Intl.NumberFormat("en-PH", {
        maximumFractionDigits: 0,
      }).format(Number(value || 0));
    },

    formatPercent(value) {
      return new Intl.NumberFormat("en-PH", {
        maximumFractionDigits: 1,
      }).format(Number(value || 0));
    },

    formatReportDate(dateStr) {
      if (!dateStr) return "All dates";

      try {
        return new Intl.DateTimeFormat("en-PH", {
          year: "numeric",
          month: "long",
          day: "numeric",
        }).format(new Date(dateStr + "T00:00:00"));
      } catch {
        return dateStr;
      }
    },

    formatReportMonth(monthStr) {
      if (!monthStr) return "All dates";

      try {
        return new Intl.DateTimeFormat("en-PH", {
          year: "numeric",
          month: "long",
        }).format(new Date(monthStr + "-01T00:00:00"));
      } catch {
        return monthStr;
      }
    },

    formatReportWeek(weekStr) {
      const match = String(weekStr || "").match(/^(\d{4})-W(\d{2})$/);
      if (!match) return "All dates";

      const isoYear = Number(match[1]);
      const isoWeek = Number(match[2]);
      const janFourth = new Date(isoYear, 0, 4);
      const janFourthDay = janFourth.getDay() || 7;
      const weekStart = new Date(isoYear, 0, 4 - janFourthDay + 1 + ((isoWeek - 1) * 7));
      const weekEnd = new Date(weekStart);
      weekEnd.setDate(weekStart.getDate() + 6);

      return this.formatDateRange(weekStart, weekEnd);
    },

    formatDateRange(startDate, endDate) {
      const sameYear = startDate.getFullYear() === endDate.getFullYear();
      const sameMonth = sameYear && startDate.getMonth() === endDate.getMonth();
      const monthFormatter = new Intl.DateTimeFormat("en-PH", { month: "long" });
      const shortFormatter = new Intl.DateTimeFormat("en-PH", { month: "long", day: "numeric" });
      const fullFormatter = new Intl.DateTimeFormat("en-PH", {
        year: "numeric",
        month: "long",
        day: "numeric",
      });

      if (sameMonth) {
        return `${monthFormatter.format(startDate)} ${startDate.getDate()}-${endDate.getDate()}, ${endDate.getFullYear()}`;
      }

      if (sameYear) {
        return `${shortFormatter.format(startDate)}-${fullFormatter.format(endDate)}`;
      }

      return `${fullFormatter.format(startDate)}-${fullFormatter.format(endDate)}`;
    },

    periodPreposition(period) {
      if (period === "day") return "on";
      if (period === "week") return "during";
      return "in";
    },

    formatReportDateTime(dateTimeStr) {
      if (!dateTimeStr) return "No visits yet";

      try {
        return new Intl.DateTimeFormat("en-PH", {
          year: "numeric",
          month: "short",
          day: "numeric",
        }).format(new Date(String(dateTimeStr).replace(" ", "T")));
      } catch {
        return dateTimeStr;
      }
    },

    customerInitials(customer) {
      const name = customer?.customerName || "";
      const initials = name
        .split(" ")
        .filter(Boolean)
        .slice(0, 2)
        .map(part => part[0])
        .join("")
        .toUpperCase();

      return initials || "CU";
    },

    formatMobileNumber(value) {
      const text = String(value ?? "").trim();
      if (!text) return "";

      const digits = text.replace(/\D/g, "");
      if (digits.length === 11) {
        return `${digits.slice(0, 4)}-${digits.slice(4, 7)}-${digits.slice(7)}`;
      }

      return text;
    },

    customerContactLine(customer) {
      return [this.formatMobileNumber(customer?.phone), customer?.email].filter(Boolean).join(" | ");
    },

    refreshIcons() {
      this.$nextTick(() => {
        if (window.lucide) window.lucide.createIcons();
      });
    },
  };
}
