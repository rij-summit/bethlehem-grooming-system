// Connected to pages/client/verify-email.html
// Handles two scenarios:
//   1. ?token=... in URL  → auto-verify, then redirect to dashboard
//   2. No token           → show "check your email" pending state with resend form

document.addEventListener("DOMContentLoaded", () => {
  if (window.lucide) window.lucide.createIcons();

  const stateVerifying = document.getElementById("stateVerifying");
  const stateSuccess   = document.getElementById("stateSuccess");
  const statePending   = document.getElementById("statePending");
  const stateError     = document.getElementById("stateError");
  const resendSection  = document.getElementById("resendSection");
  const errorMessage   = document.getElementById("errorMessage");
  const pendingEmail   = document.getElementById("pendingEmail");
  const resendEmail    = document.getElementById("resendEmail");
  const resendForm     = document.getElementById("resendForm");
  const resendSubmit   = document.getElementById("resendSubmit");
  const resendMessage  = document.getElementById("resendMessage");

  function showState(name) {
    [stateVerifying, stateSuccess, statePending, stateError].forEach(el => el.classList.add("hidden"));
    const el = { verifying: stateVerifying, success: stateSuccess, pending: statePending, error: stateError }[name];
    if (el) el.classList.remove("hidden");
    if (window.lucide) window.lucide.createIcons();
  }

  const params = new URLSearchParams(window.location.search);
  const token  = params.get("token");

  // ── Scenario 1: token in URL → auto-verify ───────────────────────────────

  if (token) {
    showState("verifying");

    (async () => {
      try {
        await API.verifyEmail(token); // stores token internally

        showState("success");
        setTimeout(() => { window.location.href = "./dashboard.html"; }, 2000);
      } catch (err) {
        showState("error");
        if (err.expired) {
          errorMessage.textContent = "This verification link has expired. Enter your email below to get a new one.";
          if (err.email) resendEmail.value = err.email;
        } else {
          errorMessage.textContent = err.message || "The verification link is invalid or has already been used.";
        }
        resendSection.classList.remove("hidden");
      }
    })();

  // ── Scenario 2: no token → show pending / check-email state ──────────────

  } else {
    const savedEmail = sessionStorage.getItem("pendingVerificationEmail");
    showState("pending");
    if (savedEmail) {
      pendingEmail.textContent = savedEmail;
      resendEmail.value = savedEmail;
      sessionStorage.removeItem("pendingVerificationEmail");
    } else {
      pendingEmail.textContent = "your registered email";
    }
    resendSection.classList.remove("hidden");
  }

  // ── Resend form ───────────────────────────────────────────────────────────

  resendForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    clearResendMessage();

    const email = resendEmail.value.trim();
    if (!email) return;

    setResendBusy(true);

    try {
      await API.resendVerification(email);
      showResendMessage("success", "Verification email sent! Check your inbox (and spam folder).");
    } catch (err) {
      if (err.status === 429) {
        showResendMessage("error", err.message || "Too many requests. Please wait before trying again.");
      } else {
        showResendMessage("error", err.message || "Failed to resend. Please try again.");
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

  function showResendMessage(type, text) {
    const styles = {
      success: "border-green-200 bg-green-50 text-green-700",
      error:   "border-red-200 bg-red-50 text-red-700",
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
