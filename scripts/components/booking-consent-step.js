import { formatBookingSchedule } from "../services/booking-format-service.js";

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

    sessionStorage.setItem("bookingConsentStep", JSON.stringify(consentPayload));

    // ── Build and submit the booking payload to the backend ──
    const schedule = getStoredData("bookingSchedule");
    const bookingPets = getStoredData("bookingPets") || [];
    const reviewData = getStoredData("bookingStep4Review") || getStoredData("bookingReview") || {};

    if (!schedule?.window_id) {
      alert("Booking schedule is missing. Please go back to Step 1 and select a date and time.");
      return;
    }

    // Map frontend pet format → backend field names
    const step3Selections = (bookingStep3?.petSelections) || [];

    const petsPayload = bookingPets.map((pet) => {
      // Find special instructions for this pet from the review data
      const reviewPet = Array.isArray(reviewData.pets)
        ? reviewData.pets.find((rp) => rp.petId === pet.id)
        : null;

      // Find service selections for this pet from step 3
      const serviceSelection = step3Selections.find((s) => s.petId === pet.id) || {};

      return {
        pet_id: isNaN(Number(pet.id)) ? undefined : Number(pet.id),  // only send if it's a real DB id
        pet_name: pet.petName,
        species: pet.petType,
        breed: pet.breed || null,
        size: normalizeSizeForApi(pet.size),
        fur_type: normalizeFurForApi(pet.furType),
        weight: pet.weight ? parseFloat(pet.weight) : null,
        medical_conditions: pet.medicalNotes || null,
        special_instructions: reviewPet?.specialInstructions || null,
        services: {
          package:   serviceSelection.servicePackage || null,
          ala_carte: serviceSelection.alaCarteServices || [],
        },
      };
    });

    submitBookingButton.disabled = true;
    submitBookingButton.textContent = "Submitting...";

    try {
      const response = await API.storeBooking({
        booking_date: schedule.date,
        window_id: schedule.window_id,
        number_of_pets: bookingPets.length,
        special_notes: null,
        pets: petsPayload,
      });

      // Save the backend response so the confirmed page can read it
      sessionStorage.setItem("bookingConfirmation", JSON.stringify({
        booking_reference: response.booking.booking_reference,
        booking_date: response.booking.booking_date,
        booking_time: response.booking.window,
        status: response.booking.status,
        number_of_pets: response.booking.number_of_pets,
        pets: bookingPets,
        review: reviewData,
        consent: consentPayload,
      }));

      // Set the 24-hour booking lock so the user can't re-book immediately
      setBookingFormLock(response.booking.booking_reference);

      window.location.href = "./booking-confirmed.html";
    } catch (error) {
      submitBookingButton.disabled = false;
      submitBookingButton.textContent = "Submit Booking";
      alert(error.message || "Booking submission failed. Please try again.");
    }
  });

  // "Small" → "small", "Extra Large" → "extra_large"
  function normalizeSizeForApi(size) {
    if (!size) return null;
    return size.toLowerCase().replace(/\s+/g, "_");
  }

  // Fur type values from the dropdown may be verbose — normalize to backend enum
  function normalizeFurForApi(furType) {
    if (!furType) return null;
    const map = {
      "short": "short",
      "short hair": "short",
      "medium": "medium",
      "long": "long",
      "long hair": "long",
      "curly": "curl",
      "double coat": "wire",
      "wire": "wire",
      "curl": "curl",
      "hairless": "short",
    };
    return map[furType.toLowerCase()] || null;
  }

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
      "";
    const bookingTime =
      bookingReview?.bookingTime ||
      bookingStep2?.bookingTime ||
      bookingStep1?.bookingTime ||
      "";

    scheduleSummaryText.textContent =
      bookingReview?.bookingScheduleText ||
      bookingStep2?.bookingScheduleText ||
      formatBookingSchedule(bookingDate, bookingTime) ||
      "No schedule selected yet.";

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

  // Sets a 24-hour lock so the user cannot re-enter the booking form.
  // Uses the same key as booking-form-access-guard.js so the guard can read it.
  function setBookingFormLock(reference) {
    const LOCK_KEY = "bethlehem.bookingFormLock";
    const now = Date.now();
    const lock = JSON.stringify({
      reference: reference || "",
      lockedAt: new Date(now).toISOString(),
      expiresAt: new Date(now + 24 * 60 * 60 * 1000).toISOString(),
      token: localStorage.getItem("customer_token") || "",
    });

    try {
      localStorage.setItem(LOCK_KEY, lock);
      sessionStorage.setItem(LOCK_KEY, lock);
    } catch {
      // Storage unavailable — non-critical, booking already succeeded
    }
  }
});
