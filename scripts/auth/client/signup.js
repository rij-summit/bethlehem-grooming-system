// Connected to pages/client/signup.html
// Depends on: api.js (loaded before this script in the HTML)

document.addEventListener("DOMContentLoaded", () => {
  const signupForm          = document.getElementById("signupForm");
  const signupMessage       = document.getElementById("signupMessage");
  const submitButton        = document.getElementById("signupSubmit");
  const usernameInput       = document.getElementById("username");
  const phoneInput          = document.getElementById("phone");
  const emailInput          = document.getElementById("email");
  const passwordInput       = document.getElementById("password");
  const confirmPasswordInput = document.getElementById("confirmPassword");

  if (!signupForm || !phoneInput || !emailInput) return;

  // Independent toggle — each button only controls its own field and icons.
  const toggleMap = [
    {
      button:  document.getElementById("togglePasswordVisibility"),
      input:   passwordInput,
      closed:  ".password-icon-closed",
      open:    ".password-icon-open",
    },
    {
      button:  document.getElementById("toggleConfirmPasswordVisibility"),
      input:   confirmPasswordInput,
      closed:  ".password-icon-closed",
      open:    ".password-icon-open",
    },
  ];

  toggleMap.forEach(({ button, input, closed, open }) => {
    if (!button || !input) return;
    button.addEventListener("click", function () {
      const show      = input.type === "password";
      const nextLabel = show ? "Hide password" : "Show password";

      input.type = show ? "text" : "password";
      this.setAttribute("aria-label", nextLabel);
      this.setAttribute("title", nextLabel);

      const iconClosed = this.querySelector(closed);
      const iconOpen   = this.querySelector(open);
      if (iconClosed && iconOpen) {
        iconClosed.classList.toggle("hidden", show);
        iconOpen.classList.toggle("hidden", !show);
      }
    });
  });

  // Strip non-digits, cap at 11, enforce 09 prefix in real time.
  phoneInput.addEventListener("input", function () {
    const digitsOnly = this.value.replace(/\D/g, "").slice(0, 11);
    const isValid    = digitsOnly.length === 0 || /^09\d{0,9}$/.test(digitsOnly);

    this.value = digitsOnly;
    this.setCustomValidity(
      isValid ? "" : "Phone number must start with 09 and be 11 digits."
    );
  });

  signupForm.addEventListener("submit", async function (e) {
    e.preventDefault();

    const firstName      = document.getElementById("firstName").value.trim();
    const lastName       = document.getElementById("lastName").value.trim();
    const username       = usernameInput ? usernameInput.value.trim() : "";
    const email          = emailInput.value.trim();
    const phone          = phoneInput.value.trim();
    const password       = passwordInput.value;
    const confirmPassword = confirmPasswordInput.value;

    clearMessage();

    if (!phone) {
      showMessage("error", "Mobile number is required.");
      phoneInput.focus();
      return;
    }

    if (!email) {
      showMessage("error", "Email address is required.");
      emailInput.focus();
      return;
    }

    if (!/^09\d{9}$/.test(phone)) {
      showMessage("error", "Phone number must start with 09 and be exactly 11 digits (e.g. 09XXXXXXXXX).");
      phoneInput.focus();
      return;
    }

    if (!emailInput.checkValidity()) {
      emailInput.reportValidity();
      emailInput.focus();
      return;
    }

    if (password !== confirmPassword) {
      showMessage("error", "Passwords do not match.");
      return;
    }

    if (password.length < 8) {
      showMessage("error", "Password must be at least 8 characters long.");
      return;
    }

    setBusyState(true);

    try {
      const data = await API.register({
        first_name:            firstName,
        last_name:             lastName,
        username:              username || null,
        email:                 email || null,
        phone:                 phone || null,
        password,
        password_confirmation: confirmPassword,
      });

      if (data?.requires_verification) {
        sessionStorage.setItem("pendingVerificationEmail", data.email ?? email);
        if (data.email_delivery_queued === false) {
          sessionStorage.setItem("pendingVerificationDeliveryFailed", "1");
        } else {
          sessionStorage.removeItem("pendingVerificationDeliveryFailed");
        }
        window.location.replace("./verify-email.html");
        return;
      }

      // Registration never implies authentication, including when talking to
      // an older backend response that omits requires_verification.
      window.location.replace("./sign-in.html?registered=1");
    } catch (error) {
      if (error.errors) {
        const firstError = Object.values(error.errors)[0];
        showMessage("error", Array.isArray(firstError) ? firstError[0] : firstError);
      } else {
        showMessage("error", error.message);
      }
      setBusyState(false);
    }
  });

  function setBusyState(isBusy) {
    submitButton.disabled = isBusy;
    const label = document.getElementById("signupBtnLabel");
    if (label) label.textContent = isBusy ? "Submitting Registration..." : "Create Account";
  }

  function clearMessage() {
    signupMessage.className = "mt-5 hidden rounded-xl border px-4 py-3 text-sm";
    signupMessage.textContent = "";
  }

  function showMessage(type, text) {
    const styles = {
      error:   "border-red-200 bg-red-50 text-red-700",
      success: "border-green-200 bg-green-50 text-green-700",
    };
    signupMessage.className = `mt-5 rounded-xl border px-4 py-3 text-sm ${styles[type] || styles.error}`;
    signupMessage.textContent = text;
  }
});
