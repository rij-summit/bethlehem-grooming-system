// Connected to pages/client/settings.html
// Depends on: api.js

document.addEventListener("DOMContentLoaded", () => {
  if (window.lucide) window.lucide.createIcons();

  if (!API.hasAuthenticatedSession("customer")) {
    API.redirectToSignIn();
    return;
  }

  const mobileSidebarQuery = window.matchMedia("(max-width: 1180px)");
  const sidebarToggle = document.getElementById("clientSidebarToggle");
  const sidebarClose = document.getElementById("clientSidebarClose");
  const sidebarBackdrop = document.getElementById("clientSidebarBackdrop");
  const sidebar = document.getElementById("clientSidebar");
  const profileName = document.getElementById("clientProfileName");
  const profileInitials = document.getElementById("clientProfileInitials");
  const logoutBtn = document.getElementById("clientLogoutBtn");

  const statusBox = document.getElementById("settingsStatus");
  const passwordForm = document.getElementById("passwordForm");
  const passwordClearBtn = document.getElementById("passwordClearBtn");
  const newPasswordInput = document.getElementById("newPassword");
  const passwordStrength = document.getElementById("passwordStrength");

  const firstNameInput = document.getElementById("settingsFirstName");
  const lastNameInput = document.getElementById("settingsLastName");
  const phoneInput = document.getElementById("settingsPhone");
  const emailInput = document.getElementById("settingsEmail");
  const accountInitials = document.getElementById("accountInitials");
  const accountDisplayName = document.getElementById("accountDisplayName");
  const accountMeta = document.getElementById("accountMeta");

  let serverUser = null;
  let profileLoadPromise = null;

  function scheduleSettingsIdleTask(task) {
    if (typeof window.requestIdleCallback === "function") {
      window.requestIdleCallback(task, { timeout: 800 });
      return;
    }

    window.setTimeout(task, 100);
  }

  setupSidebar();
  setupTabs();
  setupPasswordToggles();
  setupForms();
  window.requestAnimationFrame(() => scheduleSettingsIdleTask(loadProfile));

  function setupSidebar() {
    if (!sidebarToggle || !sidebarClose || !sidebarBackdrop || !sidebar) return;

    const sidebarLinks = sidebar.querySelectorAll("a");

    function setSidebarState(isOpen) {
      document.body.classList.toggle("client-sidebar-open", isOpen);
      sidebarToggle.setAttribute("aria-expanded", String(isOpen));
      sidebarToggle.setAttribute(
        "aria-label",
        isOpen ? "Close navigation menu" : "Open navigation menu",
      );
    }

    function closeSidebar() {
      setSidebarState(false);
    }

    function toggleSidebar() {
      if (!mobileSidebarQuery.matches) return;
      const isOpen = document.body.classList.contains("client-sidebar-open");
      setSidebarState(!isOpen);
    }

    setSidebarState(false);
    sidebarToggle.addEventListener("click", toggleSidebar);
    sidebarClose.addEventListener("click", closeSidebar);
    sidebarBackdrop.addEventListener("click", closeSidebar);
    sidebarLinks.forEach((link) => link.addEventListener("click", closeSidebar));
    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") closeSidebar();
    });
    mobileSidebarQuery.addEventListener("change", (event) => {
      if (!event.matches) closeSidebar();
    });
  }

  function setupTabs() {
    const tabs = document.querySelectorAll("[data-settings-tab]");
    const panels = document.querySelectorAll("[data-settings-panel]");

    tabs.forEach((tab) => {
      tab.addEventListener("click", () => {
        const target = tab.dataset.settingsTab;
        hideStatus();

        tabs.forEach((item) => {
          const isActive = item.dataset.settingsTab === target;
          item.setAttribute("aria-selected", String(isActive));
          item.classList.toggle("bg-[#315b7e]", isActive);
          item.classList.toggle("text-white", isActive);
          item.classList.toggle("shadow-sm", isActive);
          item.classList.toggle("text-[#2f4b66]", !isActive);
          item.classList.toggle("hover:bg-white", !isActive);
        });

        panels.forEach((panel) => {
          panel.classList.toggle("hidden", panel.dataset.settingsPanel !== target);
        });
      });
    });
  }

  function setupPasswordToggles() {
    document.querySelectorAll("[data-password-toggle]").forEach((button) => {
      button.addEventListener("click", () => {
        const input = document.getElementById(button.dataset.passwordToggle);
        if (!input) return;

        const shouldShow = input.type === "password";
        input.type = shouldShow ? "text" : "password";
        button.setAttribute("aria-label", shouldShow ? "Hide password" : "Show password");
        button.setAttribute("title", shouldShow ? "Hide password" : "Show password");

        const closedIcon = button.querySelector(".password-icon-closed");
        const openIcon = button.querySelector(".password-icon-open");
        closedIcon?.classList.toggle("hidden", shouldShow);
        openIcon?.classList.toggle("hidden", !shouldShow);
      });
    });
  }

  function setupForms() {
    logoutBtn?.addEventListener("click", handleLogout);
    newPasswordInput?.addEventListener("input", updatePasswordStrength);

    passwordForm?.addEventListener("submit", (event) => {
      event.preventDefault();
      validatePasswordForm();
    });

    passwordClearBtn?.addEventListener("click", () => {
      passwordForm?.reset();
      updatePasswordStrength();
      hideStatus();
    });
  }

  function loadProfile() {
    if (serverUser) return Promise.resolve(serverUser);
    if (profileLoadPromise) return profileLoadPromise;

    profileLoadPromise = (async () => {
      try {
        const { user } = await API.getMe("customer");
        serverUser = user || {};
        applyUserToAccount(serverUser);
        return serverUser;
      } catch (error) {
        if (API.isAuthenticationError(error) || !API.hasAuthenticatedSession("customer")) {
          API.redirectToSignIn();
          return null;
        }

        showStatus("Unable to load your account right now. Please try again.", "error");
        return null;
      } finally {
        profileLoadPromise = null;
      }
    })();

    return profileLoadPromise;
  }

  function applyUserToAccount(user) {
    firstNameInput.value = user.first_name || "";
    lastNameInput.value = user.last_name || "";
    phoneInput.value = formatMobileNumber(user.phone) || "Not provided";
    emailInput.value = user.email || "Not provided";

    updateVisibleProfile(user);
  }

  function formatMobileNumber(value) {
    const text = String(value ?? "").trim();
    if (!text || text === "—" || text === "Not provided") return "";

    const digits = text.replace(/\D/g, "");
    const localDigits = digits.startsWith("639") && digits.length === 12
      ? `0${digits.slice(2)}`
      : digits;

    if (localDigits.length === 11) {
      return `${localDigits.slice(0, 4)}-${localDigits.slice(4, 7)}-${localDigits.slice(7)}`;
    }

    return text;
  }

  function updateVisibleProfile(user) {
    const firstName = user.first_name || "";
    const lastName = user.last_name || "";
    const fullName = `${firstName} ${lastName}`.trim() || user.username || "Customer";
    const initials = getInitials(firstName, lastName, user.username);
    const joinedLabel = formatJoinedDate(user.created_at || user.joined_at);

    if (profileName) profileName.textContent = fullName;
    if (profileInitials) profileInitials.textContent = initials;
    if (accountInitials) accountInitials.textContent = initials;
    if (accountDisplayName) accountDisplayName.textContent = fullName;
    if (accountMeta) {
      accountMeta.textContent = joinedLabel ? `Pet Owner - Joined ${joinedLabel}` : "Pet Owner";
    }
  }

  function validatePasswordForm() {
    const currentPassword = document.getElementById("currentPassword").value;
    const newPassword = document.getElementById("newPassword").value;
    const confirmNewPassword = document.getElementById("confirmNewPassword").value;

    if (!currentPassword || !newPassword || !confirmNewPassword) {
      showStatus("Please complete all password fields.", "error");
      return;
    }

    if (newPassword.length < 8) {
      showStatus("New password must be at least 8 characters.", "error");
      return;
    }

    if (newPassword === currentPassword) {
      showStatus("New password must be different from the current password.", "error");
      return;
    }

    if (newPassword !== confirmNewPassword) {
      showStatus("New password and confirmation do not match.", "error");
      return;
    }

    // BACKEND: Send current_password, password, and password_confirmation here.
    passwordForm.reset();
    updatePasswordStrength();
    showStatus("Password details passed validation.", "success");
  }

  function updatePasswordStrength() {
    if (!passwordStrength || !newPasswordInput) return;

    const value = newPasswordInput.value;
    if (!value) {
      passwordStrength.textContent = "Use at least 8 characters.";
      passwordStrength.className = "mt-2 text-xs text-slate-500";
      return;
    }

    const hasLength = value.length >= 8;
    const hasNumber = /\d/.test(value);
    const hasLetter = /[A-Za-z]/.test(value);
    const score = [hasLength, hasNumber, hasLetter].filter(Boolean).length;

    if (score === 3) {
      passwordStrength.textContent = "Strong password.";
      passwordStrength.className = "mt-2 text-xs text-green-700";
    } else if (hasLength) {
      passwordStrength.textContent = "Good start. Add letters and numbers for a stronger password.";
      passwordStrength.className = "mt-2 text-xs text-amber-700";
    } else {
      passwordStrength.textContent = "Password is too short.";
      passwordStrength.className = "mt-2 text-xs text-red-700";
    }
  }

  async function handleLogout() {
    try {
      await API.logout("customer");
    } finally {
      API.redirectToSignIn({ replace: true });
    }
  }

  function getInitials(firstName, lastName, username) {
    const initials = `${firstName[0] || ""}${lastName[0] || ""}`.toUpperCase();
    return initials || (username || "CU").slice(0, 2).toUpperCase();
  }

  function formatJoinedDate(value) {
    if (!value) return "";

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return "";

    return date.toLocaleDateString("en-PH", {
      year: "numeric",
      month: "long",
      day: "numeric",
    });
  }

  function showStatus(message, type) {
    if (!statusBox) return;

    statusBox.textContent = message;
    statusBox.className = "rounded-xl border px-4 py-3 text-sm font-medium";

    if (type === "error") {
      statusBox.classList.add("border-red-200", "bg-red-50", "text-red-700");
    } else {
      statusBox.classList.add("border-green-200", "bg-green-50", "text-green-700");
    }

    statusBox.classList.remove("hidden");
  }

  function hideStatus() {
    statusBox?.classList.add("hidden");
  }
});
