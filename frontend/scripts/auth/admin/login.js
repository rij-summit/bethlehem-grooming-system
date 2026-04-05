document.addEventListener("DOMContentLoaded", () => {
  const adminLoginForm = document.getElementById("adminLoginForm");

  if (!adminLoginForm || !window.BethlehemApi) return;

  adminLoginForm.addEventListener("submit", async (e) => {
    e.preventDefault();

    const email = document.getElementById("adminEmail").value.trim();
    const password = document.getElementById("adminPassword").value.trim();

    if (!email || !password) {
      alert("Please fill in all fields.");
      return;
    }

    const submitButton = adminLoginForm.querySelector('button[type="submit"]');
    if (submitButton) {
      submitButton.disabled = true;
      submitButton.textContent = "Logging in...";
    }

    try {
      const data = await window.BethlehemApi.login({ email, password });

      if (data?.user?.role !== "admin") {
        window.BethlehemApi.clearSession();
        alert("This account is not authorized for admin access.");
        return;
      }

      if (data?.token && data?.user) {
        window.BethlehemApi.setSession(data.token, data.user);
      }

      window.location.href = "./dashboard.html";
    } catch (error) {
      alert(error.message || "Admin login failed.");
    } finally {
      if (submitButton) {
        submitButton.disabled = false;
        submitButton.textContent = "Log In as Admin";
      }
    }
  });
});
