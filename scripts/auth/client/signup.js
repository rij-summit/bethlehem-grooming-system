// Connected to pages/client/signup.html
document.addEventListener("DOMContentLoaded", () => {
  const signupForm = document.getElementById("signupForm");
  const signupMessage = document.getElementById("signupMessage");

  // Prevent errors when script runs on other pages
  if (!signupForm) return;

  signupForm.addEventListener("submit", function (e) {
    e.preventDefault();

    const firstName = document.getElementById("firstName").value.trim();
    const lastName = document.getElementById("lastName").value.trim();
    const email = document.getElementById("email").value.trim();
    const phone = document.getElementById("phone").value.trim();
    const password = document.getElementById("password").value;
    const confirmPassword = document.getElementById("confirmPassword").value;

    // Reset message styles
    signupMessage.className = "mt-5 rounded-xl border px-4 py-3 text-sm";

    /* =====================================================
    🔒 FRONTEND VALIDATION (TEMPORARY / CLIENT-SIDE ONLY)
    =====================================================
    
    NOTE FOR FUTURE (BACKEND INTEGRATION):
    
    - These validations are only for user experience.
    - In production, validation MUST also be done in backend.
    
    FUTURE CHANGES:
    ✔ Replace manual validation with API response validation
    ✔ Backend will handle:
    - Email uniqueness check
    - Phone number validation (PH format)
    - Password hashing (security)
    - OTP verification (email/SMS)
    
    ✔ REMOVE / MODIFY:
    - console.log(userData)
    - Success message without real API response
    
    ===================================================== */

    if (password !== confirmPassword) {
      signupMessage.classList.add(
        "border-red-200",
        "bg-red-50",
        "text-red-700",
      );
      signupMessage.textContent = "Passwords do not match.";
      signupMessage.classList.remove("hidden");
      return;
    }

    if (password.length < 8) {
      signupMessage.classList.add(
        "border-red-200",
        "bg-red-50",
        "text-red-700",
      );
      signupMessage.textContent =
        "Password must be at least 8 characters long.";
      signupMessage.classList.remove("hidden");
      return;
    }

    /* =====================================================
       📦 USER DATA OBJECT (FRONTEND VERSION)

       FUTURE:
       - This object will be sent to backend API using fetch()
       - Example: POST /api/auth/signup

       ===================================================== */
    const userData = {
      firstName,
      lastName,
      email,
      phone,
      password,
    };

    // TEMP: For development only
    console.log("Registered user:", userData);

    /* =====================================================
       🚀 FUTURE API INTEGRATION

       Replace this section with:

       fetch("YOUR_BACKEND_API_URL", {
         method: "POST",
         headers: {
           "Content-Type": "application/json",
         },
         body: JSON.stringify(userData),
       })
       .then(res => res.json())
       .then(data => {
         // Handle success / error from backend
       })
       .catch(err => console.error(err));

       ===================================================== */

    /* =====================================================
       📱 FUTURE FEATURE: OTP VERIFICATION

       After successful signup:
       - Send OTP to email or phone
       - Redirect to OTP verification page

       Example future flow:
       signup → send OTP → verify OTP → activate account

       ===================================================== */

    // TEMP SUCCESS MESSAGE (REMOVE AFTER API IS READY)
    signupMessage.classList.add(
      "border-green-200",
      "bg-green-50",
      "text-green-700",
    );
    signupMessage.textContent =
      "Account created successfully. You can now log in.";
    signupMessage.classList.remove("hidden");

    signupForm.reset();

    /* =====================================================
       🔁 FUTURE REDIRECTION

       AFTER REAL IMPLEMENTATION:
       - Redirect user after successful API response
       - Example:
         window.location.href = "./login.html";

       ===================================================== */
  });
});
