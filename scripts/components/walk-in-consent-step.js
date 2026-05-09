const state = {
  reviewPayload: null,
  onBack: null,
};

const WALK_IN_OWNER_STORAGE_KEY = "walkInOwnerStep";
const WALK_IN_REVIEW_STORAGE_KEY = "walkInReviewStep";
const WALK_IN_CONSENT_STORAGE_KEY = "walkInConsentStep";
const WALK_IN_CONFIRMATION_STORAGE_KEY = "walkInBookingConfirmation";
let consentDateValue = "";

/*
  BACKEND TEAMMATE + CLAUDE CODE:
  These keys are temporary browser storage for the walk-in flow. Replace them
  with a database-backed walk-in draft and final booking response when ready.
*/

let elements = {};

function getConsentMainMarkup() {
  return `
    <main class="mx-auto max-w-5xl px-4 py-8 md:px-6 lg:px-8">
      <button
        id="walkInConsentBackTopBtn"
        type="button"
        class="inline-flex items-center gap-2 text-sm font-medium text-[#315b7e] hover:underline"
      >
        <span>&larr;</span>
        <span>Back to Review</span>
      </button>

      <section class="mt-4">
        <h1 class="text-3xl font-bold text-[#2f4b66]">Select your Pet Drop-off Date and Time</h1>

        <div class="mt-6">
          <div class="mb-2 flex items-center justify-between text-xs font-medium text-slate-500">
            <span>Step 5 of 5</span>
            <span>100% Complete</span>
          </div>
          <div class="h-2 w-full overflow-hidden rounded-full bg-slate-200">
            <div class="h-full w-full rounded-full bg-[#315b7e]"></div>
          </div>
        </div>
      </section>

      <section class="mt-6 rounded-[28px] border border-slate-200 bg-[#d9e8f4] p-5 shadow-sm md:p-8">
        <div class="mb-6">
          <h2 class="text-2xl font-bold text-[#2f4b66]">Consent &amp; Agreement</h2>
        </div>

        <div class="mb-6 grid gap-4 md:grid-cols-2">
          <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">
              Schedule Type
            </p>
            <p class="mt-2 text-sm text-slate-600">Walk-in schedule</p>
          </div>

          <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">
              Schedule Summary
            </p>
            <p id="bookingSummaryText" class="mt-2 text-sm text-slate-600">
              No schedule details available yet.
            </p>
          </div>
        </div>

        <div
          id="consentStatusMessage"
          class="mb-6 rounded-2xl border border-[#9bb9d3] bg-white px-4 py-3 text-sm text-slate-600"
        >
          Please review the agreement, complete the digital signature, and
          accept all required checkboxes.
        </div>

        <form id="walkInConsentForm" class="space-y-6" novalidate>
          <section class="rounded-2xl border border-[#91aeca] bg-white p-5">
            <div class="mb-4">
              <h3 class="text-lg font-semibold text-[#2f4b66]">
                Grooming Agreement
              </h3>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
              <p class="text-sm leading-7 text-slate-700">
                I hereby agree and consent to have my pet groomed at Bethlehem
                Animal Clinic subject to the following conditions:
              </p>

              <ul class="mt-4 space-y-3 text-sm leading-7 text-slate-700">
                <li>&bull; That I confirm that my pet for grooming is at least / above six (6) months of age, apparently healthy, vaccinations up to date, no ongoing medication and/or is fit for grooming.</li>
                <li>&bull; That I understand that my pet will be bathed, clipped, trimmed and given a basic health check.</li>
                <li>&bull; That I agree that the clinic shall not be held responsible for irritations, abrasion, patchiness or hair loss due to pre-existing skin condition or as a result of dematting, thinning, stripping or shaving or any mishap caused by nondisclosure of my pet&rsquo;s medical condition or behavior.</li>
                <li>&bull; That if my pet bites or attempts to bite any person or pets, a muzzle may be used or other restrictions at the discretion of the groomer.</li>
                <li>&bull; That if my pet harbours parasites or has health concern, I understand that the grooming will cease and I will be advised for treatment, collection, or return home.</li>
                <li>&bull; That if in my absence, my representative is informed of my grooming request.</li>
                <li>&bull; That I agree and am responsible for the payment of grooming for my pet.</li>
              </ul>
            </div>

            <label class="mt-4 flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
              <input
                id="mainConsentCheckbox"
                type="checkbox"
                class="mt-1 h-4 w-4 shrink-0 accent-[#315b7e]"
              />
              <span class="text-sm text-slate-700">
                I have read and agree to the grooming agreement.
                <span class="text-red-500">*</span>
              </span>
            </label>
          </section>

          <section class="rounded-2xl border border-[#91aeca] bg-white p-5">
            <div class="mb-4">
              <h3 class="text-lg font-semibold text-[#2f4b66]">
                Consent for Sedation
              </h3>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
              <p class="text-sm leading-7 text-slate-700">
                I hereby authorize the clinic and its staff to proceed with the
                sedation as it is deemed necessary for my pet for grooming. I
                confirm that the procedure was explained and my pet had
                undergone necessary test / screening prior to sedation and
                grooming.
              </p>
            </div>

            <label class="mt-4 flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
              <input
                id="sedationConsentCheckbox"
                type="checkbox"
                class="mt-1 h-4 w-4 shrink-0 accent-[#315b7e]"
              />
              <span class="text-sm text-slate-700">
                I have read and agree to the sedation consent.
              </span>
            </label>
          </section>

          <div class="flex flex-col gap-3 pt-2 sm:flex-row sm:items-center sm:justify-between">
            <button
              id="walkInConsentBackBtn"
              type="button"
              class="inline-flex items-center justify-center rounded-xl border border-[#315b7e] px-5 py-3 text-sm font-medium text-[#315b7e] transition hover:bg-white"
            >
              Back
            </button>

            <button
              id="submitBookingButton"
              type="submit"
              disabled
              class="inline-flex cursor-not-allowed items-center justify-center rounded-xl bg-slate-300 px-5 py-3 text-sm font-semibold text-white"
            >
              Submit Schedule
            </button>
          </div>
        </form>
      </section>
    </main>
  `;
}

function refreshElements() {
  elements = {
    form: document.getElementById("walkInConsentForm"),
    mainConsentCheckbox: document.getElementById("mainConsentCheckbox"),
    sedationConsentCheckbox: document.getElementById("sedationConsentCheckbox"),
    submitBookingButton: document.getElementById("submitBookingButton"),
    consentStatusMessage: document.getElementById("consentStatusMessage"),
    bookingSummaryText: document.getElementById("bookingSummaryText"),
    backButtons: [
      document.getElementById("walkInConsentBackTopBtn"),
      document.getElementById("walkInConsentBackBtn"),
    ].filter(Boolean),
  };
}

function setCurrentDate() {
  const today = new Date();
  consentDateValue = today.toLocaleDateString("en-PH", {
    year: "numeric",
    month: "long",
    day: "numeric",
  });
}

function populateSummary() {
  const items = Array.isArray(state.reviewPayload?.items)
    ? state.reviewPayload.items
    : [];

  if (items.length > 1) {
    elements.bookingSummaryText.textContent = `${items.length} pets selected: ${items
      .map(
        (item) =>
          `${item.pet?.petName || "Unnamed Pet"} (${getSelectedServiceSummary(item)})`,
      )
      .join(", ")}`;
    return;
  }

  if (items.length === 1) {
    const [item] = items;
    elements.bookingSummaryText.textContent = `${item.pet?.petName || "Unnamed Pet"} | ${formatServiceName(
      item.pet?.petType || "No pet type",
    )} | ${getSelectedServiceSummary(item)}`;
    return;
  }

  elements.bookingSummaryText.textContent = "No schedule details available yet.";
}

function getSelectedServiceSummary(item) {
  const selectedServices = (item?.pricing?.lineItems || [])
    .map((lineItem) => lineItem.serviceName)
    .filter(Boolean);

  return selectedServices.length > 0
    ? selectedServices.join(", ")
    : "No service selected";
}

function formatServiceName(value) {
  if (!value) return "";
  return String(value)
    .replaceAll("_", " ")
    .replace(/\b\w/g, (char) => char.toUpperCase());
}

function isFormValid() {
  return elements.mainConsentCheckbox.checked;
}

function validateConsentForm() {
  const valid = isFormValid();

  if (valid) {
    elements.submitBookingButton.disabled = false;
    elements.submitBookingButton.className =
      "inline-flex items-center justify-center rounded-xl bg-[#315b7e] px-5 py-3 text-sm font-semibold text-white transition hover:bg-[#274a67]";
    elements.consentStatusMessage.textContent =
      "All required fields are complete. You can now submit this walk-in schedule.";
    elements.consentStatusMessage.className =
      "mb-6 rounded-2xl border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-700";
    return;
  }

  elements.submitBookingButton.disabled = true;
  elements.submitBookingButton.className =
    "inline-flex cursor-not-allowed items-center justify-center rounded-xl bg-slate-300 px-5 py-3 text-sm font-semibold text-white";
  elements.consentStatusMessage.textContent =
    "Please check the grooming consent before submitting.";
  elements.consentStatusMessage.className =
    "mb-6 rounded-2xl border border-[#9bb9d3] bg-white px-4 py-3 text-sm text-slate-600";
}

function handleFormStateChange() {
  saveConsentDraft();
  validateConsentForm();
}

function handleSubmit(event) {
  event.preventDefault();

  if (!isFormValid()) {
    validateConsentForm();
    return;
  }

  const consentPayload = getConsentPayload();
  saveConsentDraft(consentPayload);

  elements.submitBookingButton.disabled = true;
  elements.submitBookingButton.textContent = "Submitting...";

  /*
    BACKEND TEAMMATE + CLAUDE CODE:
    This submit currently builds a frontend-only confirmation for the visible
    admin/staff flow. Swap this block for the real walk-in booking API call,
    then save the API response for the confirmation page to render.
  */
  sessionStorage.setItem(
    WALK_IN_CONFIRMATION_STORAGE_KEY,
    JSON.stringify(buildWalkInConfirmation(consentPayload)),
  );

  window.location.href = "./walk-in-booking-confirmed.html";
}

function handleBackClick() {
  if (typeof state.onBack === "function") {
    state.onBack();
    return;
  }

  window.location.href = "./walk-in-booking-review.html";
}

function getConsentPayload() {
  return {
    groomingAgreementAccepted: elements.mainConsentCheckbox.checked,
    sedationConsentAccepted: elements.sedationConsentCheckbox.checked,
    digitalSignature: "",
    consentDate: consentDateValue,
  };
}

function saveConsentDraft(consentPayload = getConsentPayload()) {
  /*
    BACKEND TEAMMATE + CLAUDE CODE:
    Consent is saved only in browser storage right now. Persist the signed
    consent snapshot with the final walk-in booking record later.
  */
  sessionStorage.setItem(
    WALK_IN_CONSENT_STORAGE_KEY,
    JSON.stringify(consentPayload),
  );
}

function restoreConsentDraft() {
  const savedDraft = getStoredData(WALK_IN_CONSENT_STORAGE_KEY);

  if (!savedDraft) {
    return;
  }

  elements.mainConsentCheckbox.checked = Boolean(
    savedDraft.groomingAgreementAccepted,
  );
  elements.sedationConsentCheckbox.checked = Boolean(
    savedDraft.sedationConsentAccepted,
  );
  consentDateValue = savedDraft.consentDate || consentDateValue;
}

function buildWalkInConfirmation(consentPayload) {
  /*
    BACKEND TEAMMATE + CLAUDE CODE:
    The reference number, date, and status below are frontend placeholders.
    Replace them with the official booking fields returned after database save.
  */
  const submittedAt = new Date();
  const reviewPayload =
    state.reviewPayload || getStoredData(WALK_IN_REVIEW_STORAGE_KEY);
  const owner = getStoredData(WALK_IN_OWNER_STORAGE_KEY);
  const items = Array.isArray(reviewPayload?.items) ? reviewPayload.items : [];

  return {
    booking_reference: createFrontEndReference(submittedAt),
    booking_date: formatLocalDateKey(submittedAt),
    booking_time: "Walk-in / Queue",
    booking_type: "walk_in",
    status: "confirmed",
    number_of_pets: items.length,
    owner,
    pets: items.map((item) => item.pet).filter(Boolean),
    review: buildConfirmationReview(reviewPayload),
    consent: consentPayload,
  };
}

function buildConfirmationReview(reviewPayload) {
  const items = Array.isArray(reviewPayload?.items) ? reviewPayload.items : [];

  return {
    pets: items.map((item) => {
      const lineItems = Array.isArray(item.pricing?.lineItems)
        ? item.pricing.lineItems
        : [];
      const packageLine = lineItems.find(
        (lineItem) => lineItem.kind === "package",
      );

      return {
        petId: item.pet?.id || "",
        petName: item.pet?.petName || "",
        petType: item.pet?.petType || "",
        breed: item.pet?.breed || "",
        size: item.pet?.size || "",
        servicePackage:
          packageLine?.serviceName || item.selection?.servicePackage || "",
        alaCarteServices: lineItems
          .filter((lineItem) => lineItem.kind === "ala_carte")
          .map((lineItem) => lineItem.serviceName),
        specialInstructions: String(
          item.selection?.specialInstructions || "",
        ).trim(),
        pricing: item.pricing?.total || null,
      };
    }),
    totalPricing: reviewPayload?.totalPricing || null,
    notices: reviewPayload?.notices || [],
    isEstimate: Boolean(reviewPayload?.isEstimate),
  };
}

function createFrontEndReference(date) {
  const datePart = formatLocalDateKey(date).replaceAll("-", "");
  const timePart = [
    date.getHours(),
    date.getMinutes(),
    date.getSeconds(),
  ]
    .map((part) => String(part).padStart(2, "0"))
    .join("");

  return `WALK-IN-${datePart}-${timePart}`;
}

function formatLocalDateKey(date) {
  return [
    date.getFullYear(),
    String(date.getMonth() + 1).padStart(2, "0"),
    String(date.getDate()).padStart(2, "0"),
  ].join("-");
}

function getStoredData(key) {
  try {
    const rawValue = sessionStorage.getItem(key);
    return rawValue ? JSON.parse(rawValue) : null;
  } catch (error) {
    console.error(`Failed to parse sessionStorage key: ${key}`, error);
    return null;
  }
}

function bindEvents() {
  elements.mainConsentCheckbox.addEventListener("change", handleFormStateChange);
  elements.sedationConsentCheckbox.addEventListener("change", handleFormStateChange);
  elements.form.addEventListener("submit", handleSubmit);
  elements.backButtons.forEach((button) => {
    button.addEventListener("click", handleBackClick);
  });
}

function guardAdminAccess() {
  /*
    BACKEND TEAMMATE + CLAUDE CODE:
    This only redirects unauthenticated users in the UI. Backend walk-in submit
    and draft endpoints should still validate the admin/staff token.
  */
  const token = API.getAdminToken?.();
  const role = API.getUserRole?.();

  if (token && (role === "admin" || role === "staff")) {
    return true;
  }

  window.location.href = "../client/sign-in.html";
  return false;
}

function initConsentState({ reviewPayload = null, onBack = null } = {}) {
  state.reviewPayload =
    reviewPayload || getStoredData(WALK_IN_REVIEW_STORAGE_KEY);
  state.onBack = onBack;
}

export function renderWalkInConsentStep(options = {}) {
  document.title = "Walk-in Schedule - Consent & Agreement";
  document.body.className = "min-h-screen bg-slate-50 text-slate-800";
  document.body.innerHTML = getConsentMainMarkup();

  refreshElements();
  initConsentState(options);
  setCurrentDate();
  restoreConsentDraft();
  populateSummary();
  bindEvents();
  validateConsentForm();
}

document.addEventListener("DOMContentLoaded", () => {
  if (!document.getElementById("walkInConsentForm")) {
    return;
  }

  if (!guardAdminAccess()) {
    return;
  }

  refreshElements();
  initConsentState();
  setCurrentDate();
  restoreConsentDraft();
  populateSummary();
  bindEvents();
  validateConsentForm();
});
