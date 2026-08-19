// Connected to pages/client/verify-email.html
// Handles two scenarios:
//   1. #token=... (or legacy ?token=...) → auto-verify, then require an explicit sign-in
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
  const pendingLead    = document.getElementById("pendingLead");
  const pendingAction  = document.getElementById("pendingAction");
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

  const fragmentParams = new URLSearchParams(window.location.hash.slice(1));
  const queryParams = new URLSearchParams(window.location.search);
  // New verification links keep credentials in the fragment so they are not
  // sent in the initial HTTP request. Query tokens remain supported for links
  // generated before that change.
  const token = fragmentParams.get("token") || queryParams.get("token");

  if (token) {
    // Verification tokens are credentials. Remove them from the address bar
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

  // ── Scenario 1: token in URL → auto-verify ───────────────────────────────

  if (token) {
    showState("verifying");

    (async () => {
      try {
        await API.verifyEmail(token);

        showState("success");
        setTimeout(() => {
          window.location.replace("./sign-in.html?verified=1");
        }, 2000);
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
