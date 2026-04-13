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

    handleLogout() {
      // TEMPORARY FRONTEND-ONLY LOGIC:
      // Backend developer should replace this with actual logout API/session destroy logic.
      // Example:
      // 1. Call logout endpoint
      // 2. Clear auth token/session
      // 3. Redirect to login page after successful logout

      window.location.href = "../client/login.html";
    },
  };
}
