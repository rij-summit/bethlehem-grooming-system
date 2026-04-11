// Connected to pages/admin/login.html
if (adminLoginForm) {
  adminLoginForm.addEventListener("submit", async function (e) {
    e.preventDefault();

    const email = document.getElementById("adminEmail").value.trim();
    const password = document.getElementById("adminPassword").value.trim();

    if (!email || !password) {
      alert("Please fill in all fields.");
      return;
    }

    /*
    // USE THIS WHEN THE BACKEND ADMIN LOGIN API IS READY
    const ADMIN_LOGIN_API_URL = "YOUR_BACKEND_ADMIN_LOGIN_URL";

    try {
      const response = await fetch(ADMIN_LOGIN_API_URL, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({ email, password }),
      });

      const data = await response.json().catch(() => ({}));

      if (!response.ok) {
        alert(data.message || "Admin login failed.");
        return;
      }

      window.location.href = "./dashboard.html";
      return;
    } catch (error) {
      console.error("Admin login error:", error);
      alert("Unable to reach the admin login service.");
      return;
    }
    */

    // TEMPORARY: Delete this once the backend admin login API is ready.
    alert("Temporary admin login only.");
    window.location.href = "./dashboard.html";
  });
}
