document.addEventListener("DOMContentLoaded", () => {
  const API_BASE = "http://localhost:8000/api";

  const form = document.getElementById("bookingConsentForm");
  const mainConsentCheckbox = document.getElementById("mainConsentCheckbox");
  const sedationConsentCheckbox = document.getElementById("sedationConsentCheckbox");
  const digitalSignatureInput = document.getElementById("digitalSignature");
  const consentDateInput = document.getElementById("consentDate");
  const submitBookingButton = document.getElementById("submitBookingButton");
  const consentStatusMessage = document.getElementById("consentStatusMessage");
  const scheduleSummaryText = document.getElementById("scheduleSummaryText");
  const bookingSummaryText = document.getElementById("bookingSummaryText");

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

    // Save consent data before submitting
    sessionStorage.setItem("bookingConsentStep", JSON.stringify(consentPayload));

    setSubmitLoading(true);
    clearStatusError();

    try {
      await submitBookingToAPI();
    } catch (err) {
      setSubmitLoading(false);
      showStatusError(err.message || "Unable to submit booking. Please try again.");
    }
  });

  // ── API SUBMISSION ────────────────────────────────────────────────

  async function submitBookingToAPI() {
    const token = localStorage.getItem("auth_token");

    if (!token) {
      window.location.replace("./login.html");
      return;
    }

    // Step 1: Get booking schedule (date + time slot)
    const bookingSchedule = getStoredData("bookingSchedule");

    if (!bookingSchedule?.date) {
      throw new Error(
        "Booking schedule not found. Please start over from Step 1.",
      );
    }

    // Step 2: Fetch available time windows to get a valid window_id
    let timeslotsData;
    try {
      const timeslotsRes = await fetch(
        `${API_BASE}/timeslots?date=${encodeURIComponent(bookingSchedule.date)}`,
      );
      timeslotsData = await timeslotsRes.json().catch(() => ({}));

      if (!timeslotsRes.ok) {
        throw new Error("Unable to verify time slot availability.");
      }
    } catch (fetchErr) {
      if (fetchErr.message && fetchErr.message !== "Failed to fetch") {
        throw fetchErr;
      }
      throw new Error(
        "Unable to connect to the server. Make sure the backend is running.",
      );
    }

    const windows = Array.isArray(timeslotsData.windows)
      ? timeslotsData.windows
      : [];

    // Use the first available (non-full) window, or the first window if all are full
    const selectedWindow =
      windows.find((w) => !w.is_full) || windows[0] || null;

    if (!selectedWindow) {
      throw new Error(
        "No time slots are available for this date. Please go back to Step 1 and choose a different date.",
      );
    }

    // Step 3: Get pets from review draft (has service selections) + step2 (has pet details)
    const review =
      getStoredData("bookingStep4Review") || getStoredData("bookingReview");
    const step2 = getStoredData("bookingStep2");

    const reviewPets = Array.isArray(review?.pets) ? review.pets : [];
    const step2Pets = Array.isArray(step2?.pets) ? step2.pets : [];

    if (reviewPets.length === 0) {
      throw new Error(
        "No pet data found. Please start over from Step 2.",
      );
    }

    // Step 4: Build pets payload — merge review pet (service info) with step2 pet (physical details)
    const pets = reviewPets.map((reviewPet) => {
      const step2Pet =
        step2Pets.find((p) => p.id === reviewPet.petId) || {};

      return {
        pet_name: reviewPet.petName || step2Pet.petName || "",
        species: normalizeSpecies(reviewPet.petType || step2Pet.petType),
        breed: reviewPet.breed || step2Pet.breed || null,
        size: normalizePetSize(reviewPet.size || step2Pet.size) || null,
        fur_type: normalizeFurType(step2Pet.furType) || null,
        weight: step2Pet.weight ? parseFloat(step2Pet.weight) || null : null,
        color: null,
        medical_conditions: step2Pet.medicalNotes || null,
        special_instructions: reviewPet.specialInstructions || null,
      };
    });

    // Step 5: Submit booking
    const payload = {
      booking_date: bookingSchedule.date,
      window_id: selectedWindow.window_id,
      number_of_pets: pets.length,
      special_notes: null,
      pets,
    };

    let responseData;
    try {
      const res = await fetch(`${API_BASE}/booking/store`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          Authorization: "Bearer " + token,
        },
        body: JSON.stringify(payload),
      });

      responseData = await res.json().catch(() => ({}));

      if (!res.ok) {
        throw new Error(
          responseData.message || "Booking submission failed. Please try again.",
        );
      }
    } catch (fetchErr) {
      if (fetchErr.message && fetchErr.message !== "Failed to fetch") {
        throw fetchErr;
      }
      throw new Error(
        "Unable to connect to the server. Make sure the backend is running.",
      );
    }

    // Step 6: Save API response and redirect to confirmed page
    sessionStorage.setItem("bookingApiResponse", JSON.stringify(responseData));
    window.location.href = "./booking-confirmed.html";
  }

  // ── NORMALIZATION HELPERS ─────────────────────────────────────────

  function normalizePetSize(size) {
    const map = {
      small: "small",
      medium: "medium",
      large: "large",
      "extra large": "extra_large",
      extra_large: "extra_large",
      xl: "extra_large",
    };
    return map[String(size || "").toLowerCase().trim()] || null;
  }

  function normalizeFurType(furType) {
    const map = {
      short: "short",
      medium: "medium",
      long: "long",
      curly: "curl",
      "double coat": "short",
      "short hair": "short",
      "long hair": "long",
      hairless: null,
    };
    const key = String(furType || "").toLowerCase().trim();
    return Object.prototype.hasOwnProperty.call(map, key) ? map[key] : null;
  }

  function normalizeSpecies(petType) {
    const raw = String(petType || "").trim();
    if (!raw) return "Dog";
    return raw.charAt(0).toUpperCase() + raw.slice(1).toLowerCase();
  }

  // ── SUBMIT BUTTON STATE ───────────────────────────────────────────

  function setSubmitLoading(isLoading) {
    submitBookingButton.disabled = isLoading;
    submitBookingButton.textContent = isLoading ? "Submitting…" : "Submit Booking";
    submitBookingButton.className = isLoading
      ? "inline-flex cursor-not-allowed items-center justify-center rounded-xl bg-slate-300 px-5 py-3 text-sm font-semibold text-white"
      : "inline-flex items-center justify-center rounded-xl bg-[#315b7e] px-5 py-3 text-sm font-semibold text-white transition hover:bg-[#274a67]";
  }

  function showStatusError(message) {
    consentStatusMessage.textContent = message;
    consentStatusMessage.className =
      "mb-6 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700";
  }

  function clearStatusError() {
    validateConsentForm();
  }

  // ── FORM VALIDATION ───────────────────────────────────────────────

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
    return (
      mainConsentCheckbox.checked &&
      sedationConsentCheckbox.checked &&
      digitalSignatureInput.value.trim().length > 0
    );
  }

  // ── DRAFT PERSISTENCE ─────────────────────────────────────────────

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
    sedationConsentCheckbox.checked = Boolean(savedDraft.sedationConsentAccepted);
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

  // ── SUMMARY DISPLAY ───────────────────────────────────────────────

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
        ? pet.alaCarteServices.map((id) => formatServiceName(id))
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

  // ── UTILITIES ─────────────────────────────────────────────────────

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
