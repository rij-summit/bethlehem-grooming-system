// Signup delivery/verification actions are separate from the shared OTP input.
document.addEventListener("DOMContentLoaded", () => {
  const params = new URLSearchParams(window.location.search);
  const fragment = new URLSearchParams(window.location.hash.slice(1));
  if (params.get("mode") === "login" || params.has("token") || fragment.has("token")) return;

  const view = document.getElementById("statePending");
  const title = document.getElementById("verificationTitle");
  const description = document.getElementById("verificationDescription");
  const form = document.getElementById("signupCodeForm");
  const input = document.getElementById("signupCode");
  const submit = document.getElementById("signupCodeSubmit");
  const resend = document.getElementById("signupResend");
  const switchChannel = document.getElementById("switchVerificationChannel");
  const message = document.getElementById("signupCodeMessage");
  const recovery = document.getElementById("verificationEmailRecovery");
  const recoveryEmail = document.getElementById("verificationEmail");
  const read = (key) => { try { return sessionStorage.getItem(key) || ""; } catch { return ""; } };
  const write = (key, value) => { try { sessionStorage.setItem(key, value); } catch { /* UX only */ } };
  let email = read("pendingVerificationEmail");
  const phone = read("pendingVerificationPhone");
  let channel = "email";
  let busy = "";
  let completed = false;
  const until = { email: Number(read("pendingVerificationResendUntil")) || 0, sms: 0 };
  let timer = null;

  // These adapters are the only delivery boundary. SMS intentionally has no
  // endpoint, code generation, verification result, or account/session mutation.
  const localSmsInteraction = () => new Promise((resolve) => window.setTimeout(resolve, 400));
  const actions = {
    email: {
      send: () => API.resendVerification(email),
      verify: (code) => API.verifyEmail(email, code),
    },
    sms: {
      async send() {
        await localSmsInteraction();
        return { delivered: false, resend_after: 45 };
      },
      async verify() {
        await localSmsInteraction();
        throw new Error("We couldn't verify this mobile number. Use email to finish creating your account.");
      },
    },
  };

  function setMessage(text = "") {
    message.textContent = text;
    message.className = text ? "rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" : "hidden";
    input.setAttribute("aria-invalid", text ? "true" : "false");
  }
  const otp = window.VerificationCode.bind(input, [...view.querySelectorAll(".verification-code-cell")], () => {
    setMessage();
    updateControls();
  });

  function updateControls() {
    const remaining = Math.max(0, Math.ceil((until[channel] - Date.now()) / 1000));
    input.disabled = !!busy || completed;
    recoveryEmail.disabled = !!busy || completed;
    submit.disabled = !!busy || completed || input.value.length !== 6;
    switchChannel.disabled = !!busy || completed || !phone;
    resend.disabled = !!busy || completed || remaining > 0;
    submit.textContent = busy === "verify" ? "Verifying..." : channel === "email" ? "Verify Email" : "Verify Mobile Number";
    resend.textContent = busy === "send" ? "Sending..." : remaining > 0 ? `Resend code in ${remaining}s` : "Resend Code";
    if (remaining === 0 && timer) {
      window.clearInterval(timer);
      timer = null;
    }
  }

  function scheduleCountdown() {
    if (timer) window.clearInterval(timer);
    timer = window.setInterval(updateControls, 1000);
    updateControls();
  }

  function startCooldown(seconds = 45) {
    until[channel] = Date.now() + Math.max(0, Number(seconds) || 45) * 1000;
    if (channel === "email") write("pendingVerificationResendUntil", String(until.email));
    scheduleCountdown();
  }

  function renderChannel() {
    title.textContent = channel === "email" ? "Verify your email" : "Verify your mobile number";
    document.title = `${title.textContent} | Bethlehem Animal Clinic`;
    description.textContent = channel === "email"
      ? `We sent a 6-digit verification code to ${email || "your email address"}.`
      : `We'll send a 6-digit verification code to ${phone.slice(0, 4)} ••• ••${phone.slice(-2)}.`;
    document.getElementById("channelPrompt").textContent = channel === "email" ? "Prefer text message? " : "";
    switchChannel.textContent = channel === "email" ? "Verify with SMS instead" : "Use email instead";
    recovery.classList.toggle("hidden", !!email || channel !== "email");
    otp.clear();
    scheduleCountdown();
    input.focus();
  }

  function resolveEmail() {
    if (email) return true;
    if (!recoveryEmail.value.trim() || !recoveryEmail.checkValidity()) {
      recoveryEmail.reportValidity();
      recoveryEmail.focus();
      return false;
    }
    email = recoveryEmail.value.trim().toLowerCase();
    write("pendingVerificationEmail", email);
    description.textContent = `We sent a 6-digit verification code to ${email}.`;
    recovery.classList.add("hidden");
    return true;
  }

  switchChannel.addEventListener("click", () => {
    if (busy || completed || !phone) return;
    channel = channel === "email" ? "sms" : "email";
    renderChannel();
  });

  resend.addEventListener("click", async () => {
    if (resend.disabled || busy || (channel === "email" && !resolveEmail())) return;
    busy = "send";
    setMessage();
    updateControls();
    try {
      const result = await actions[channel].send();
      otp.clear();
      startCooldown(result?.resend_after);
      if (result?.delivered === false) setMessage("We couldn't send a text message. Use email to finish creating your account.");
    } catch (error) {
      setMessage(error?.status === 429 ? "Please wait before requesting another code." : "The code could not be sent. Please try again.");
      if (error?.retryAfter) startCooldown(error.retryAfter);
    } finally {
      busy = "";
      updateControls();
    }
  });

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (busy || completed || (channel === "email" && !resolveEmail())) return;
    if (!/^[0-9]{6}$/.test(input.value)) {
      setMessage("Invalid or expired verification code.");
      return;
    }
    busy = "verify";
    setMessage();
    updateControls();
    try {
      const result = await actions[channel].verify(input.value);
      // Only an authenticated Email response can complete this registration.
      if (channel !== "email" || !result?.token || result?.user?.role !== "customer") {
        throw new Error("Invalid or expired verification code.");
      }
      completed = true;
      otp.clear();
      for (const key of ["pendingVerificationEmail", "pendingVerificationPhone", "pendingRegistrationEditToken", "pendingSignupDetails", "pendingVerificationResendUntil", "pendingVerificationDeliveryFailed"]) {
        try { sessionStorage.removeItem(key); } catch { /* storage unavailable */ }
      }
      if (timer) window.clearInterval(timer);
      view.classList.add("hidden");
      document.getElementById("stateSuccess").classList.remove("hidden");
      window.setTimeout(() => window.location.replace("./dashboard.html"), 1500);
    } catch (error) {
      setMessage(channel === "sms" ? error.message : error?.status === 429
        ? "Too many attempts. Please request a new verification code."
        : error?.message === "These registration details are no longer available. Please register again."
          ? error.message : "Invalid or expired verification code.");
    } finally {
      busy = "";
      updateControls();
    }
  });

  view.classList.remove("hidden");
  renderChannel();
  if (!read("pendingRegistrationEditToken")) document.getElementById("correctSignup").href = "./signup.html";
  if (read("pendingVerificationDeliveryFailed") === "1") {
    setMessage("Your registration was saved, but the code could not be sent. Please select Resend Code.");
  }
});
