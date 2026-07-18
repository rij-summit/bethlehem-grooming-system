function adminCustomers() {
  return {
    customers:    [],
    totalCount:   0,
    loading:      false,
    errorMessage: "",
    statusFilter: "active",   // active | inactive | archived
    tierFilter:   "",          // new | returning | ""
    searchQuery:  "",
    busyId:       null,

    confirmModal: {
      open:         false,
      action:       "",   // deactivate | reactivate | archive | unarchive
      customer:     null,
      title:        "",
      message:      "",
      confirmLabel: "",
      error:        "",
    },

    resetModal: {
      open:     false,
      customer: null,
    },

    detailModal: {
      open:     false,
      customer: null,
      loading:  false,
      error:    "",
    },

    // ── Init ──────────────────────────────────────────────

    async init() {
      await this.loadCustomers();
      const params     = new URLSearchParams(window.location.search);
      const customerId = params.get("customer_id");
      if (customerId) {
        const customer = this.customers.find(c => String(c.id) === customerId);
        if (customer) {
          this.openCustomerDetails(customer);
        } else {
          // Not in the current filtered list — fetch directly by ID
          try {
            const data = await API.getCustomerDetails(customerId);
            if (data.customer) this.openCustomerDetails(data.customer);
          } catch { /* silently ignore if not found */ }
        }
      }
    },

    get isAdmin() {
      return API.getUserRole?.() === "admin";
    },

    // ── Load ─────────────────────────────────────────────

    async loadCustomers() {
      this.loading      = true;
      this.errorMessage = "";

      try {
        const data = await API.getCustomers({
          status: this.statusFilter,
          tier:   this.tierFilter,
          search: this.searchQuery.trim(),
        });
        this.customers  = data.customers || [];
        this.totalCount = data.total ?? this.customers.length;
      } catch (err) {
        this.errorMessage = err.message || "Failed to load customers.";
        this.customers    = [];
        this.totalCount   = 0;
      } finally {
        this.loading = false;
        this.refreshIcons();
      }
    },

    // ── Filter helpers ────────────────────────────────────

    setStatus(status) {
      this.statusFilter = status;
      this.loadCustomers();
    },

    setTier(tier) {
      this.tierFilter = tier;
      this.loadCustomers();
    },

    // ── Confirm modal ─────────────────────────────────────

    confirmAction(action, customer) {
      if (!this.isAdmin) return;

      const labels = {
        deactivate:  { title: "Deactivate Account",  confirmLabel: "Deactivate",  color: "amber"  },
        reactivate:  { title: "Reactivate Account",  confirmLabel: "Reactivate",  color: "green"  },
        archive:     { title: "Archive Account",     confirmLabel: "Archive",     color: "slate"  },
        unarchive:   { title: "Unarchive Account",   confirmLabel: "Unarchive",   color: "green"  },
      };

      const messages = {
        deactivate: `This will block ${customer.fullName} from logging in. You can reactivate their account at any time.`,
        reactivate: `This will restore ${customer.fullName}'s access and allow them to log in again.`,
        archive:    `This will permanently move ${customer.fullName} to the archive. They will not be able to log in.`,
        unarchive:  `This will restore ${customer.fullName}'s account and reactivate their access.`,
      };

      const meta = labels[action] || {};

      this.confirmModal = {
        open:         true,
        action,
        customer,
        title:        meta.title        || action,
        message:      messages[action]  || "",
        confirmLabel: meta.confirmLabel || action,
        error:        "",
      };

      this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
    },

    closeConfirm() {
      this.confirmModal.open = false;
    },

    async executeAction() {
      if (!this.isAdmin) return;

      const { action, customer } = this.confirmModal;
      if (!customer) return;

      this.busyId              = customer.id;
      this.confirmModal.error  = "";

      const apiMap = {
        deactivate: () => API.deactivateCustomer(customer.id),
        reactivate: () => API.reactivateCustomer(customer.id),
        archive:    () => API.archiveCustomer(customer.id),
        unarchive:  () => API.unarchiveCustomer(customer.id),
      };

      try {
        await apiMap[action]();
        this.confirmModal.open = false;
        await this.loadCustomers();
      } catch (err) {
        this.confirmModal.error = err.message || "Action failed. Please try again.";
      } finally {
        this.busyId = null;
      }
    },

    // ── Reset Password modal ──────────────────────────────

    openResetPassword(customer) {
      if (!this.isAdmin) return;

      this.resetModal = { open: true, customer };
      this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
    },

    closeReset() {
      this.resetModal.open = false;
    },

    async openCustomerDetails(customer) {
      this.setCustomerDetailScrollLock(true);
      this.detailModal = {
        open:     true,
        customer: { ...customer, pets: [] },
        loading:  true,
        error:    "",
      };
      this.refreshIcons();

      try {
        const data = await API.getCustomerDetails(customer.id);
        this.detailModal.customer = data.customer || this.detailModal.customer;
      } catch (err) {
        this.detailModal.error = err.message || "Failed to load customer details.";
      } finally {
        this.detailModal.loading = false;
        this.refreshIcons();
      }
    },

    closeCustomerDetails() {
      this.detailModal.open = false;
      this.detailModal.error = "";
      this.setCustomerDetailScrollLock(false);
    },

    setCustomerDetailScrollLock(locked) {
      document.documentElement.classList.toggle("customer-detail-modal-open", locked);
      document.body.classList.toggle("customer-detail-modal-open", locked);
    },

    // ── Avatar helpers ────────────────────────────────────

    getInitials(fullName) {
      if (!fullName) return "?";
      const parts = fullName.trim().split(/\s+/);
      if (parts.length === 1) return parts[0].charAt(0).toUpperCase();
      return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
    },

    getAvatarColor(fullName) {
      // Deterministic color from name — same name always gets the same color
      const palette = [
        "#315b7e", "#4a7fa5", "#63a89d", "#7b6fa0",
        "#a06b5b", "#5b7a4a", "#7a5b8a", "#8a7a4a",
        "#4a6b8a", "#6b4a7a",
      ];
      let hash = 0;
      for (let i = 0; i < (fullName || "").length; i++) {
        hash = fullName.charCodeAt(i) + ((hash << 5) - hash);
      }
      return palette[Math.abs(hash) % palette.length];
    },

    // ── Date formatting ───────────────────────────────────

    formatDate(dateStr) {
      if (!dateStr) return "—";
      try {
        return new Intl.DateTimeFormat("en-PH", {
          year: "numeric", month: "short", day: "numeric",
        }).format(new Date(dateStr));
      } catch {
        return dateStr;
      }
    },

    // ── Lucide refresh ────────────────────────────────────

    formatMobileNumber(value) {
      const text = String(value ?? "").trim();
      if (!text || text === "—") return "—";

      const digits = text.replace(/\D/g, "");
      if (digits.length === 11) {
        return `${digits.slice(0, 4)}-${digits.slice(4, 7)}-${digits.slice(7)}`;
      }

      return text;
    },

    formatTextValue(value) {
      const text = String(value ?? "").trim();
      return text || "Not provided";
    },

    formatLabel(value) {
      const text = String(value ?? "").trim();
      if (!text) return "Not provided";

      return text
        .replace(/_/g, " ")
        .split(" ")
        .filter(Boolean)
        .map(part => part.charAt(0).toUpperCase() + part.slice(1))
        .join(" ");
    },

    formatWeight(value) {
      if (value === null || value === undefined || value === "") return "Not provided";
      const amount = Number(value);
      if (!Number.isFinite(amount)) return String(value);

      return `${amount.toLocaleString("en-PH", { maximumFractionDigits: 2 })} kg`;
    },

    customerStatusLabel(customer) {
      if (customer?.isArchived) return "Archived";
      if (customer?.isActive) return "Active";
      return "Inactive";
    },

    refreshIcons() {
      this.$nextTick(() => {
        if (window.lucide) window.lucide.createIcons();
      });
    },
  };
}
