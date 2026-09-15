// Connected to pages/client/verify-email.html
// Handles signup verification, login confirmation, and the signup pending state.

document.addEventListener("DOMContentLoaded", () => {
  const stateVerifying = document.getElementById("stateVerifying");
  const stateSuccess   = document.getElementById("stateSuccess");
  const statePending   = document.getElementById("statePending");
  const stateLoginCode = document.getElementById("stateLoginCode");
  const stateError     = document.getElementById("stateError");
  const resendSection  = document.getElementById("resendSection");
  const successTitle   = document.getElementById("successTitle");
  const successMessage = document.getElementById("successMessage");
  const errorTitle     = document.getElementById("errorTitle");
  const errorMessage   = document.getElementById("errorMessage");
  const pendingEmail   = document.getElementById("pendingEmail");
  const pendingLead    = document.getElementById("pendingLead");
  const pendingAction  = document.getElementById("pendingAction");
  const loginCodeEmail = document.getElementById("loginCodeEmail");
  const loginCodeForm  = document.getElementById("loginCodeForm");
  const loginCodeInput = document.getElementById("loginCode");
  const loginCodeSubmit = document.getElementById("loginCodeSubmit");
  const loginCodeMessage = document.getElementById("loginCodeMessage");
  const resendEmail    = document.getElementById("resendEmail");
  const resendForm     = document.getElementById("resendForm");
  const resendSubmit   = document.getElementById("resendSubmit");
  const resendMessage  = document.getElementById("resendMessage");
  const loginCodeResendBtn       = document.getElementById("loginCodeResendBtn");
  const loginCodeResendCountdown = document.getElementById("loginCodeResendCountdown");

  let resendCodeTimer = null;
  let resendCodeCount = 0;
  const RESEND_CODE_MAX = 3;

  function showState(name) {
    [stateVerifying, stateSuccess, statePending, stateLoginCode, stateError]
      .forEach((element) => element.classList.add("hidden"));
    const element = {
      verifying: stateVerifying,
      success: stateSuccess,
      pending: statePending,
      loginCode: stateLoginCode,
      error: stateError,
    }[name];
    if (element) element.classList.remove("hidden");
  }

  const fragmentParams = new URLSearchParams(window.location.hash.slice(1));
  const queryParams = new URLSearchParams(window.location.search);
  const verificationToken = fragmentParams.get("token")
    || queryParams.get("token");
  const loginCodeMode = queryParams.get("mode") === "login";

  if (verificationToken) {
    // Authentication links are credentials. Remove them from the address bar
    // and browser history before making any network request.
    const cleanUrl = new URL(window.location.href);
    cleanUrl.searchParams.delete("token");
    cleanUrl.hash = "";
    history.replaceState(
      null,
      "",
      `${cleanUrl.pathname}${cleanUrl.search}${cleanUrl.hash}`,
    );
  }

  if (verificationToken) {
    showState("verifying");

    (async () => {
      try {
        await API.verifyEmail(verificationToken);
        showState("success");
        setTimeout(() => {
          window.location.replace("./dashboard.html");
        }, 1500);
      } catch (error) {
        showState("error");
        if (error.expired) {
          errorMessage.textContent = "This verification link has expired. Enter your email below to get a new one.";
          if (error.email) resendEmail.value = error.email;
        } else {
          errorMessage.textContent = error.message
            || "The verification link is invalid or has already been used.";
        }
        resendSection.classList.remove("hidden");
      }
    })();
  } else if (loginCodeMode) {
    document.title = "Confirm Sign-In | Bethlehem Animal Clinic";

    if (!API.hasPendingLoginConfirmation()) {
      errorTitle.textContent = "Sign-In Request Unavailable";
      errorMessage.textContent = "This browser no longer has a pending sign-in. Please return to sign in and try again.";
      showState("error");
    } else {
      const email = API.getPendingLoginConfirmationEmail();
      loginCodeEmail.textContent = email || "your registered email";
      showState("loginCode");
      loginCodeInput.focus();
      startResendCountdown(60);
    }
  } else {
    const savedEmail = sessionStorage.getItem("pendingVerificationEmail");
    const deliveryFailed = sessionStorage.getItem("pendingVerificationDeliveryFailed") === "1";
    showState("pending");
    if (savedEmail) {
      pendingEmail.textContent = savedEmail;
      resendEmail.value = savedEmail;
      sessionStorage.removeItem("pendingVerificationEmail");
    } else {
      pendingEmail.textContent = "your registered email";
    }
    resendSection.classList.remove("hidden");
    sessionStorage.removeItem("pendingVerificationDeliveryFailed");

    if (deliveryFailed) {
      pendingLead.textContent = "Your registration was saved, but we could not send the first verification email to";
      pendingAction.textContent = "Use the resend form below to try again.";
      showResendMessage(
        "error",
        "The first verification email was not sent. Please select Resend Verification Email.",
      );
    }
  }

  loginCodeInput.addEventListener("input", () => {
    loginCodeInput.value = loginCodeInput.value.replace(/\D/g, "").slice(0, 6);
    loginCodeSubmit.disabled = loginCodeInput.value.length !== 6;
    clearLoginCodeMessage();
  });

  loginCodeForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    clearLoginCodeMessage();

    const code = loginCodeInput.value;
    if (!/^\d{6}$/.test(code)) return;

    setLoginCodeBusy(true);

    try {
      const response = await API.confirmLoginCode(code);
      successTitle.textContent = "Sign-In Confirmed!";
      successMessage.textContent = "Redirecting you to your account…";
      showState("success");
      setTimeout(() => {
        window.location.replace(
          ["admin", "staff"].includes(response?.user?.role)
            ? "../admin/dashboard.html"
            : "./dashboard.html",
        );
      }, 750);
    } catch (error) {
      if (error.expired || error.status === 403 || error.status === 429) {
        errorTitle.textContent = "Sign-In Confirmation Failed";
        errorMessage.textContent = error.message
          || "This sign-in request is no longer available. Please sign in again.";
        showState("error");
      } else {
        showLoginCodeMessage(error.message || "Unable to confirm sign-in. Please try again.");
      }
    } finally {
      setLoginCodeBusy(false);
    }
  });

  function lockResendPermanently() {
    clearInterval(resendCodeTimer);
    resendCodeTimer = null;
    loginCodeResendBtn.classList.remove("hidden");
    loginCodeResendBtn.disabled = true;
    loginCodeResendCountdown.classList.add("hidden");
    showLoginCodeMessage("Too many resend attempts. Try again later.", "error");
  }

  function startResendCountdown(seconds = 60) {
    loginCodeResendBtn.disabled = true;
    loginCodeResendBtn.classList.add("hidden");
    loginCodeResendCountdown.classList.remove("hidden");

    let remaining = seconds;
    function tick() {
      if (remaining <= 0) {
        clearInterval(resendCodeTimer);
        resendCodeTimer = null;
        loginCodeResendCountdown.classList.add("hidden");
        if (resendCodeCount >= RESEND_CODE_MAX) {
          lockResendPermanently();
        } else {
          loginCodeResendBtn.classList.remove("hidden");
          loginCodeResendBtn.disabled = false;
        }
        return;
      }
      loginCodeResendCountdown.textContent = `Resend available in ${remaining}s`;
      remaining--;
    }
    tick();
    resendCodeTimer = setInterval(tick, 1000);
  }

  if (loginCodeResendBtn) {
    loginCodeResendBtn.addEventListener("click", async () => {
      clearLoginCodeMessage();
      loginCodeResendBtn.disabled = true;

      try {
        await API.resendLoginCode();
        resendCodeCount++;
        if (resendCodeCount >= RESEND_CODE_MAX) {
          showLoginCodeMessage("A new code has been sent. This was your last resend attempt.", "success");
          startResendCountdown(60);
        } else {
          showLoginCodeMessage("A new code has been sent to your email.", "success");
          startResendCountdown(60);
        }
      } catch (error) {
        loginCodeResendBtn.disabled = false;
        if (error.status === 429) {
          resendCodeCount = RESEND_CODE_MAX;
          lockResendPermanently();
        } else if (error.expired || error.status === 422) {
          errorTitle.textContent = "Sign-In Expired";
          errorMessage.textContent = error.message || "This sign-in session has expired. Please sign in again.";
          showState("error");
        } else {
          showLoginCodeMessage(error.message || "Failed to resend. Please try again.");
        }
      }
    });
  }

  resendForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    clearResendMessage();

    const email = resendEmail.value.trim();
    if (!email) return;

    setResendBusy(true);

    try {
      await API.resendVerification(email);
      showResendMessage("success", "Verification email sent! Check your inbox (and spam folder).");
    } catch (error) {
      if (error.status === 429) {
        showResendMessage("error", error.message || "Too many requests. Please wait before trying again.");
      } else {
        showResendMessage("error", error.message || "Failed to resend. Please try again.");
      }
    } finally {
      setResendBusy(false);
    }
  });

  function setResendBusy(busy) {
    resendSubmit.disabled = busy;
    const label = document.getElementById("resendBtnLabel");
    if (label) label.textContent = busy ? "Sending…" : "Resend Verification Email";
  }

  function setLoginCodeBusy(busy) {
    loginCodeInput.disabled = busy;
    loginCodeSubmit.disabled = busy || loginCodeInput.value.length !== 6;
    const label = document.getElementById("loginCodeBtnLabel");
    if (label) label.textContent = busy ? "Confirming…" : "Confirm Sign-In";
  }

  function showLoginCodeMessage(text, type = "error") {
    const styles = {
      error:   "mt-3 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700",
      success: "mt-3 rounded-2xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700",
    };
    loginCodeMessage.className = styles[type] || styles.error;
    loginCodeMessage.textContent = text;
    loginCodeMessage.classList.remove("hidden");
  }

  function clearLoginCodeMessage() {
    loginCodeMessage.className = "mt-3 hidden rounded-2xl border px-4 py-3 text-sm";
    loginCodeMessage.textContent = "";
  }

  function showResendMessage(type, text) {
    const styles = {
      success: "border-green-200 bg-green-50 text-green-700",
      error: "border-red-200 bg-red-50 text-red-700",
    };
    resendMessage.className = `mt-3 rounded-2xl border px-4 py-3 text-sm ${styles[type] || styles.error}`;
    resendMessage.textContent = text;
    resendMessage.classList.remove("hidden");
  }

  function clearResendMessage() {
    resendMessage.className = "mt-3 hidden rounded-2xl border px-4 py-3 text-sm";
    resendMessage.textContent = "";
  }
});
