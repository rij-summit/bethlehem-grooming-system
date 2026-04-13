function adminSidebar() {
  return {
    sidebarOpen: false,
    activePage: "dashboard",

    init() {
      this.detectActivePage();

      // BACKEND/FRONTEND NOTE:
      // Lucide icons are re-rendered after Alpine initializes the DOM.
      if (window.lucide) {
        window.lucide.createIcons();
      }

      // Re-render icons whenever Alpine finishes updates.
      this.$nextTick(() => {
        if (window.lucide) {
          window.lucide.createIcons();
        }
      });
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
      } else if (currentPath.includes("settings.html")) {
        this.activePage = "settings";
      }
    },

    async handleLogout() {
      try {
        await API.logout("admin");
      } finally {
        // Always redirect even if the API call fails (token is already cleared by api.js)
        window.location.href = "../../pages/admin/login.html";
      }
    },
  };
}
