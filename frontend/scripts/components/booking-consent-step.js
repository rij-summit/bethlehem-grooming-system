document.addEventListener("DOMContentLoaded", () => {
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

  setCurrentDate();
  populateSummary();
  restoreConsentDraft();
  validateConsentForm();

  mainConsentCheckbox.addEventListener("change", handleFormStateChange);
  sedationConsentCheckbox.addEventListener("change", handleFormStateChange);
  digitalSignatureInput.addEventListener("input", handleFormStateChange);

  form.addEventListener("submit", (event) => {
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

    /*
      BACKEND NOTE:
      Replace this temporary save with your real API submission.

      Suggested final payload structure:
      {
        bookingStep1,
        bookingStep2,
        bookingStep3,
        bookingReview,
        consent: {
          groomingAgreementAccepted,
          sedationConsentAccepted,
          digitalSignature,
          consentDate
        }
      }

      Example:
      POST /api/bookings/submit
    */
    sessionStorage.setItem(
      "bookingConsentStep",
      JSON.stringify(consentPayload),
    );

    /*
      TEMPORARY FRONT-END REDIRECT:
      Delete or replace this line once backend booking submission is ready.
      Suggested replacement:
      - await submitBookingToAPI(...)
      - redirect to success page using backend response
    */
    window.location.href = "./booking-confirmed.html";
  });

  function handleFormStateChange() {
    saveConsentDraft();
    validateConsentForm();
  }

  function validateConsentForm() {
    const valid = isFormValid();

    if (valid) {
      submitBookingButton.disabled = false;
      submitBookingButton.className =
        "inline-flex items-center justify-center rounded-xl bg-[#315b7e] px-5 py-3 text-sm font-semibold text-white transition hover:bg-[#274a67]";
      consentStatusMessage.textContent =
        "All required fields are complete. You can now submit your booking.";
      consentStatusMessage.className =
        "mb-6 rounded-2xl border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-700";
    } else {
      submitBookingButton.disabled = true;
      submitBookingButton.className =
        "inline-flex cursor-not-allowed items-center justify-center rounded-xl bg-slate-300 px-5 py-3 text-sm font-semibold text-white";
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

  function saveConsentDraft() {
    const consentDraft = {
      groomingAgreementAccepted: mainConsentCheckbox.checked,
      sedationConsentAccepted: sedationConsentCheckbox.checked,
      digitalSignature: digitalSignatureInput.value.trim(),
      consentDate: consentDateInput.value,
    };

    sessionStorage.setItem("bookingConsentStep", JSON.stringify(consentDraft));
  }

  function restoreConsentDraft() {
    const savedDraft = getStoredData("bookingConsentStep");
    if (!savedDraft) return;

    mainConsentCheckbox.checked = Boolean(savedDraft.groomingAgreementAccepted);
    sedationConsentCheckbox.checked = Boolean(
      savedDraft.sedationConsentAccepted,
    );
    digitalSignatureInput.value = savedDraft.digitalSignature || "";
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
