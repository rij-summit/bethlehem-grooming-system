document.addEventListener("alpine:init", () => {
  let resolveDialog = null;
  let toastTimer = null;

  Alpine.store("clinicAccess", {
    permissions: {},
    user: null,
    ready: null,
    init() {
      return this.ready ??= this.load();
    },
    async load() {
      try {
        const response = await API.getStaffIdentity();
        this.user = response.user || null;
        this.permissions = response.user?.clinic_permissions || {};
      } catch {
        this.permissions = {};
      }
    },
  });

  Alpine.store("clinicConfinementClock", {
    now: Date.now(),
    init() { this.timer = setInterval(() => { this.now = Date.now(); }, 60000); },
  });

  Alpine.store("clinicFeedback", {
    dialog: { open: false, title: "", message: "", confirmLabel: "Confirm", cancelLabel: "Cancel", danger: false },
    toast: { show: false, message: "", variant: "success" },
    toastDuration: 3500,

    confirm({ title, message, confirmLabel = "Confirm", cancelLabel = "Cancel", danger = false, dismissOnEscape = true }) {
      resolveDialog?.(false);
      this.dialog = { open: true, title, message, confirmLabel, cancelLabel, danger, dismissOnEscape };
      return new Promise((resolve) => { resolveDialog = resolve; });
    },

    answer(confirmed) {
      this.dialog.open = false;
      resolveDialog?.(confirmed);
      resolveDialog = null;
    },

    notify(message, variant = "success") {
      clearTimeout(toastTimer);
      // Keep legacy boolean callers, including cached components, compatible.
      if (typeof variant === "boolean") variant = variant ? "success" : "error";
      this.toast = { show: true, message, variant };
      toastTimer = setTimeout(() => { this.toast.show = false; }, this.toastDuration);
    },
  });
});

