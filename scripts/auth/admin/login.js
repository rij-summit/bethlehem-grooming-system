// Connected to pages/admin/login.html
// Depends on: api.js (loaded before this script in the HTML)

const adminLoginForm = document.getElementById("adminLoginForm");

if (adminLoginForm) {
  adminLoginForm.addEventListener("submit", async function (e) {
    e.preventDefault();

    const email = document.getElementById("adminEmail").value.trim();
    const password = document.getElementById("adminPassword").value.trim();

    if (!email || !password) {
      alert("Please fill in all fields.");
      return;
    }

    try {
      await API.adminLogin(email, password);
      window.location.href = "./dashboard.html";
    } catch (error) {
      alert(error.message);
    }
  });
}
