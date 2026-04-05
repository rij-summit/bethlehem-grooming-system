document.addEventListener("DOMContentLoaded", () => {
  const signupForm = document.getElementById("signupForm");
  const signupMessage = document.getElementById("signupMessage");
  const submitButton = signupForm?.querySelector('button[type="submit"]');

  if (!signupForm || !signupMessage || !window.BethlehemApi) return;

  function showMessage(message, type) {
    signupMessage.className = "mt-5 rounded-xl border px-4 py-3 text-sm";

    if (type === "error") {
      signupMessage.classList.add("border-red-200", "bg-red-50", "text-red-700");
    } else {
      signupMessage.classList.add(
        "border-green-200",
        "bg-green-50",
        "text-green-700",
      );
    }

    signupMessage.textContent = message;
    signupMessage.classList.remove("hidden");
  }

  signupForm.addEventListener("submit", async (e) => {
    e.preventDefault();

    const firstName = document.getElementById("firstName").value.trim();
    const lastName = document.getElementById("lastName").value.trim();
    const email = document.getElementById("email").value.trim();
    const phone = document.getElementById("phone").value.trim();
    const password = document.getElementById("password").value;
    const confirmPassword = document.getElementById("confirmPassword").value;

    if (password !== confirmPassword) {
      showMessage("Passwords do not match.", "error");
      return;
    }

    if (password.length < 6) {
      showMessage("Password must be at least 6 characters long.", "error");
      return;
    }

    const payload = {
      first_name: firstName,
      last_name: lastName,
      email,
      phone,
      password,
      password_confirmation: confirmPassword,
    };

    if (submitButton) {
      submitButton.disabled = true;
      submitButton.textContent = "Creating account...";
    }

    try {
      const data = await window.BethlehemApi.register(payload);

      if (data?.token && data?.user) {
        window.BethlehemApi.setSession(data.token, data.user);
      }

      showMessage(
        data?.message || "Account created successfully. Redirecting to login...",
        "success",
      );

      signupForm.reset();

      setTimeout(() => {
        window.location.href = "./login.html";
      }, 1200);
    } catch (error) {
      showMessage(error.message || "Unable to register right now.", "error");
    } finally {
      if (submitButton) {
        submitButton.disabled = false;
        submitButton.textContent = "Create Account";
      }
    }
  });
});
