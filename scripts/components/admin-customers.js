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

    // ── Init ──────────────────────────────────────────────

    async init() {
      await this.loadCustomers();
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
      this.resetModal = { open: true, customer };
      this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
    },

    closeReset() {
      this.resetModal.open = false;
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

    refreshIcons() {
      this.$nextTick(() => {
        if (window.lucide) window.lucide.createIcons();
      });
    },
  };
}
