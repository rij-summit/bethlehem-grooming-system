// Connected to pages/client/signup.html
// Depends on: api.js (loaded before this script in the HTML)

document.addEventListener("DOMContentLoaded", () => {
  const signupForm = document.getElementById("signupForm");
  const signupMessage = document.getElementById("signupMessage");
  const phoneInput = document.getElementById("phone");

  if (!signupForm) return;

  // ── Phone input masking ───────────────────────────────────────────────────
  // Strip non-digits, cap at 11, enforce 09 prefix in real time
  phoneInput.addEventListener("input", function () {
    const digitsOnly = this.value.replace(/\D/g, "").slice(0, 11);
    const isValid =
      digitsOnly.length === 0 || /^09\d{0,9}$/.test(digitsOnly);

    this.value = digitsOnly;
    this.setCustomValidity(
      isValid
        ? ""
        : "Phone number must start with 09 and be 11 digits."
    );
  });

  signupForm.addEventListener("submit", async function (e) {
    e.preventDefault();

    const firstName = document.getElementById("firstName").value.trim();
    const lastName = document.getElementById("lastName").value.trim();
    const email = document.getElementById("email").value.trim();
    const phone = phoneInput.value.trim();
    const password = document.getElementById("password").value;
    const confirmPassword = document.getElementById("confirmPassword").value;

    signupMessage.className = "mt-5 rounded-xl border px-4 py-3 text-sm";

    // ── Phone validation ──────────────────────────────────────────────────
    const phonePattern = /^09\d{9}$/;
    if (!phonePattern.test(phone)) {
      showMessage(
        signupMessage,
        "error",
        "Phone number must start with 09 and be exactly 11 digits (e.g. 09XXXXXXXXX)."
      );
      phoneInput.focus();
      return;
    }

    if (password !== confirmPassword) {
      showMessage(signupMessage, "error", "Passwords do not match.");
      return;
    }

    if (password.length < 8) {
      showMessage(
        signupMessage,
        "error",
        "Password must be at least 8 characters long."
      );
      return;
    }

    try {
      await API.register({
        first_name: firstName,
        last_name: lastName,
        email,
        phone,
        password,
        password_confirmation: confirmPassword,
      });

      showMessage(
        signupMessage,
        "success",
        "Account created successfully. You can now log in."
      );
      signupForm.reset();

      setTimeout(() => {
        window.location.href = "./login.html";
      }, 1500);
    } catch (error) {
      // 422 from Laravel includes field-level validation errors
      if (error.errors) {
        const firstError = Object.values(error.errors)[0];
        showMessage(
          signupMessage,
          "error",
          Array.isArray(firstError) ? firstError[0] : firstError
        );
      } else {
        showMessage(signupMessage, "error", error.message);
      }
    }
  });

  function showMessage(el, type, text) {
    const styles = {
      error: "border-red-200 bg-red-50 text-red-700",
      success: "border-green-200 bg-green-50 text-green-700",
    };
    el.classList.add(...styles[type].split(" "));
    el.textContent = text;
    el.classList.remove("hidden");
  }
});
