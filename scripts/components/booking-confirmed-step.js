import {
  formatBookingDate,
  formatBookingTimeRange,
} from "../services/booking-format-service.js";

document.addEventListener("DOMContentLoaded", () => {
  const confirmationContext =
    document.body.dataset.confirmationContext || "customer";
  const isWalkInConfirmation = confirmationContext === "walk-in";
  const bookingReferenceNumber = document.getElementById("bookingReferenceNumber");
  const ownerName = document.getElementById("ownerName");
  const ownerPhone = document.getElementById("ownerPhone");
  const petName = document.getElementById("petName");
  const petType = document.getElementById("petType");
  const petBreed = document.getElementById("petBreed");
  const petSize = document.getElementById("petSize");
  const selectedService = document.getElementById("selectedService");
  const selectedAlaCarteMenu = document.getElementById("selectedAlaCarteMenu");
  const specialInstructions = document.getElementById("specialInstructions");
  const estimatedTotalPrice = document.getElementById("estimatedTotalPrice");
  const appointmentDate = document.getElementById("appointmentDate");
  const appointmentTime = document.getElementById("appointmentTime");
  const groomingConsentStatus = document.getElementById("groomingConsentStatus");
  const sedationConsentStatus = document.getElementById("sedationConsentStatus");
  const digitalSignatureValue = document.getElementById("digitalSignatureValue");
  const consentDateValue = document.getElementById("consentDateValue");
  const printConfirmationButton = document.getElementById("printConfirmationButton");
  const confirmationStatus = document.getElementById("confirmationStatus");

  if (isWalkInConfirmation && !guardAdminAccess()) {
    return;
  }

  /*
    BACKEND TEAMMATE + CLAUDE CODE:
    Customer pre-registrations already read their saved confirmation payload. Walk-in
    confirmations still read a frontend-only payload until the backend returns
    the official walk-in schedule response.
  */
  const confirmation = getStoredData(
    isWalkInConfirmation ? "walkInBookingConfirmation" : "bookingConfirmation",
  );
  const bookingConsentStep = getStoredData(
    isWalkInConfirmation ? "walkInConsentStep" : "bookingConsentStep",
  );

  populateConfirmationData();
  populateOwnerInfo();

  printConfirmationButton.addEventListener("click", () => {
    const originalTitle = document.title;
    let titleRestored = false;

    function restoreTitle() {
      if (titleRestored) {
        return;
      }

      titleRestored = true;
      document.title = originalTitle;
      window.removeEventListener("afterprint", restoreTitle);
    }

    document.title = "";
    window.addEventListener("afterprint", restoreTitle);
    window.print();
    window.setTimeout(restoreTitle, 1000);
  });

  function populateConfirmationData() {
    if (!confirmation) {
      confirmationStatus.hidden = false;
      confirmationStatus.textContent =
        "Schedule data not found. Please complete the scheduling process from the beginning.";
      confirmationStatus.className =
        "mb-6 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700";
      return;
    }

    // ── Reference number (from backend) ──────────────────
    bookingReferenceNumber.textContent =
      confirmation.booking_reference || "Pending";

    // ── Owner info (populated asynchronously via populateOwnerInfo) ──
    ownerName.textContent = "Loading...";
    ownerPhone.textContent = "Loading...";

    // Pet info (first pet in the schedule)
    const firstPet = Array.isArray(confirmation.pets) ? confirmation.pets[0] : null;

    if (confirmation.pets?.length > 1) {
      // Multiple pets — summarize all
      petName.textContent = confirmation.pets
        .map((p) => p.petName || "Unnamed")
        .join(", ");
      petType.textContent = confirmation.pets
        .map((p) => p.petType || "")
        .filter(Boolean)
        .join(", ");
      petBreed.textContent = confirmation.pets
        .map((p) => p.breed || "Not specified")
        .join(", ");
      petSize.textContent = confirmation.pets
        .map((p) => formatLabel(p.size) || "Not specified")
        .join(", ");
    } else {
      petName.textContent = firstPet?.petName || "No pet selected.";
      petType.textContent = formatLabel(firstPet?.petType) || "Not specified.";
      petBreed.textContent = firstPet?.breed || "Breed not specified.";
      petSize.textContent = formatLabel(firstPet?.size) || "Size not specified.";
    }

    // ── Service info (from review data) ──────────────────
    const reviewPets = Array.isArray(confirmation.review?.pets)
      ? confirmation.review.pets
      : [];

    if (reviewPets.length > 0) {
      selectedService.textContent = reviewPets
        .map((rp) => {
          if (Array.isArray(rp.selectedServiceNames)) {
            return rp.selectedServiceNames.map(formatServiceName).join(", ");
          }

          return formatServiceName(rp.servicePackage);
        })
        .filter(Boolean)
        .join(", ") || "No service selected.";

      const allAlaCarteServices = reviewPets.flatMap((rp) =>
        Array.isArray(rp.alaCarteServices) ? rp.alaCarteServices : [],
      );
      selectedAlaCarteMenu.textContent = allAlaCarteServices.length > 0
        ? allAlaCarteServices.map(formatServiceName).join(", ")
        : "No A la Carte service selected.";

      const allInstructions = reviewPets
        .map((rp) => rp.specialInstructions?.trim())
        .filter(Boolean)
        .join(" | ");
      specialInstructions.textContent =
        allInstructions || "No special instructions provided.";

      // Estimated total from review
      const totalPricing = confirmation.review?.totalPricing;
      estimatedTotalPrice.textContent = totalPricing
        ? formatPriceRange(totalPricing)
        : "To be confirmed by clinic.";
    } else {
      selectedService.textContent = "No service selected.";
      selectedAlaCarteMenu.textContent = "No A la Carte service selected.";
      specialInstructions.textContent = "No special instructions provided.";
      estimatedTotalPrice.textContent = "To be confirmed by clinic.";
    }

    // ── Schedule ──────────────────────────────────────────
    appointmentDate.textContent =
      formatBookingDate(confirmation.booking_date) || "No pet drop-off date selected.";
    appointmentTime.textContent =
      formatBookingTimeRange(confirmation.booking_time) || "No pet drop-off time selected.";

    // ── Consent ───────────────────────────────────────────
    groomingConsentStatus.textContent =
      bookingConsentStep?.groomingAgreementAccepted ? "Agreed" : "Not confirmed";
    sedationConsentStatus.textContent =
      bookingConsentStep?.sedationConsentAccepted ? "Agreed" : "Not confirmed";
    if (digitalSignatureValue) {
      digitalSignatureValue.textContent =
        bookingConsentStep?.digitalSignature || "No signature available.";
    }
    if (consentDateValue) {
      consentDateValue.textContent =
        bookingConsentStep?.consentDate || "No consent date available.";
    }

    updateConfirmationStatus();
  }

  function updateConfirmationStatus() {
    const hasReference = Boolean(confirmation?.booking_reference);
    const hasConsent = isWalkInConfirmation
      ? Boolean(bookingConsentStep?.groomingAgreementAccepted)
      : Boolean(
          bookingConsentStep?.groomingAgreementAccepted &&
          bookingConsentStep?.digitalSignature,
        );

    if (hasReference && hasConsent) {
      confirmationStatus.hidden = true;
      confirmationStatus.textContent = "";
      confirmationStatus.className = "hidden";
    } else {
      confirmationStatus.hidden = false;
      confirmationStatus.textContent =
        "Some confirmation details are incomplete. Please contact the clinic if you believe this is an error.";
      confirmationStatus.className =
        "mb-6 rounded-2xl border border-[#9bb9d3] bg-white px-4 py-3 text-sm text-slate-600";
    }
  }

  function formatPriceRange(pricing) {
    if (!pricing) return "To be confirmed by clinic.";
    const min = Number(pricing.minAmount || 0).toLocaleString("en-PH");
    if (pricing.maxAmount === null) return `₱${min}+`;
    if (pricing.minAmount === pricing.maxAmount) return `₱${min}`;
    const max = Number(pricing.maxAmount || 0).toLocaleString("en-PH");
    return `₱${min} – ₱${max}`;
  }

  function formatServiceName(value) {
    if (!value) return "";
    return value.replaceAll("_", " ").replace(/\b\w/g, (c) => c.toUpperCase());
  }

  function formatLabel(value) {
    if (!value) return "";
    return String(value).replaceAll("_", " ").replace(/\b\w/g, (c) => c.toUpperCase());
  }

  async function populateOwnerInfo() {
    const owner = confirmation?.owner;

    if (owner) {
      const fullName = owner.fullName ||
        [
          owner.firstName,
          owner.middleInitial ? `${owner.middleInitial}.` : "",
          owner.lastName,
        ]
          .filter(Boolean)
          .join(" ") ||
        "Not provided";

      ownerName.textContent = fullName;
      ownerPhone.textContent = formatMobileNumber(owner.phone) || "Not provided";
      return;
    }

    if (isWalkInConfirmation) {
      ownerName.textContent = "Not provided";
      ownerPhone.textContent = "Not provided";
      return;
    }

    try {
      const data = await API.getMe("customer");
      const user = data?.user;
      const fullName = [user?.first_name, user?.last_name].filter(Boolean).join(" ")
        || user?.username
        || "Not provided";
      ownerName.textContent = fullName;
      ownerPhone.textContent = formatMobileNumber(user?.phone) || "Not provided";
    } catch {
      ownerName.textContent = "Not provided";
      ownerPhone.textContent = "Not provided";
    }
  }

  function getStoredData(key) {
    try {
      const raw = sessionStorage.getItem(key);
      return raw ? JSON.parse(raw) : null;
    } catch {
      return null;
    }
  }

  function formatMobileNumber(value) {
    const text = String(value ?? "").trim();
    if (!text || text === "Not provided") return "";

    const digits = text.replace(/\D/g, "");
    const localDigits = digits.startsWith("639") && digits.length === 12
      ? `0${digits.slice(2)}`
      : digits;

    if (localDigits.length === 11) {
      return `${localDigits.slice(0, 4)}-${localDigits.slice(4, 7)}-${localDigits.slice(7)}`;
    }

    return text;
  }

  function guardAdminAccess() {
    /*
      BACKEND TEAMMATE + CLAUDE CODE:
      This browser guard only keeps the admin confirmation page out of casual
      navigation. The real walk-in confirmation API should enforce permissions.
    */
    const token = API.getAdminToken?.();
    const role = API.getUserRole?.();

    if (token && (role === "admin" || role === "staff")) {
      return true;
    }

    window.location.href = "../client/sign-in.html";
    return false;
  }
});
