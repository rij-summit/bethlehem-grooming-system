function adminSidebar() {
  return {
    sidebarOpen: false,
    activePage: "dashboard",
    clinicStopped: false,
    stopModal: {
      open: false,
      title: "",
      message: "",
      confirmLabel: "Confirm",
      action: "",
      busy: false,
      error: "",
    },
    blockedDates: [],
    blockDatesModal: { open: false },
    blockForm: {
      startDate: "",
      endDate: "",
      reason: "",
      busy: false,
      error: "",
      warning: "",
    },

    async init() {
      this.detectActivePage();

      this.$nextTick(() => {
        if (window.lucide) window.lucide.createIcons();
      });

      await this.loadClinicStatus();
    },

    detectActivePage() {
      const currentPath = window.location.pathname;

      if (currentPath.includes("dashboard.html")) {
        this.activePage = "dashboard";
      } else if (currentPath.includes("clients.html")) {
        this.activePage = "customers";
      } else if (currentPath.includes("appointments.html")) {
        this.activePage = "appointments";
      } else if (currentPath.includes("ai-analytics.html")) {
        this.activePage = "analytics";
      } else if (currentPath.includes("archive.html")) {
        this.activePage = "archive";
      } else if (currentPath.includes("transactions.html")) {
        this.activePage = "transactions";
      } else if (currentPath.includes("settings.html")) {
        this.activePage = "settings";
      }
    },

    async handleLogout() {
      try {
        await API.logout("admin");
      } finally {
        // Always redirect even if the API call fails (token is already cleared by api.js)
        window.location.href = "../../pages/client/sign-in.html";
      }
    },

    async loadClinicStatus() {
      try {
        const data = await API.getClinicStatus();
        this.clinicStopped = Boolean(data.stopped_today);
      } catch {
        // Non-fatal
      }
    },

    async openBlockedDatesModal() {
      this.blockDatesModal.open = true;
      this.blockForm = { startDate: "", endDate: "", reason: "", busy: false, error: "", warning: "" };
      await this.loadBlockedDates();
      this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
    },

    async loadBlockedDates() {
      try {
        const data = await API.getBlockedDates();
        this.blockedDates = data.blocked_dates || [];
      } catch {
        this.blockedDates = [];
      }
    },

    async submitBlockedDate() {
      this.blockForm.error = "";
      this.blockForm.warning = "";

      if (!this.blockForm.startDate || !this.blockForm.endDate) {
        this.blockForm.error = "Please select both a start and end date.";
        return;
      }
      if (this.blockForm.endDate < this.blockForm.startDate) {
        this.blockForm.error = "End date cannot be before start date.";
        return;
      }

      this.blockForm.busy = true;
      try {
        const res = await API.addBlockedDate({
          start_date: this.blockForm.startDate,
          end_date:   this.blockForm.endDate,
          reason:     this.blockForm.reason || null,
        });
        if (res.conflict_warning) {
          this.blockForm.warning = res.conflict_warning;
        }
        this.blockForm.startDate = "";
        this.blockForm.endDate   = "";
        this.blockForm.reason    = "";
        await this.loadBlockedDates();
      } catch (err) {
        this.blockForm.error = err.message || "Failed to block dates. Please try again.";
      } finally {
        this.blockForm.busy = false;
      }
    },

    async removeBlockedDate(id) {
      try {
        await API.removeBlockedDate(id);
        this.blockedDates = this.blockedDates.filter(b => b.id !== id);
      } catch (err) {
        alert(err.message || "Failed to remove blocked date.");
      }
    },

    handleStopReceivingClick() {
      if (this.clinicStopped) {
        this.stopModal = {
          open: true,
          title: "Reopen for Today?",
          message: "This will allow new walk-ins and late check-ins for the rest of the day.",
          confirmLabel: "Yes, Reopen",
          action: "reopen",
          busy: false,
          error: "",
        };
      } else {
        this.stopModal = {
          open: true,
          title: "Stop Receiving for Today?",
          message: "Remaining walk-in bookings will be marked as no-show. This action can be undone before the day ends.",
          confirmLabel: "Yes, Stop",
          action: "stop",
          busy: false,
          error: "",
        };
      }
    },

    async executeStopReceiving() {
      const action = this.stopModal.action;
      this.stopModal.busy = true;
      this.stopModal.error = "";

      try {
        if (action === "stop") {
          await API.adminStopToday();
          this.clinicStopped = true;
        } else {
          await API.adminReopenToday();
          this.clinicStopped = false;
        }
        this.stopModal.open = false;
      } catch (err) {
        this.stopModal.error = err.message || "Something went wrong. Please try again.";
      } finally {
        this.stopModal.busy = false;
      }
    },
  };
}
