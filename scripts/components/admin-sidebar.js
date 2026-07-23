function getAdminSidebarActivePage(pathname = window.location.pathname) {
  const normalizedPath = String(pathname || "")
    .replace(/\\/g, "/")
    .toLowerCase();

  if (
    normalizedPath.includes("/inventory/") ||
    normalizedPath.endsWith("/inventory.html")
  ) {
    return "inventory";
  }

  const pageName = normalizedPath.split("/").filter(Boolean).pop() || "";
  const pageMap = {
    "dashboard.html": "dashboard",
    "clients.html": "customers",
    "appointments.html": "appointments",
    "clinic.html": "clinic",
    "archive.html": "archive",
    "transactions.html": "transactions",
    "reports.html": "reports",
    "settings.html": "settings",
  };

  return pageMap[pageName] || "";
}

function adminSidebar() {
  return {
    sidebarOpen: false,
    activePage: getAdminSidebarActivePage(),
    isAdmin: false,
    clinicStopped: false,
    incomingAppointmentCount: 0,
    _incomingAppointmentInterval: null,
    _incomingAppointmentListener: null,
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

    get hasIncomingAppointments() {
      return this.incomingAppointmentCount > 0;
    },

    async init() {
      this.detectActivePage();
      this.isAdmin = API.getUserRole() === "admin";
      if (API.enforceAdminPageAccess && !API.enforceAdminPageAccess()) return;

      await window.AppClock?.load?.();

      this.registerIncomingAppointmentListener();

      this.$nextTick(() => {
        if (window.lucide) window.lucide.createIcons();
      });

      await Promise.all([
        this.loadClinicStatus(),
        this.loadIncomingAppointmentCount(),
      ]);

      this._incomingAppointmentInterval = setInterval(
        () => this.loadIncomingAppointmentCount(),
        30000,
      );
    },

    destroy() {
      if (this._incomingAppointmentInterval) {
        clearInterval(this._incomingAppointmentInterval);
      }

      if (this._incomingAppointmentListener) {
        window.removeEventListener(
          "admin-dashboard:data-applied",
          this._incomingAppointmentListener,
        );
      }
    },

    detectActivePage() {
      this.activePage = getAdminSidebarActivePage();
    },

    registerIncomingAppointmentListener() {
      this._incomingAppointmentListener = (event) => {
        if (this.activePage !== "appointments") return;

        const incomingList = event.detail?.state?.incomingList;
        if (Array.isArray(incomingList)) {
          this.setIncomingAppointmentCount(incomingList.length);
        }
      };

      window.addEventListener(
        "admin-dashboard:data-applied",
        this._incomingAppointmentListener,
      );
    },

    todayDate() {
      const today = window.AppClock?.todayKey?.();
      if (today) return today;

      const d = new Date();
      return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
    },

    setIncomingAppointmentCount(count) {
      const nextCount = Number(count);
      this.incomingAppointmentCount =
        Number.isFinite(nextCount) && nextCount > 0 ? nextCount : 0;
    },

    async loadIncomingAppointmentCount() {
      if (
        !window.API ||
        typeof API.getAdminBookings !== "function" ||
        typeof API.getAdminToken !== "function" ||
        !API.getAdminToken()
      ) {
        return;
      }

      try {
        const data = await API.getAdminBookings(this.todayDate(), {
          includeFuture: true,
        });
        const incomingList = data?.incomingList ?? data?.incoming ?? [];
        this.setIncomingAppointmentCount(
          Array.isArray(incomingList) ? incomingList.length : 0,
        );
      } catch {
        // Non-fatal: the sidebar should stay usable even if the count cannot load.
      }
    },

    async handleLogout() {
      try {
        await API.logout("admin");
      } finally {
        const parts = window.location.pathname.replace(/\\/g, "/").split("/").filter(Boolean);
        const ups = parts.length > 1 ? "../".repeat(parts.length - 1) : "./";
        window.location.href = ups + "pages/client/sign-in.html";
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
      if (!this.isAdmin) return;

      this.blockDatesModal.open = true;
      this.clearBlockedDateForm();
      await this.loadBlockedDates();
      this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
    },

    clearBlockedDateForm() {
      this.blockForm = {
        startDate: "",
        endDate: "",
        reason: "",
        busy: false,
        error: "",
        warning: "",
      };
    },

    async loadBlockedDates() {
      try {
        const data = await API.getBlockedDates();
        this.blockedDates = data.blocked_dates || [];
      } catch {
        this.blockedDates = [];
      } finally {
        this.$nextTick(() => {
          if (window.lucide) window.lucide.createIcons();
        });
      }
    },

    async submitBlockedDate() {
      if (!this.isAdmin) return;

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
      if (!this.isAdmin) return;

      try {
        await API.removeBlockedDate(id);
        this.blockedDates = this.blockedDates.filter(b => b.id !== id);
      } catch (err) {
        alert(err.message || "Failed to remove blocked date.");
      }
    },

    handleStopReceivingClick() {
      if (!this.isAdmin) return;

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
      if (!this.isAdmin) return;

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
