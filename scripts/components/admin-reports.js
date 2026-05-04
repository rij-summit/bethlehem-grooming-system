function adminReports() {
  const d = new Date();
  const currentDate = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
  const currentMonth = currentDate.slice(0, 7);
  const currentYear = currentDate.slice(0, 4);

  return {
    activeSection: "services",
    servicesPeriod: "day",
    selectedDate: currentDate,
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

    switchSection(section) {
      this.activeSection = section;
      this.refreshIcons();
    },

    onServicesPeriodChange() {
      if (this.servicesPeriod === "day" && !this.selectedDate) {
        this.selectedDate = currentDate;
      }

      if (this.servicesPeriod === "month" && !this.selectedMonth) {
        this.selectedMonth = currentMonth;
      }

      if (this.servicesPeriod === "year" && !this.selectedYear) {
        this.selectedYear = currentYear;
      }

      this.loadServicesPerformed();
    },

    onServicesPeriodValueChange() {
      this.loadServicesPerformed();
    },

    clearServicesPeriod() {
      if (this.servicesPeriod === "day") {
        this.selectedDate = "";
      } else if (this.servicesPeriod === "month") {
        this.selectedMonth = "";
      } else if (this.servicesPeriod === "year") {
        this.selectedYear = "";
      }

      this.loadServicesPerformed();
    },

    get hasSelectedPeriodValue() {
      if (this.servicesPeriod === "month") {
        return Boolean(this.selectedMonth);
      }

      if (this.servicesPeriod === "year") {
        return Boolean(this.selectedYear);
      }

      return Boolean(this.selectedDate);
    },

    get servicesPeriodLabel() {
      return this.servicesReport.periodLabel || this.localServicesPeriodLabel;
    },

    get localServicesPeriodLabel() {
      if (this.servicesPeriod === "month") {
        return this.selectedMonth ? this.formatReportMonth(this.selectedMonth) : "All dates";
      }

      if (this.servicesPeriod === "year") {
        return this.selectedYear || "All dates";
      }

      return this.selectedDate ? this.formatReportDate(this.selectedDate) : "All dates";
    },

    get servicesSubtitle() {
      const count = this.servicesReport.completedAppointments;
      const period = this.servicesPeriodLabel === "All dates"
        ? "across all dates"
        : `${this.servicesPeriod === "day" ? "on" : "in"} ${this.servicesPeriodLabel}`;

      return `${this.formatWholeNumber(count)} completed appointment${count === 1 ? "" : "s"} ${period}`;
    },

    get topService() {
      return this.servicesReport.serviceBreakdown[0] || null;
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

    refreshIcons() {
      this.$nextTick(() => {
        if (window.lucide) window.lucide.createIcons();
      });
    },
  };
}
