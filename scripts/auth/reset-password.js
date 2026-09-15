document.addEventListener("DOMContentLoaded", async () => {
  const form = document.getElementById("resetPasswordForm");
  const account = document.getElementById("resetPasswordAccount");
  const password = document.getElementById("newPassword");
  const confirmation = document.getElementById("confirmNewPassword");
  const message = document.getElementById("resetPasswordMessage");
  const submit = document.getElementById("resetPasswordSubmit");
  const submitLabel = document.getElementById("resetPasswordSubmitLabel");

  if (!form || !password || !confirmation || !message || !submit) return;
  installVisibilityToggle(
    "toggleNewPassword",
    password,
    ".new-password-icon-closed",
    ".new-password-icon-open",
    "new password",
  );
  installVisibilityToggle(
    "toggleConfirmNewPassword",
    confirmation,
    ".confirm-password-icon-closed",
    ".confirm-password-icon-open",
    "password confirmation",
  );

  const fragmentParams = new URLSearchParams(window.location.hash.slice(1));
  const resetToken = fragmentParams.get("token") || "";
  const cleanUrl = new URL(window.location.href);
  cleanUrl.hash = "";
  history.replaceState(null, "", `${cleanUrl.pathname}${cleanUrl.search}`);

  if (!/^[A-Za-z0-9]{64}$/.test(resetToken)) {
    showInvalidLink();
    return;
  }

  try {
    const response = await API.verifyPasswordResetToken(resetToken);
    account.textContent = response.email;
    form.classList.remove("hidden");
    password.focus();
  } catch (error) {
    showInvalidLink(error.message);
    return;
  }

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    setMessage();

    const validationError = validatePasswords(password.value, confirmation.value);
    if (validationError) {
      setMessage("error", validationError);
      return;
    }

    setBusy(true);
    try {
      await API.resetPassword(resetToken, password.value, confirmation.value);
      window.location.replace("./sign-in.html?password_reset=1");
    } catch (error) {
      setMessage("error", firstApiError(error));
    } finally {
      setBusy(false);
    }
  });

  function showInvalidLink(text = "This password reset link is invalid or has expired.") {
    account.textContent = "Password reset unavailable";
    form.classList.add("hidden");
    setMessage("error", text);
  }

  function validatePasswords(value, confirmationValue) {
    if (!value || !confirmationValue) return "Complete both password fields.";
    if (value !== confirmationValue) return "The passwords do not match.";
    if (
      value.length < 12
      || !/[a-z]/.test(value)
      || !/[A-Z]/.test(value)
      || !/\d/.test(value)
      || !/[^A-Za-z0-9]/.test(value)
    ) {
      return "Use at least 12 characters with uppercase, lowercase, a number, and a symbol.";
    }
    return "";
  }

  function firstApiError(error) {
    const firstMessages = Object.values(error?.errors || {})[0];
    return Array.isArray(firstMessages) && firstMessages[0]
      ? firstMessages[0]
      : error?.message || "The password could not be reset.";
  }

  function setBusy(busy) {
    submit.disabled = busy;
    if (submitLabel) submitLabel.textContent = busy ? "Resetting..." : "Reset Password";
  }

  function setMessage(type = null, text = "") {
    if (!type) {
      message.className = "hidden rounded-2xl border px-4 py-3 text-sm";
      message.textContent = "";
      return;
    }
    message.className = "rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700";
    message.textContent = text;
  }

  function installVisibilityToggle(buttonId, input, closedSelector, openSelector, label) {
    const button = document.getElementById(buttonId);
    const closedIcon = document.querySelector(closedSelector);
    const openIcon = document.querySelector(openSelector);
    if (!button) return;

    button.addEventListener("click", () => {
      const show = input.type === "password";
      input.type = show ? "text" : "password";
      button.setAttribute("aria-label", `${show ? "Hide" : "Show"} ${label}`);
      closedIcon?.classList.toggle("hidden", show);
      openIcon?.classList.toggle("hidden", !show);
    });
  }
});
