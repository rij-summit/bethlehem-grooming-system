// Connected to pages/sign-in/sign_in.html
// Depends on: api.js (loaded before this script in the HTML)
// Team note: pages/admin/login.html and pages/client/login.html were removed
// intentionally. All frontend login entry points and auth redirects should
// point to the shared sign-in page above.

document.addEventListener("DOMContentLoaded", () => {
  const signInForm = document.getElementById("sharedSignInForm");
  const identifierInput = document.getElementById("identifier");
  const passwordInput = document.getElementById("password");
  const messageBox = document.getElementById("sharedSignInMessage");
  const submitButton = document.getElementById("sharedSignInSubmit");
  const togglePasswordVisibilityButton = document.getElementById(
    "toggleSharedPasswordVisibility"
  );

  if (!signInForm || !identifierInput || !passwordInput) return;

  if (window.lucide) {
    window.lucide.createIcons();
  }

  const passwordIconClosed = document.querySelector(
    ".shared-password-icon-closed"
  );
  const passwordIconOpen = document.querySelector(".shared-password-icon-open");

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

  signInForm.addEventListener("submit", async (event) => {
    event.preventDefault();

    const identifier = identifierInput.value.trim();
    const password = passwordInput.value;

    if (!identifier || !password) {
      showMessage("error", "Please fill in both fields.");
      return;
    }

    setBusyState(true);
    showMessage(null, "");

    try {
      /*
        BACKEND HANDOFF:
        API.signIn currently does temporary frontend routing so this shared page
        can work against the existing split login endpoints. Once backend ships a
        real identifier-aware sign-in endpoint, this page should keep calling
        API.signIn, but the helper can stop guessing based on input shape.
      */
      const response = await API.signIn(identifier, password);
      const role = response?.user?.role;

      if (role === "admin" || role === "staff") {
        window.location.href = "../admin/dashboard.html";
        return;
      }

      window.location.href = "../client/dashboard.html";
    } catch (error) {
      showMessage("error", error.message || "Unable to sign in.");
    } finally {
      setBusyState(false);
    }
  });

  function setBusyState(isBusy) {
    submitButton.disabled = isBusy;
    submitButton.textContent = isBusy ? "Signing In..." : "Sign In";
  }

  function showMessage(type, text) {
    if (!type) {
      messageBox.className = "hidden rounded-2xl border px-4 py-3 text-sm";
      messageBox.textContent = "";
      return;
    }

    const styles = {
      error: "rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700",
      success:
        "rounded-2xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700",
    };

    messageBox.className = styles[type] || styles.error;
    messageBox.textContent = text;
  }
});
