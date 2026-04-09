document.addEventListener("DOMContentLoaded", () => {
  const bookingReferenceNumber = document.getElementById(
    "bookingReferenceNumber",
  );
  const ownerName = document.getElementById("ownerName");
  const ownerPhone = document.getElementById("ownerPhone");
  const petName = document.getElementById("petName");
  const petType = document.getElementById("petType");
  const petBreed = document.getElementById("petBreed");
  const petSize = document.getElementById("petSize");
  const selectedService = document.getElementById("selectedService");
  const selectedAddOn = document.getElementById("selectedAddOn");
  const specialInstructions = document.getElementById("specialInstructions");
  const estimatedTotalPrice = document.getElementById("estimatedTotalPrice");
  const appointmentDate = document.getElementById("appointmentDate");
  const appointmentTime = document.getElementById("appointmentTime");
  const groomingConsentStatus = document.getElementById(
    "groomingConsentStatus",
  );
  const sedationConsentStatus = document.getElementById(
    "sedationConsentStatus",
  );
  const digitalSignatureValue = document.getElementById(
    "digitalSignatureValue",
  );
  const consentDateValue = document.getElementById("consentDateValue");
  const printConfirmationButton = document.getElementById(
    "printConfirmationButton",
  );
  const confirmationStatus = document.getElementById("confirmationStatus");

  const BOOKING_SUBMISSION_STORAGE_KEY = "bookingSubmission";
  const BOOKING_FORM_LOCK_STORAGE_KEY = "bethlehem.bookingFormLock";
  const BOOKING_FORM_LOCK_WINDOW_MS = 24 * 60 * 60 * 1000;

  const state = {
    bookingStep1: getStoredData("bookingStep1"),
    bookingStep2: getStoredData("bookingStep2"),
    bookingStep3: getStoredData("bookingStep3"),
    bookingConsentStep: getStoredData("bookingConsentStep"),
    bookingSubmission: getStoredData(BOOKING_SUBMISSION_STORAGE_KEY),
    backendBooking: null,
  };

  init();

  async function init() {
    await hydrateBookingFromBackend();
    populateConfirmationData();
    installBackButtonRedirectGuard();

    if (printConfirmationButton) {
      printConfirmationButton.addEventListener("click", () => {
        window.print();
      });
    }
  }

  async function hydrateBookingFromBackend() {
    const urlReference = getReferenceFromUrl();
    const sessionReference = state.bookingSubmission?.booking?.reference;
    const reference = urlReference || sessionReference;

    if (
      !reference ||
      !window.BethlehemApi ||
      typeof window.BethlehemApi.getBooking !== "function"
    ) {
      return;
    }

    try {
      const response = await window.BethlehemApi.getBooking(reference);
      const booking = response?.booking || null;
      if (!booking) return;

      state.backendBooking = booking;
      state.bookingSubmission = { success: true, booking };
      sessionStorage.setItem(
        BOOKING_SUBMISSION_STORAGE_KEY,
        JSON.stringify(state.bookingSubmission),
      );

      if (booking?.payload && typeof booking.payload === "object") {
        state.bookingStep1 = booking.payload.bookingStep1 || state.bookingStep1;
        state.bookingStep2 = booking.payload.bookingStep2 || state.bookingStep2;
        state.bookingStep3 = booking.payload.bookingStep3 || state.bookingStep3;
        state.bookingConsentStep =
          booking.payload.consent || state.bookingConsentStep;
      }

      if (booking?.consent && typeof booking.consent === "object") {
        state.bookingConsentStep = booking.consent;
      }
    } catch (error) {
      console.error("Failed to load booking from backend:", error);
    }
  }

  function populateConfirmationData() {
    const backendBooking = state.backendBooking;
    const submissionBooking = state.bookingSubmission?.booking || null;
    const step1 = state.bookingStep1;
    const step2 = state.bookingStep2;
    const step3 = state.bookingStep3;
    const consent = state.bookingConsentStep;
    const primaryPet = getPrimaryPetDetails(backendBooking, step2, step3);

    const reference =
      backendBooking?.reference ||
      submissionBooking?.reference ||
      generateTemporaryBookingReference();
    bookingReferenceNumber.textContent = reference;
    persistBookingFormLock(reference);

    ownerName.textContent =
      backendBooking?.ownerName ||
      submissionBooking?.ownerName ||
      consent?.digitalSignature ||
      step1?.ownerName ||
      step2?.ownerName ||
      "No owner name available.";

    ownerPhone.textContent =
      backendBooking?.ownerPhone ||
      submissionBooking?.ownerPhone ||
      step1?.ownerPhone ||
      step2?.ownerPhone ||
      "No contact number available.";

    petName.textContent = primaryPet?.petName || "No pet selected.";
    petType.textContent =
      formatLabel(primaryPet?.petType) || "No pet type available.";
    petBreed.textContent = primaryPet?.petBreed || "Breed not specified.";
    petSize.textContent = formatLabel(primaryPet?.petSize) || "Size not specified.";

    selectedService.textContent =
      formatServiceName(primaryPet?.servicePackage || step3?.servicePackage) ||
      "No service selected.";

    selectedAddOn.textContent = formatAddOns(
      primaryPet?.addOns || primaryPet?.alaCarteServices || step3?.addOns,
    );
    specialInstructions.textContent =
      primaryPet?.specialInstructions ||
      backendBooking?.specialNotes ||
      step3?.specialInstructions ||
      "No special instructions provided.";

    appointmentDate.textContent =
      backendBooking?.bookingDate ||
      submissionBooking?.bookingDate ||
      step2?.bookingDate ||
      step1?.bookingDate ||
      "No booking date selected.";

    appointmentTime.textContent =
      backendBooking?.bookingTime ||
      submissionBooking?.bookingTime ||
      step2?.bookingTime ||
      step1?.bookingTime ||
      "No booking time selected.";

    groomingConsentStatus.textContent = consent?.groomingAgreementAccepted
      ? "Agreed"
      : "Not confirmed";

    sedationConsentStatus.textContent = consent?.sedationConsentAccepted
      ? "Agreed"
      : "Not confirmed";

    digitalSignatureValue.textContent =
      consent?.digitalSignature || "No signature available.";
    consentDateValue.textContent = consent?.consentDate || "No consent date available.";

    estimatedTotalPrice.textContent = getEstimatedPrice(primaryPet, step3);

    updateConfirmationStatus(
      Boolean(backendBooking?.reference || submissionBooking?.reference),
      reference,
      consent,
    );
  }

  function getPrimaryPetDetails(backendBooking, bookingStep2, bookingStep3) {
    const backendPet = Array.isArray(backendBooking?.pets)
      ? backendBooking.pets[0]
      : null;

    if (backendPet && typeof backendPet === "object") {
      return {
        petName: backendPet.petName || bookingStep2?.petName || "",
        petType: backendPet.petType || bookingStep2?.petType || "",
        petBreed: backendPet.petBreed || bookingStep2?.petBreed || "",
        petSize: backendPet.petSize || bookingStep2?.petSize || "",
        servicePackage:
          backendPet.servicePackage || bookingStep3?.servicePackage || "",
        addOns: backendPet.addOns || backendPet.alaCarteServices || [],
        specialInstructions:
          backendPet.specialInstructions ||
          bookingStep3?.specialInstructions ||
          "",
      };
    }

    return {
      petName: bookingStep2?.petName || "",
      petType: bookingStep2?.petType || "",
      petBreed: bookingStep2?.petBreed || "",
      petSize: bookingStep2?.petSize || "",
      servicePackage: bookingStep3?.servicePackage || "",
      addOns: bookingStep3?.addOns || [],
      specialInstructions: bookingStep3?.specialInstructions || "",
    };
  }

  function getEstimatedPrice(primaryPet, bookingStep3) {
    const petTypeValue = (primaryPet?.petType || "").toLowerCase();
    const petSizeValue = normalizeSize(primaryPet?.petSize || "");
    const serviceValue = primaryPet?.servicePackage || bookingStep3?.servicePackage || "";

    const dogPriceTable = {
      partial_grooming: {
        small: 400,
        medium: 500,
        large: 600,
        extra_large: 700,
      },
      regular_dog_grooming: {
        small: 550,
        medium: 650,
        large: 850,
        extra_large: 1050,
      },
      deluxe_dog_grooming: {
        small: 650,
        medium: 750,
        large: 1000,
        extra_large: 1200,
      },
      bath_and_go: {
        small: 450,
        medium: 550,
        large: 650,
        extra_large: 750,
      },
    };

    const catPriceTable = {
      cat_full_grooming: {
        small: 500,
        medium: 600,
      },
    };

    if (petTypeValue === "dog") {
      const servicePrices = dogPriceTable[serviceValue];
      if (servicePrices && servicePrices[petSizeValue]) {
        return `PHP ${servicePrices[petSizeValue].toLocaleString("en-PH")}`;
      }

      if (serviceValue === "partial_grooming") return "PHP 400 - 700+";
      if (serviceValue === "regular_dog_grooming") return "PHP 550 - 1,050+";
      if (serviceValue === "deluxe_dog_grooming") return "PHP 650 - 1,200+";
      if (serviceValue === "bath_and_go") return "PHP 450 - 750+";
    }

    if (petTypeValue === "cat") {
      const servicePrices = catPriceTable[serviceValue];
      if (servicePrices && servicePrices[petSizeValue]) {
        return `PHP ${servicePrices[petSizeValue].toLocaleString("en-PH")}`;
      }

      if (serviceValue === "cat_full_grooming") return "PHP 500 - 600";
      if (serviceValue === "cat_alacarte")
        return "Varies by selected cat service";
    }

    return "To be confirmed by clinic.";
  }

  function formatAddOns(addOns) {
    if (!Array.isArray(addOns) || addOns.length === 0) {
      return "No add-on selected.";
    }

    return addOns
      .map((item) => item.replaceAll("_", " "))
      .map((item) => item.replace(/\b\w/g, (char) => char.toUpperCase()))
      .join(", ");
  }

  function formatServiceName(value) {
    if (!value) return "";
    return value
      .replaceAll("_", " ")
      .replace(/\b\w/g, (char) => char.toUpperCase());
  }

  function formatLabel(value) {
    if (!value) return "";
    return String(value)
      .replaceAll("_", " ")
      .replace(/\b\w/g, (char) => char.toUpperCase());
  }

  function normalizeSize(size) {
    const normalized = String(size).toLowerCase().trim();

    if (normalized === "small") return "small";
    if (normalized === "medium") return "medium";
    if (normalized === "large") return "large";
    if (
      normalized === "extra large" ||
      normalized === "extra_large" ||
      normalized === "xl"
    ) {
      return "extra_large";
    }

    return normalized;
  }

  function generateTemporaryBookingReference() {
    const characters = "ABCDEFGHJKLMNPQRSTUVWXYZ0123456789";
    let suffix = "";

    for (let i = 0; i < 4; i += 1) {
      suffix += characters.charAt(
        Math.floor(Math.random() * characters.length),
      );
    }

    return `GRM-${suffix}`;
  }

  function updateConfirmationStatus(hasBackendReference, reference, consent) {
    if (hasBackendReference) {
      confirmationStatus.textContent = `Booking confirmation loaded from backend. Reference: ${reference}.`;
      confirmationStatus.className =
        "mb-6 rounded-2xl border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-700";
      return;
    }

    const hasConsent =
      consent?.groomingAgreementAccepted &&
      consent?.sedationConsentAccepted &&
      consent?.digitalSignature;

    if (hasConsent) {
      confirmationStatus.textContent =
        "Booking confirmation is complete, but backend booking details were not loaded.";
      confirmationStatus.className =
        "mb-6 rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-700";
      return;
    }

    confirmationStatus.textContent =
      "Some confirmation details are missing. Please return to the booking form and submit again.";
    confirmationStatus.className =
      "mb-6 rounded-2xl border border-[#9bb9d3] bg-white px-4 py-3 text-sm text-slate-600";
  }

  function getReferenceFromUrl() {
    const params = new URLSearchParams(window.location.search);
    return params.get("reference") || params.get("ref") || "";
  }

  function persistBookingFormLock(reference) {
    if (!reference) {
      return;
    }

    const now = Date.now();
    const lock = {
      reference,
      lockedAt: new Date(now).toISOString(),
      expiresAt: new Date(now + BOOKING_FORM_LOCK_WINDOW_MS).toISOString(),
    };

    try {
      const serializedLock = JSON.stringify(lock);
      sessionStorage.setItem(BOOKING_FORM_LOCK_STORAGE_KEY, serializedLock);
      localStorage.setItem(BOOKING_FORM_LOCK_STORAGE_KEY, serializedLock);
    } catch (error) {
      console.error("Failed to persist booking form lock:", error);
    }
  }

  function installBackButtonRedirectGuard() {
    window.history.pushState(
      { bookingConfirmedGuard: true },
      "",
      window.location.href,
    );

    window.addEventListener("popstate", () => {
      window.location.replace("./dashboard.html");
    });
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
