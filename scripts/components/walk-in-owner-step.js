const elements = {
  form: document.getElementById("walkInOwnerForm"),
  firstName: document.getElementById("ownerFirstName"),
  lastName: document.getElementById("ownerLastName"),
  middleInitial: document.getElementById("ownerMiddleInitial"),
  phone: document.getElementById("ownerPhoneNumber"),
  email: document.getElementById("ownerEmailAddress"),
  message: document.getElementById("walkInOwnerMessage"),
  nextButton: document.getElementById("walkInOwnerNextBtn"),
};

function normalizeText(value) {
  return String(value || "").trim().replace(/\s+/g, " ");
}

function normalizeMiddleInitial(value) {
  return normalizeText(value).replace(/\.+$/, "").toUpperCase();
}

function normalizePhoneNumber(value) {
  const rawValue = normalizeText(value);
  const digits = rawValue.replace(/\D/g, "");

  if (/^639\d{9}$/.test(digits)) {
    return `0${digits.slice(2)}`;
  }

  if (/^9\d{9}$/.test(digits)) {
    return `0${digits}`;
  }

  return rawValue;
}

function isValidPhoneNumber(value) {
  const digits = String(value || "").replace(/\D/g, "");
  return digits.length >= 7 && digits.length <= 15;
}

function isValidEmail(value) {
  const email = normalizeText(value);

  if (!email) {
    return true;
  }

  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

function getFormValues() {
  return {
    firstName: normalizeText(elements.firstName.value),
    lastName: normalizeText(elements.lastName.value),
    middleInitial: normalizeMiddleInitial(elements.middleInitial.value),
    phone: normalizePhoneNumber(elements.phone.value),
    email: normalizeText(elements.email.value),
  };
}

function getOwnerDisplayName(values) {
  return [
    values.firstName,
    values.middleInitial ? `${values.middleInitial}.` : "",
    values.lastName,
  ]
    .filter(Boolean)
    .join(" ");
}

function validateOwner(values) {
  if (!values.firstName) {
    return "First name is required.";
  }

  if (!values.lastName) {
    return "Last name is required.";
  }

  if (!values.phone) {
    return "Phone number is required.";
  }

  if (!isValidPhoneNumber(values.phone)) {
    return "Enter a valid phone number.";
  }

  if (!isValidEmail(values.email)) {
    return "Enter a valid email address or leave it blank.";
  }

  return "";
}

function showMessage(message, variant = "default") {
  const styleMap = {
    default: "border-[#9ebedf] bg-white/80 text-slate-600",
    success: "border-emerald-200 bg-emerald-50 text-emerald-700",
    error: "border-red-200 bg-red-50 text-red-700",
  };

  elements.message.textContent = message;
  elements.message.className = `rounded-2xl border px-4 py-3 text-sm ${
    styleMap[variant] || styleMap.default
  }`;
}

function hideMessage() {
  elements.message.textContent = "";
  elements.message.className = "hidden rounded-2xl border px-4 py-3 text-sm";
}

function syncNextButtonState() {
  const values = getFormValues();
  const validationMessage = validateOwner(values);
  const isReady = !validationMessage;

  elements.nextButton.disabled = !isReady;
  elements.nextButton.setAttribute("aria-disabled", String(!isReady));
  elements.nextButton.classList.toggle("opacity-50", !isReady);
  elements.nextButton.classList.toggle("cursor-not-allowed", !isReady);

  return isReady;
}

function handleInput() {
  hideMessage();
  syncNextButtonState();
}

function handleSubmit(event) {
  event.preventDefault();

  const values = getFormValues();
  const validationMessage = validateOwner(values);

  if (validationMessage) {
    showMessage(validationMessage, "error");
    syncNextButtonState();
    return;
  }

  elements.firstName.value = values.firstName;
  elements.lastName.value = values.lastName;
  elements.middleInitial.value = values.middleInitial;
  elements.phone.value = values.phone;
  elements.email.value = values.email;

  showMessage("Owner information is ready for the next step.", "success");
}

function guardAdminAccess() {
  const token = API.getAdminToken?.();
  const role = API.getUserRole?.();

  if (token && (role === "admin" || role === "staff")) {
    return true;
  }

  window.location.href = "../client/sign-in.html";
  return false;
}

function bindEvents() {
  elements.form.addEventListener("submit", handleSubmit);

  [
    elements.firstName,
    elements.lastName,
    elements.middleInitial,
    elements.phone,
    elements.email,
  ].forEach((input) => {
    input.addEventListener("input", handleInput);
  });
}

document.addEventListener("DOMContentLoaded", () => {
  if (!guardAdminAccess()) {
    return;
  }

  bindEvents();
  syncNextButtonState();

  if (window.lucide) {
    window.lucide.createIcons();
  }
});
