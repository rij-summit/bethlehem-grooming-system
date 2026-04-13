// Connected to pages/client/login.html
// Depends on: api.js (loaded before this script in the HTML)

const customerLoginForm = document.getElementById("customerLoginForm");
const customerPhoneInput = document.getElementById("phone");

if (customerPhoneInput && customerLoginForm) {
  customerPhoneInput.addEventListener("input", function () {
    const digitsOnly = this.value.replace(/\D/g, "").slice(0, 11);
    const isValid =
      digitsOnly.length === 0 || /^09\d{0,9}$/.test(digitsOnly);

    this.value = digitsOnly;
    this.setCustomValidity(
      isValid
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

    try {
      await API.customerLogin(phone, password);
      window.location.href = "./dashboard.html";
    } catch (error) {
      alert(error.message);
    }
  });
}
