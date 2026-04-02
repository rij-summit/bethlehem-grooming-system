// Connected to pages/client/login.html
const customerLoginForm = document.getElementById("customerLoginForm");
const adminLoginForm = document.getElementById("adminLoginForm");

if (customerLoginForm) {
  customerLoginForm.addEventListener("submit", async function (e) {
    e.preventDefault();

    const email = document.getElementById("email").value.trim();
    const password = document.getElementById("password").value.trim();

    if (!email || !password) {
      alert("Please fill in all fields.");
      return;
    }

    /*
    // USE THIS WHEN THE BACKEND LOGIN API IS READY
    const CUSTOMER_LOGIN_API_URL = "YOUR_BACKEND_LOGIN_URL";

    try {
      const response = await fetch(CUSTOMER_LOGIN_API_URL, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({ email, password }),
      });

      const data = await response.json().catch(() => ({}));

      if (!response.ok) {
        alert(data.message || "Customer login failed.");
        return;
      }

      window.location.href = "./dashboard.html";
      return;
    } catch (error) {
      console.error("Customer login error:", error);
      alert("Unable to reach the customer login service.");
      return;
    }
    */

    // TEMPORARY: Delete this once the backend login API is ready.
    alert("Temporary customer login only.");
    window.location.href = "./dashboard.html";
  });
}
