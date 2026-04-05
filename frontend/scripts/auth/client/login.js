document.addEventListener("DOMContentLoaded", () => {
  const customerLoginForm = document.getElementById("customerLoginForm");

  if (!customerLoginForm || !window.BethlehemApi) return;

  customerLoginForm.addEventListener("submit", async (e) => {
    e.preventDefault();

    const email = document.getElementById("email").value.trim();
    const password = document.getElementById("password").value.trim();

    if (!email || !password) {
      alert("Please fill in all fields.");
      return;
    }

    const submitButton = customerLoginForm.querySelector('button[type="submit"]');
    if (submitButton) {
      submitButton.disabled = true;
      submitButton.textContent = "Logging in...";
    }

    try {
      const data = await window.BethlehemApi.login({ email, password });

      if (data?.token && data?.user) {
        window.BethlehemApi.setSession(data.token, data.user);
      }

      if (data?.user?.role === "admin") {
        window.location.href = "../admin/dashboard.html";
        return;
      }

      window.location.href = "./dashboard.html";
    } catch (error) {
      alert(error.message || "Customer login failed.");
    } finally {
      if (submitButton) {
        submitButton.disabled = false;
        submitButton.textContent = "Log In";
      }
    }
  });
});
