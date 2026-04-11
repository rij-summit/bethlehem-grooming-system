// Connected to pages/client/login.html
const customerLoginForm = document.getElementById("customerLoginForm");
const adminLoginForm = document.getElementById("adminLoginForm");
const customerPhoneInput = document.getElementById("phone");

if (customerPhoneInput && customerLoginForm) {
  customerPhoneInput.addEventListener("input", function () {
    const digitsOnlyValue = this.value.replace(/\D/g, "").slice(0, 11);
    const isPotentialMobile =
      digitsOnlyValue.length === 0 || /^09\d{0,9}$/.test(digitsOnlyValue);

    this.value = digitsOnlyValue;
    this.setCustomValidity(
      isPotentialMobile
        ? ""
        : "Please enter a valid 11-digit mobile number starting with 09."
    );
  });
}

if (customerLoginForm) {
  customerLoginForm.addEventListener("submit", async function (e) {
    e.preventDefault();

    const phone = customerPhoneInput.value.trim();
    const password = document.getElementById("password").value.trim();
    const phonePattern = /^09\d{9}$/;

    if (!phone || !password) {
      alert("Please fill in all fields.");
      return;
    }

    if (!phonePattern.test(phone)) {
      customerPhoneInput.setCustomValidity(
        "Please enter a valid 11-digit mobile number starting with 09."
      );
      customerPhoneInput.reportValidity();
      customerPhoneInput.focus();
      return;
    }

    customerPhoneInput.setCustomValidity("");

    /*
    // USE THIS WHEN THE BACKEND LOGIN API IS READY
    const CUSTOMER_LOGIN_API_URL = "YOUR_BACKEND_LOGIN_URL";

    try {
      const response = await fetch(CUSTOMER_LOGIN_API_URL, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({ phone, password }),
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
