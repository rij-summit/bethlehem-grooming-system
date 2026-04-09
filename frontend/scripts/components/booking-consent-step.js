import {
  initBookingFormAccessGuard,
  persistBookingFormLock,
} from "../services/booking-form-access-guard.js";

document.addEventListener("DOMContentLoaded", () => {
  if (initBookingFormAccessGuard()) {
    return;
  }

  const form = document.getElementById("bookingConsentForm");
  const mainConsentCheckbox = document.getElementById("mainConsentCheckbox");
  const sedationConsentCheckbox = document.getElementById(
    "sedationConsentCheckbox",
  );
  const digitalSignatureInput = document.getElementById("digitalSignature");
  const consentDateInput = document.getElementById("consentDate");
  const submitBookingButton = document.getElementById("submitBookingButton");
  const consentStatusMessage = document.getElementById("consentStatusMessage");
  const scheduleSummaryText = document.getElementById("scheduleSummaryText");
  const bookingSummaryText = document.getElementById("bookingSummaryText");

  /*
    BACKEND NOTE:
    This page currently uses sessionStorage as a temporary front-end placeholder
    for step-to-step data persistence.

    Your backend developer can replace this with:
    - authenticated user session
    - database draft booking record
    - API fetch for booking draft
  */

  const bookingStep1 = getStoredData("bookingStep1");
  const bookingStep2 = getStoredData("bookingStep2");
  const bookingStep3 = getStoredData("bookingStep3");
  const bookingReview =
    getStoredData("bookingReview") || getStoredData("bookingStep4Review");
  const BOOKING_SUBMISSION_STORAGE_KEY = "bookingSubmission";

  sessionStorage.removeItem("bookingConsentStep");
  setCurrentDate();
  resetConsentInputs();
  populateSummary();
  validateConsentForm();

  mainConsentCheckbox.addEventListener("change", handleFormStateChange);
  sedationConsentCheckbox.addEventListener("change", handleFormStateChange);
  digitalSignatureInput.addEventListener("input", handleFormStateChange);

  form.addEventListener("submit", async (event) => {
    event.preventDefault();

    if (!isFormValid()) {
      validateConsentForm();
      return;
    }

    const consentPayload = {
      groomingAgreementAccepted: mainConsentCheckbox.checked,
      sedationConsentAccepted: sedationConsentCheckbox.checked,
      digitalSignature: digitalSignatureInput.value.trim(),
      consentDate: consentDateInput.value,
    };

    try {
      if (
        !window.BethlehemApi ||
        typeof window.BethlehemApi.submitBooking !== "function"
      ) {
        throw new Error(
          "Booking API is not available. Please include scripts/api.js on this page.",
        );
      }

      const bookingPayload = {
        bookingStep1,
        bookingStep2,
        bookingStep3,
        bookingReview,
        consent: consentPayload,
      };

      setSubmittingState(true);

      const response = await window.BethlehemApi.submitBooking(bookingPayload);
      handleSuccessfulSubmission(response, consentPayload);
    } catch (error) {
      if (error?.status === 409 && error?.data?.code === "ACTIVE_BOOKING_EXISTS") {
        await handleExistingBookingConflict(error, consentPayload);
        return;
      }

      console.error("Booking submission failed:", error);
      consentStatusMessage.textContent =
        error?.message ||
        "Booking submission failed. Please review the form and try again.";
      consentStatusMessage.className =
        "mb-6 rounded-2xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700";
      setSubmittingState(false);
      syncSubmitButtonState();
    }
  });

  function handleFormStateChange() {
    validateConsentForm();
  }

  function validateConsentForm() {
    const valid = syncSubmitButtonState();

    if (valid) {
      consentStatusMessage.textContent =
        "All required fields are complete. You can now submit your booking.";
      consentStatusMessage.className =
        "mb-6 rounded-2xl border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-700";
    } else {
      consentStatusMessage.textContent =
        "Please complete both consent checkboxes and enter your digital signature before submitting.";
      consentStatusMessage.className =
        "mb-6 rounded-2xl border border-[#9bb9d3] bg-white px-4 py-3 text-sm text-slate-600";
    }
  }

  function isFormValid() {
    const signatureValue = digitalSignatureInput.value.trim();

    return (
      mainConsentCheckbox.checked &&
      sedationConsentCheckbox.checked &&
      signatureValue.length > 0
    );
  }

  function syncSubmitButtonState() {
    const valid = isFormValid();

    if (valid) {
      submitBookingButton.disabled = false;
      submitBookingButton.className =
        "inline-flex items-center justify-center rounded-xl bg-[#315b7e] px-5 py-3 text-sm font-semibold text-white transition hover:bg-[#274a67]";
      return true;
    }

    submitBookingButton.disabled = true;
    submitBookingButton.className =
      "inline-flex cursor-not-allowed items-center justify-center rounded-xl bg-slate-300 px-5 py-3 text-sm font-semibold text-white";
    return false;
  }

  function setSubmittingState(isSubmitting) {
    if (isSubmitting) {
      submitBookingButton.disabled = true;
      submitBookingButton.className =
        "inline-flex cursor-not-allowed items-center justify-center rounded-xl bg-slate-400 px-5 py-3 text-sm font-semibold text-white";
      submitBookingButton.textContent = "Submitting...";
      consentStatusMessage.textContent =
        "Submitting your booking. Please wait...";
      consentStatusMessage.className =
        "mb-6 rounded-2xl border border-[#9bb9d3] bg-white px-4 py-3 text-sm text-slate-600";
      return;
    }

    submitBookingButton.textContent = "Submit Booking";
  }

  function resetConsentInputs() {
    mainConsentCheckbox.checked = false;
    sedationConsentCheckbox.checked = false;
    digitalSignatureInput.value = "";
  }

  function handleSuccessfulSubmission(response, consentPayload) {
    persistBookingFormLock(response?.booking || null);

    sessionStorage.setItem(
      "bookingConsentStep",
      JSON.stringify(consentPayload),
    );
    sessionStorage.setItem(
      BOOKING_SUBMISSION_STORAGE_KEY,
      JSON.stringify(response),
    );

    const reference = response?.booking?.reference;
    window.location.href = reference
      ? `./booking-confirmed.html?reference=${encodeURIComponent(reference)}`
      : "./booking-confirmed.html";
  }

  async function handleExistingBookingConflict(error, consentPayload) {
    const existingBooking = error?.data?.existingBooking || {};
    const bookingReference = existingBooking?.reference || "unknown reference";
    const schedule = [existingBooking?.bookingDate, existingBooking?.bookingTime]
      .filter(Boolean)
      .join(" | ");

    setSubmittingState(false);
    syncSubmitButtonState();

    const shouldBookDifferent = window.confirm(
      `You already have an active booking (${bookingReference}${schedule ? ` | ${schedule}` : ""}). Click OK to book a different appointment now, or Cancel to reschedule your existing booking.`,
    );

    if (!shouldBookDifferent) {
      const rescheduleQuery = existingBooking?.reference
        ? `?reschedule=1&reference=${encodeURIComponent(existingBooking.reference)}`
        : "?reschedule=1";
      window.location.href = `./booking.html${rescheduleQuery}`;
      return;
    }

    const retryPayload = {
      bookingStep1,
      bookingStep2,
      bookingStep3,
      bookingReview,
      consent: consentPayload,
      forceNewBooking: true,
    };

    setSubmittingState(true);

    try {
      const retryResponse = await window.BethlehemApi.submitBooking(retryPayload);
      handleSuccessfulSubmission(retryResponse, consentPayload);
    } catch (retryError) {
      console.error("Booking retry failed:", retryError);
      consentStatusMessage.textContent =
        retryError?.message ||
        "Unable to create a new booking. Please try again.";
      consentStatusMessage.className =
        "mb-6 rounded-2xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700";
      setSubmittingState(false);
      syncSubmitButtonState();
    }
  }

  function setCurrentDate() {
    const today = new Date();
    const formattedDate = today.toLocaleDateString("en-PH", {
      year: "numeric",
      month: "long",
      day: "numeric",
    });

    consentDateInput.value = formattedDate;
  }

  function populateSummary() {
    const bookingDate =
      bookingReview?.bookingDate ||
      bookingStep2?.bookingDate ||
      bookingStep1?.bookingDate ||
      "No date selected";
    const bookingTime =
      bookingReview?.bookingTime ||
      bookingStep2?.bookingTime ||
      bookingStep1?.bookingTime ||
      "No time selected";

    scheduleSummaryText.textContent = `${bookingDate} | ${bookingTime}`;

    const reviewedPets = Array.isArray(bookingReview?.pets)
      ? bookingReview.pets
      : [];

    if (reviewedPets.length > 1) {
      bookingSummaryText.textContent = `${reviewedPets.length} pets selected: ${reviewedPets
        .map(
          (pet) =>
            `${pet.petName || "Unnamed Pet"} (${getReviewedServiceSummary(pet)})`,
        )
        .join(", ")}`;
      return;
    }

    if (reviewedPets.length === 1) {
      const [reviewedPet] = reviewedPets;

      bookingSummaryText.textContent = `${reviewedPet.petName || "No pet selected"} | ${formatServiceName(
        reviewedPet.petType || "No pet type",
      )} | ${getReviewedServiceSummary(reviewedPet)}`;
      return;
    }

    const petName = bookingStep2?.petName || "No pet selected";
    const petType = formatServiceName(bookingStep2?.petType || "No pet type");
    const servicePackage = formatServiceName(
      bookingStep3?.servicePackage || "No service selected",
    );

    bookingSummaryText.textContent = `${petName} | ${petType} | ${servicePackage}`;
  }

  function getReviewedServiceSummary(pet) {
    const selectedServices = [
      pet?.servicePackage ? formatServiceName(pet.servicePackage) : "",
      ...(Array.isArray(pet?.alaCarteServices)
        ? pet.alaCarteServices.map((serviceId) => formatServiceName(serviceId))
        : []),
    ].filter(Boolean);

    return selectedServices.length > 0
      ? selectedServices.join(", ")
      : "No service selected";
  }

  function formatServiceName(value) {
    if (!value) return "";
    return value
      .replaceAll("_", " ")
      .replace(/\b\w/g, (char) => char.toUpperCase());
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
});
