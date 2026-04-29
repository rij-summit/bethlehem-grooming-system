// Connected to pages/client/sign-in.html
// Depends on: api.js (loaded before this script in the HTML)
// Team note: pages/admin/login.html and pages/client/login.html were removed
// intentionally. All frontend login entry points and auth redirects should
// point to the shared sign-in page above.

document.addEventListener("DOMContentLoaded", () => {
  const signInForm   = document.getElementById("sharedSignInForm");
  const identifierInput = document.getElementById("identifier");
  const passwordInput   = document.getElementById("password");
  const messageBox      = document.getElementById("sharedSignInMessage");
  const submitButton    = document.getElementById("sharedSignInSubmit");
  const rememberMeCheckbox = document.getElementById("rememberMeCheckbox");
  const togglePasswordVisibilityButton = document.getElementById(
    "toggleSharedPasswordVisibility"
  );

  if (!signInForm || !identifierInput || !passwordInput) return;

  if (window.lucide) {
    window.lucide.createIcons();
  }

  const passwordIconClosed = document.querySelector(".shared-password-icon-closed");
  const passwordIconOpen   = document.querySelector(".shared-password-icon-open");

  if (togglePasswordVisibilityButton) {
    togglePasswordVisibilityButton.addEventListener("click", function () {
      const shouldShowPassword = passwordInput.type === "password";
      const nextLabel = shouldShowPassword ? "Hide password" : "Show password";

      passwordInput.type = shouldShowPassword ? "text" : "password";
      this.setAttribute("aria-label", nextLabel);
      this.setAttribute("title", nextLabel);

      if (passwordIconClosed && passwordIconOpen) {
        passwordIconClosed.classList.toggle("hidden", shouldShowPassword);
        passwordIconOpen.classList.toggle("hidden", !shouldShowPassword);
      }
    });
  }

  let countdownTimer = null;

  signInForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (countdownTimer) return; // rate-limit lockout still active

    const identifier = identifierInput.value.trim();
    const password   = passwordInput.value;
    const rememberMe = rememberMeCheckbox?.checked ?? true;

    if (!identifier || !password) {
      showMessage("error", "Please fill in both fields.");
      return;
    }

    setBusyState(true);
    showMessage(null, "");

    try {
      const response = await API.signIn(identifier, password, rememberMe);
      const role = response?.user?.role;

      if (role === "admin" || role === "staff") {
        window.location.href = "../admin/dashboard.html";
        return;
      }

      window.location.href = "../client/dashboard.html";
    } catch (error) {
      if (error.status === 429 && error.retryAfter) {
        startCountdown(error.retryAfter);
      } else {
        showMessage("error", error.message || "Unable to sign in.");
      }
    } finally {
      if (!countdownTimer) setBusyState(false);
    }
  });

  function startCountdown(seconds) {
    clearInterval(countdownTimer);
    setBusyState(true);
    let remaining = seconds;

    function tick() {
      if (remaining <= 0) {
        clearInterval(countdownTimer);
        countdownTimer = null;
        setBusyState(false);
        showMessage(null, "");
        return;
      }
      showMessage("lockout", `Too many attempts. Try again in ${remaining}s.`);
      remaining--;
    }

    tick();
    countdownTimer = setInterval(tick, 1000);
  }

  function setBusyState(isBusy) {
    submitButton.disabled = isBusy;
    const label = document.getElementById("signInBtnLabel");
    if (label) label.textContent = isBusy ? "Signing In..." : "Sign In";
  }

  function showMessage(type, text) {
    if (!type) {
      messageBox.className = "hidden rounded-2xl border px-4 py-3 text-sm";
      messageBox.textContent = "";
      return;
    }

    const styles = {
      error:   "rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700",
      success: "rounded-2xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700",
      lockout: "rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700",
    };

    messageBox.className = styles[type] || styles.error;
    messageBox.textContent = text;
  }
});
