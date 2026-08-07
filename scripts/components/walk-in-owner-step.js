const elements = {
  form: document.getElementById("walkInOwnerForm"),
  firstName: document.getElementById("ownerFirstName"),
  lastName: document.getElementById("ownerLastName"),
  middleInitial: document.getElementById("ownerMiddleInitial"),
  phone: document.getElementById("ownerPhoneNumber"),
  email: document.getElementById("ownerEmailAddress"),
  message: document.getElementById("walkInOwnerMessage"),
  nextButton: document.getElementById("walkInOwnerNextBtn"),
  fieldErrors: {
    firstName: document.getElementById("ownerFirstNameError"),
    lastName: document.getElementById("ownerLastNameError"),
    phone: document.getElementById("ownerPhoneNumberError"),
    email: document.getElementById("ownerEmailAddressError"),
  },
};

const WALK_IN_OWNER_STORAGE_KEY = "walkInOwnerStep";
const WALK_IN_FLOW_STORAGE_KEYS = [
  "walkInConsentStep",
  "walkInReviewStep",
  "walkInBookingConfirmation",
];
let hasSubmittedOnce = false;

/*
  BACKEND TEAMMATE + CLAUDE CODE:
  Owner information is only kept as a browser-side walk-in draft for now.
  Replace this with a persisted customer / walk-in draft record when the
  backend flow is ready.
*/

function normalizeText(value) {
  return String(value || "").trim().replace(/\s+/g, " ");
}

function normalizeMiddleInitial(value) {
  return normalizeText(value).replace(/\.+$/, "").toUpperCase();
}

function normalizePhoneNumber(value) {
  const rawValue = normalizeText(value);
  const digits = rawValue.replace(/\D/g, "");

  if (/^63\d{10}$/.test(digits)) {
    return `0${digits.slice(2)}`;
  }

  if (/^9\d{9}$/.test(digits)) {
    return `0${digits}`;
  }

  return rawValue;
}

function isValidPhoneNumber(value) {
  const digits = String(value || "").replace(/\D/g, "");
  return digits.length === 11 && digits.startsWith("09");
}

function isValidEmail(value) {
  const email = normalizeText(value);

  if (!email) {
    return true;
  }

  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

function getAppointmentType() {
  const checked = document.querySelector('input[name="appointmentType"]:checked');
  return checked?.value || "grooming";
}

function isClinicWalkInEntry() {
  const params = new URLSearchParams(window.location.search);
  return params.get("source") === "clinic" || params.get("flow") === "clinic";
}

function handleWalkInBack(event) {
  if (!isClinicWalkInEntry()) {
    return;
  }

  event.preventDefault();
  window.location.assign("./clinic.html");
}

function applyRequestedAppointmentType() {
  if (!isClinicWalkInEntry()) {
    return;
  }

  const clinicType = document.getElementById("typeClinic");

  if (clinicType) {
    clinicType.checked = true;
  }

  document.querySelectorAll("[data-walk-in-back-link]").forEach((link) => {
    link.setAttribute("href", "./clinic.html");
    link.addEventListener("click", handleWalkInBack);
  });
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

function clearWalkInContinuationDraft() {
  WALK_IN_FLOW_STORAGE_KEYS.forEach((key) => {
    sessionStorage.removeItem(key);
  });
}

function saveOwnerDraft(values) {
  sessionStorage.setItem(
    WALK_IN_OWNER_STORAGE_KEY,
    JSON.stringify({
      ...values,
      fullName: getOwnerDisplayName(values),
      bookingType: "walk_in",
      appointmentType: getAppointmentType(),
    }),
  );
}

function validateOwner(values) {
  const errors = {};

  if (!values.firstName) {
    errors.firstName = "First name is required.";
  }

  if (!values.lastName) {
    errors.lastName = "Last name is required.";
  }

  if (!values.phone) {
    errors.phone = "Phone number is required.";
  } else if (!isValidPhoneNumber(values.phone)) {
    errors.phone = "Phone number must be 11 digits and start with 09.";
  }

  if (values.email && !isValidEmail(values.email)) {
    errors.email = "Enter a valid email address or leave it blank.";
  }

  return errors;
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

function setFieldError(fieldName, message) {
  const errorElement = elements.fieldErrors[fieldName];

  if (!errorElement) {
    return;
  }

  if (message) {
    errorElement.textContent = message;
    errorElement.className = "mt-2 text-sm text-red-600";
    return;
  }

  errorElement.textContent = "";
  errorElement.className = "mt-2 hidden text-sm text-red-600";
}

function renderValidationErrors(values, { showAll = false } = {}) {
  const errors = validateOwner(values);
  const requestedFields = Object.keys(elements.fieldErrors);

  requestedFields.forEach((fieldName) => {
    const hasValue = Boolean(values[fieldName] || "");
    const shouldShow = showAll || hasSubmittedOnce || hasValue;
    setFieldError(fieldName, shouldShow ? errors[fieldName] || "" : "");
  });

  const firstError = requestedFields.map((fieldName) => errors[fieldName]).find(Boolean);

  if (showAll || (hasSubmittedOnce && firstError)) {
    showMessage(firstError || "Please complete the highlighted fields.", "error");
    return;
  }

  hideMessage();
}

function syncNextButtonState() {
  const values = getFormValues();
  const errors = validateOwner(values);
  const isReady = Object.keys(errors).length === 0;

  elements.nextButton.disabled = !isReady;
  elements.nextButton.setAttribute("aria-disabled", String(!isReady));
  elements.nextButton.classList.toggle("opacity-50", !isReady);
  elements.nextButton.classList.toggle("cursor-not-allowed", !isReady);

  return isReady;
}

function handleInput(event) {
  const values = getFormValues();
  const fieldName = event.target?.name;

  if (fieldName === "phone") {
    elements.phone.value = normalizePhoneNumber(elements.phone.value);
  }

  renderValidationErrors(values, { showAll: false });
  syncNextButtonState();
}

function handleSubmit(event) {
  event.preventDefault();
  hasSubmittedOnce = true;

  const values = getFormValues();
  const errors = validateOwner(values);

  if (Object.keys(errors).length > 0) {
    renderValidationErrors(values, { showAll: true });
    syncNextButtonState();
    return;
  }

  elements.firstName.value = values.firstName;
  elements.lastName.value = values.lastName;
  elements.middleInitial.value = values.middleInitial;
  elements.phone.value = values.phone;
  elements.email.value = values.email;

  clearWalkInContinuationDraft();
  saveOwnerDraft(values);

  window.location.href = "./walk-in-pet-details.html";
}

function guardAdminAccess() {
  /*
    BACKEND TEAMMATE + CLAUDE CODE:
    This is only a frontend route guard using the locally stored admin/staff
    token and role. Keep real authorization on the backend walk-in endpoints.
  */
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

  applyRequestedAppointmentType();
  bindEvents();
  renderValidationErrors(getFormValues(), { showAll: false });
  syncNextButtonState();

  if (window.lucide) {
    window.lucide.createIcons();
  }
});
