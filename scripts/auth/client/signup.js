// Connected to pages/client/signup.html
// Depends on: api.js (loaded before this script in the HTML)

document.addEventListener("DOMContentLoaded", () => {
  const signupForm = document.getElementById("signupForm");
  const signupMessage = document.getElementById("signupMessage");

  if (!signupForm) return;

  signupForm.addEventListener("submit", async function (e) {
    e.preventDefault();

    const firstName = document.getElementById("firstName").value.trim();
    const lastName = document.getElementById("lastName").value.trim();
    const email = document.getElementById("email").value.trim();
    const phone = document.getElementById("phone").value.trim();
    const password = document.getElementById("password").value;
    const confirmPassword = document.getElementById("confirmPassword").value;

    signupMessage.className = "mt-5 rounded-xl border px-4 py-3 text-sm";

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
