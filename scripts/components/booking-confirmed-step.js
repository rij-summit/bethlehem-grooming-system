import {
  formatBookingDate,
  formatBookingTimeRange,
} from "../services/booking-format-service.js";
import { escapeHtml } from "../services/booking-draft-service.js";

document.addEventListener("DOMContentLoaded", () => {
  const confirmationContext =
    document.body.dataset.confirmationContext || "customer";
  const isWalkInConfirmation = confirmationContext === "walk-in";
  const bookingReferenceNumber = document.getElementById("bookingReferenceNumber");
  const ownerName = document.getElementById("ownerName");
  const ownerPhone = document.getElementById("ownerPhone");
  const ownerEmail = document.getElementById("ownerEmail");
  const petInfoTableBody = document.getElementById("petInfoTableBody");
  const serviceInfoTableBody = document.getElementById("serviceInfoTableBody");
  const estimatedTotalPrice = document.getElementById("estimatedTotalPrice");
  const appointmentDate = document.getElementById("appointmentDate");
  const appointmentTime = document.getElementById("appointmentTime");
  const submittedAt = document.getElementById("submittedAt");
  const groomingConsentStatus = document.getElementById("groomingConsentStatus");
  const sedationConsentStatus = document.getElementById("sedationConsentStatus");
  const digitalSignatureValue = document.getElementById("digitalSignatureValue");
  const consentDateValue = document.getElementById("consentDateValue");
  const printConfirmationButton = document.getElementById("printConfirmationButton");
  const confirmationStatus = document.getElementById("confirmationStatus");
  const printableConfirmation = document.getElementById("printableConfirmation");
  const printNextSteps = document.getElementById("printNextSteps");
  const PRINT_PAGE_CONTENT_HEIGHT_MM = 273;
  const MAX_ROWS_FOR_SINGLE_PRINT_PAGE = 5;

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
  populateSubmittedAt();
  window.addEventListener("beforeprint", preparePrintLayout);
  window.addEventListener("afterprint", resetPrintLayout);

  printConfirmationButton?.addEventListener("click", () => {
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

    // Keep the browser from falling back to the page URL as an empty title.
    document.title = "\u200B";
    window.addEventListener("afterprint", restoreTitle);
    window.print();
    window.setTimeout(restoreTitle, 1000);
  });

  function populateConfirmationData() {
    if (!confirmation) {
      showConfirmationStatus(
        "Schedule data not found. Please complete the scheduling process from the beginning.",
        "error",
      );
      return;
    }

    // ── Reference number (from backend) ──────────────────
    setText(bookingReferenceNumber, confirmation.booking_reference || "Pending");

    // ── Owner info (populated asynchronously via populateOwnerInfo) ──
    setText(ownerName, "Loading...");
    setText(ownerPhone, "Loading...");
    setText(ownerEmail, "Loading...");

    const pets = Array.isArray(confirmation.pets) ? confirmation.pets : [];

    if (petInfoTableBody) {
      if (pets.length > 0) {
        petInfoTableBody.innerHTML = pets
          .map(
            (pet) => `
              <tr>
                <td class="px-3 py-3 font-medium text-slate-700">${escapeHtml(pet.petName || "Unnamed Pet")}</td>
                <td class="px-3 py-3 text-slate-600">${escapeHtml(formatLabel(pet.petType) || "Not specified")}</td>
                <td class="px-3 py-3 text-slate-600">${escapeHtml(pet.breed || "Not specified")}</td>
                <td class="px-3 py-3 text-slate-600">${escapeHtml(formatLabel(pet.size) || "Not specified")}</td>
              </tr>
            `,
          )
          .join("");
      } else {
        petInfoTableBody.innerHTML = `
          <tr>
            <td colspan="4" class="px-3 py-3 text-slate-500">No pet selected.</td>
          </tr>
        `;
      }
    }

    // ── Service info (from review data) ──────────────────
    const reviewPets = Array.isArray(confirmation.review?.pets)
      ? confirmation.review.pets
      : [];

    if (reviewPets.length > 0) {
      if (serviceInfoTableBody) {
        serviceInfoTableBody.innerHTML = reviewPets
          .map((rp) => {
            const serviceNames = Array.isArray(rp.selectedServiceNames)
              ? rp.selectedServiceNames.map(formatServiceName)
              : [formatServiceName(rp.servicePackage)].filter(Boolean);
            const alaCarteServices = Array.isArray(rp.alaCarteServices)
              ? rp.alaCarteServices.map(formatServiceName)
              : [];
            const price = rp.pricing
              ? formatPriceRange(rp.pricing)
              : "To be confirmed by clinic.";
            const instructions = rp.specialInstructions?.trim() || "No special instructions provided.";

            return `
              <tr>
                <td class="px-3 py-3 font-medium text-slate-700">${escapeHtml(rp.petName || "Unnamed Pet")}</td>
                <td class="px-3 py-3 text-slate-600">${escapeHtml(serviceNames.join(", ") || "No service selected")}</td>
                <td class="px-3 py-3 text-slate-600">${escapeHtml(alaCarteServices.join(", ") || "No A la Carte service selected")}</td>
                <td class="whitespace-nowrap px-3 py-3 font-semibold text-slate-700">${escapeHtml(price)}</td>
                <td class="px-3 py-3 text-slate-600">${escapeHtml(instructions)}</td>
              </tr>
            `;
          })
          .join("");
      }

      const totalPricing = confirmation.review?.totalPricing;
      setText(
        estimatedTotalPrice,
        totalPricing
          ? formatPriceRange(totalPricing)
          : "To be confirmed by clinic.",
      );
    } else {
      if (serviceInfoTableBody) {
        serviceInfoTableBody.innerHTML = `
          <tr>
            <td colspan="5" class="px-3 py-3 text-slate-500">No service selected.</td>
          </tr>
        `;
      }
      setText(estimatedTotalPrice, "To be confirmed by clinic.");
    }

    // ── Schedule ──────────────────────────────────────────
    setText(
      appointmentDate,
      formatBookingDate(confirmation.booking_date) || "No pet drop-off date selected.",
    );
    setText(
      appointmentTime,
      formatBookingTimeRange(confirmation.booking_time) || "No pet drop-off time selected.",
    );
    setText(
      submittedAt,
      formatSubmittedAt(confirmation.submitted_at || confirmation.created_at) ||
        "Not available.",
    );

    // ── Consent ───────────────────────────────────────────
    setText(
      groomingConsentStatus,
      bookingConsentStep?.groomingAgreementAccepted ? "Agreed" : "Not confirmed",
    );
    setText(
      sedationConsentStatus,
      bookingConsentStep?.sedationConsentAccepted ? "Agreed" : "Not confirmed",
    );
    setText(
      digitalSignatureValue,
      bookingConsentStep?.digitalSignature || "No signature available.",
    );
    setText(
      consentDateValue,
      bookingConsentStep?.consentDate || "No consent date available.",
    );

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
      hideConfirmationStatus();
    } else {
      showConfirmationStatus(
        "Some confirmation details are incomplete. Please contact the clinic if you believe this is an error.",
      );
    }
  }

  function setText(element, value) {
    if (element) {
      element.textContent = value;
    }
  }

  function preparePrintLayout() {
    if (!printableConfirmation || !printNextSteps) return;

    const petCount = Array.isArray(confirmation?.pets)
      ? confirmation.pets.length
      : 0;
    const serviceRowCount = Array.isArray(confirmation?.review?.pets)
      ? confirmation.review.pets.length
      : 0;
    const declaredPetCount = Number(confirmation?.number_of_pets) || 0;
    const largestRowCount = Math.max(
      petCount,
      serviceRowCount,
      declaredPetCount,
    );
    const pageCount = largestRowCount > MAX_ROWS_FOR_SINGLE_PRINT_PAGE ? 2 : 1;

    printableConfirmation.style.setProperty(
      "--booking-print-min-height",
      `${pageCount * PRINT_PAGE_CONTENT_HEIGHT_MM}mm`,
    );
  }

  function resetPrintLayout() {
    printableConfirmation?.style.removeProperty("--booking-print-min-height");
  }

  function hideConfirmationStatus() {
    if (!confirmationStatus) return;

    confirmationStatus.hidden = true;
    confirmationStatus.textContent = "";
    confirmationStatus.className = "hidden";
  }

  function showConfirmationStatus(message, variant = "default") {
    if (!confirmationStatus) return;

    confirmationStatus.hidden = false;
    confirmationStatus.textContent = message;
    confirmationStatus.className = variant === "error"
      ? "mb-6 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
      : "mb-6 rounded-2xl border border-[#9bb9d3] bg-white px-4 py-3 text-sm text-slate-600";
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

  function formatSubmittedAt(value) {
    if (!value) return "";

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return "";

    return new Intl.DateTimeFormat("en-PH", {
      year: "numeric",
      month: "long",
      day: "numeric",
      hour: "numeric",
      minute: "2-digit",
      hour12: true,
    }).format(date);
  }

  async function populateSubmittedAt() {
    const savedTimestamp = confirmation?.submitted_at || confirmation?.created_at;

    if (savedTimestamp || isWalkInConfirmation || !confirmation?.booking_reference) {
      return;
    }

    try {
      const data = await API.getBookingHistory();
      const bookings = [
        ...(Array.isArray(data?.bookings) ? data.bookings : []),
        ...(Array.isArray(data?.history) ? data.history : []),
      ];
      const savedBooking = bookings.find(
        (booking) =>
          booking.booking_reference === confirmation.booking_reference,
      );
      const createdAt = savedBooking?.created_at;

      if (!createdAt) return;

      confirmation.submitted_at = createdAt;
      sessionStorage.setItem("bookingConfirmation", JSON.stringify(confirmation));
      setText(submittedAt, formatSubmittedAt(createdAt) || "Not available.");
    } catch {
      // Keep the existing fallback when history cannot be loaded.
    }
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

      setText(ownerName, fullName);
      setText(ownerPhone, formatMobileNumber(owner.phone) || "Not provided");
      setText(ownerEmail, owner.email || "Not provided");
      return;
    }

    if (isWalkInConfirmation) {
      setText(ownerName, "Not provided");
      setText(ownerPhone, "Not provided");
      setText(ownerEmail, "Not provided");
      return;
    }

    try {
      const data = await API.getMe("customer");
      const user = data?.user;
      const fullName = [user?.first_name, user?.last_name].filter(Boolean).join(" ")
        || user?.username
        || "Not provided";
      setText(ownerName, fullName);
      setText(ownerPhone, formatMobileNumber(user?.phone) || "Not provided");
      setText(ownerEmail, user?.email || "Not provided");
    } catch {
      setText(ownerName, "Not provided");
      setText(ownerPhone, "Not provided");
      setText(ownerEmail, "Not provided");
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
