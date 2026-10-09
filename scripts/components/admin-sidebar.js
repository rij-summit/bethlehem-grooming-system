// Alpine's x-if creates fresh DOM after Lucide's initial page scan. Observe
// those insertions so conditional and empty-state icons are always converted.
(function installDynamicLucideIconObserver() {
  if (window.__bethlehemDynamicLucideObserver || typeof MutationObserver === "undefined") {
    return;
  }

  let refreshQueued = false;
  const containsPendingIcon = (node) => node?.nodeType === Node.ELEMENT_NODE
    && (node.matches?.("i[data-lucide]") || node.querySelector?.("i[data-lucide]"));

  const queueIconRefresh = () => {
    if (refreshQueued) return;
    refreshQueued = true;

    window.requestAnimationFrame(() => {
      refreshQueued = false;
      if (window.lucide && document.querySelector("i[data-lucide]")) {
        window.lucide.createIcons();
      }
    });
  };

  const observer = new MutationObserver((mutations) => {
    const hasNewIcon = mutations.some((mutation) =>
      Array.from(mutation.addedNodes).some(containsPendingIcon),
    );
    if (hasNewIcon) queueIconRefresh();
  });

  observer.observe(document.documentElement, { childList: true, subtree: true });
  window.__bethlehemDynamicLucideObserver = observer;
  queueIconRefresh();
})();

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
    "notifications.html": "appointments",
    "clinic.html": "clinic",
    "archive.html": "archive",
    "transactions.html": "transactions",
    "reports.html": "reports",
    "settings.html": "settings",
  };

  return pageMap[pageName] || "";
}

function revealStaffSettingsSidebarLink() {
  if (!window.API || API.getUserRole() !== "staff") return;

  document.querySelectorAll('.admin-sidebar-menu a[href$="settings.html"]').forEach((link) => {
    const adminOnlySection = link.closest('[x-show="isAdmin"]');
    if (adminOnlySection) adminOnlySection.style.removeProperty("display");
  });
}

const adminInventoryLinks = { overview: "Overview", products: "Products", "sell-product": "Point of Sale", history: "Stock Movements" };
const adminInventoryRoutes = ["overview", "products", "sell-product", "stock-in", "stock-out", "history"];

function getAdminInventorySection() {
  if (!String(window.location.pathname || "").endsWith("/inventory.html")) return "";
  const section = String(window.location.hash || "").slice(1).split("?")[0];
  return adminInventoryRoutes.includes(section) ? section : "overview";
}

function installAdminInventoryNavigation() {
  const link = document.querySelector('.admin-sidebar-menu a[href*="inventory.html"]');
  if (!link) return;
  const url = new URL("inventory.html", link.href);
  const group = document.createElement("div");
  group.className = "admin-inventory-navigation";
  const button = document.createElement("button");
  button.type = "button";
  button.className = `${link.className} w-full`;
  button.setAttribute(":class", link.getAttribute(":class") || "''");
  button.setAttribute("@click", "inventoryExpanded = activePage === 'inventory' || !inventoryExpanded");
  button.setAttribute(":aria-expanded", "inventoryExpanded");
  button.setAttribute("aria-controls", "inventory-submenu");
  button.innerHTML = `${link.innerHTML}<span class="ml-auto text-xs" aria-hidden="true" x-text="inventoryExpanded ? '▾' : '▸'"></span>`;
  const submenu = document.createElement("div");
  submenu.id = "inventory-submenu";
  submenu.setAttribute("x-show", "inventoryExpanded");
  submenu.setAttribute("x-cloak", "");
  submenu.className = "admin-inventory-submenu";
  for (const [section, label] of Object.entries(adminInventoryLinks)) {
    const child = document.createElement("a");
    child.href = `${url.pathname}#${section}`;
    child.textContent = label;
    child.setAttribute(":class", `activePage === 'inventory' && inventorySection === '${section}' ? 'is-active' : ''`);
    child.setAttribute(":aria-current", `activePage === 'inventory' && inventorySection === '${section}' ? 'page' : null`);
    child.setAttribute("@click", "sidebarOpen = false");
    submenu.appendChild(child);
  }
  group.append(button, submenu);
  link.replaceWith(group);
}

// Build the shared group before Alpine walks the sidebar. Replacing a live
// Alpine link can leave its queued effects detached from their parent scope.
if (typeof document !== "undefined" && document.querySelector) {
  installAdminInventoryNavigation();
  document.querySelectorAll('.admin-sidebar-menu a[href$="archive.html"] span').forEach((label) => {
    if (label.textContent.trim() === "Archive") label.textContent = "History";
  });
}

function adminSidebar() {
  return {
    sidebarOpen: false,
    activePage: getAdminSidebarActivePage(),
    inventoryExpanded: getAdminSidebarActivePage() === "inventory",
    inventorySection: getAdminInventorySection(),
    _inventoryNavigationListener: null,
    isAdmin: false,
    staffDisplayName: "Staff",
    staffInitials: "ST",
    clinicStopped: false,
    incomingAppointmentCount: 0,
    _incomingAppointmentRevision: 0,
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
      // Keep the shared sidebar binding; the dot represents all active grooming customers today.
      return this.incomingAppointmentCount > 0;
    },

    async init() {
      this.detectActivePage();
      this._inventoryNavigationListener = () => {
        this.inventorySection = getAdminInventorySection();
        if (this.activePage === "inventory") this.inventoryExpanded = true;
      };
      window.addEventListener("hashchange", this._inventoryNavigationListener);
      this.isAdmin = API.getUserRole() === "admin";
      if (API.enforceAdminPageAccess && !API.enforceAdminPageAccess()) return;

      await window.AppClock?.load?.();

      this.registerIncomingAppointmentListener();

      this.$nextTick(() => {
        revealStaffSettingsSidebarLink();
        if (window.lucide) window.lucide.createIcons();
      });

      await Promise.all([
        this.loadLoggedInIdentity(),
        this.loadClinicStatus(),
        this.loadIncomingAppointmentCount(),
      ]);

      this._incomingAppointmentInterval = setInterval(
        () => this.loadIncomingAppointmentCount(),
        30000,
      );
    },

    destroy() {
      window.removeEventListener("hashchange", this._inventoryNavigationListener);
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

    async loadLoggedInIdentity() {
      if (this.isAdmin || typeof API.getMe !== "function") return;

      try {
        const response = await API.getMe("staff");
        const firstName = String(response?.user?.first_name || "").trim();
        const lastName = String(response?.user?.last_name || "").trim();
        const fullName = `${firstName} ${lastName}`.trim();
        if (!fullName) return;

        this.staffDisplayName = fullName;
        this.staffInitials = `${firstName.charAt(0)}${lastName.charAt(0)}`.toUpperCase();
      } catch {
        // Non-fatal: retain the generic staff label when profile loading fails.
      }
    },

    registerIncomingAppointmentListener() {
      this._incomingAppointmentListener = (event) => {
        const state = event.detail?.state;
        if (state?.selectedDate === this.todayDate()) {
          this.setIncomingAppointmentCount(state);
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

    setIncomingAppointmentCount(data, today = this.todayDate()) {
      this._incomingAppointmentRevision += 1;
      // Use the existing status lists, including released bookings awaiting pickup.
      const activeLists = [
        data?.incomingList ?? data?.incoming,
        data?.queuedList ?? data?.queued,
        data?.inProgressList ?? data?.inProgress,
        data?.forPaymentList ?? data?.forPayment,
        data?.forPickupList ?? data?.forPickup,
        data?.releasedList,
      ];
      this.incomingAppointmentCount = activeLists.reduce((count, list) =>
        count + (Array.isArray(list)
          ? list.filter((booking) => booking?.appointmentDate === today).length
          : 0), 0);
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
        const today = this.todayDate();
        const revision = ++this._incomingAppointmentRevision;
        const data = await API.getAdminBookings(today);
        if (revision === this._incomingAppointmentRevision && today === this.todayDate()) {
          this.setIncomingAppointmentCount(data, today);
        }
      } catch {
        // Non-fatal: the sidebar should stay usable even if the count cannot load.
      }
    },

    async handleLogout() {
      try {
        await API.logout("admin");
      } finally {
        API.redirectToSignIn({ replace: true });
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
          message: "This will allow Clinic and Grooming walk-ins and check-ins for the rest of the day.",
          confirmLabel: "Yes, Reopen",
          action: "reopen",
          busy: false,
          error: "",
        };
      } else {
        this.stopModal = {
          open: true,
          title: "Stop Receiving for Today?",
          message: "This stops physical intake for Clinic and Grooming today. Unused pre-registrations expire after their selected date ends. You can reopen intake today.",
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
