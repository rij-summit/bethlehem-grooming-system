document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("forgotPasswordForm");
  const emailInput = document.getElementById("resetEmail");
  const message = document.getElementById("forgotPasswordMessage");
  const submit = document.getElementById("forgotPasswordSubmit");
  const submitLabel = document.getElementById("forgotPasswordSubmitLabel");

  if (!form || !emailInput || !message || !submit) return;

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const email = emailInput.value.trim();
    if (!email) return;

    setMessage();
    setBusy(true);
    try {
      const response = await API.requestPasswordReset(email);
      setMessage("success", response.message);
    } catch (error) {
      setMessage("error", firstApiError(error));
    } finally {
      setBusy(false);
    }
  });

  function firstApiError(error) {
    const firstMessages = Object.values(error?.errors || {})[0];
    return Array.isArray(firstMessages) && firstMessages[0]
      ? firstMessages[0]
      : error?.message || "The password reset request could not be completed.";
  }

  function setBusy(busy) {
    submit.disabled = busy;
    if (submitLabel) submitLabel.textContent = busy ? "Sending..." : "Send Reset Link";
  }

  function setMessage(type = null, text = "") {
    if (!type) {
      message.className = "hidden rounded-2xl border px-4 py-3 text-sm";
      message.textContent = "";
      return;
    }

    message.className = type === "success"
      ? "rounded-2xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-700"
      : "rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700";
    message.textContent = text;
  }
});
