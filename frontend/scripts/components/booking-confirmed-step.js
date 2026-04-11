document.addEventListener("DOMContentLoaded", () => {
  // ── Element references ──────────────────────────────────────────
  const bookingReferenceNumber = document.getElementById("bookingReferenceNumber");
  const ownerName = document.getElementById("ownerName");
  const ownerPhone = document.getElementById("ownerPhone");
  const petInfoSection = document.getElementById("petInfoSection");
  const serviceInfoSection = document.getElementById("serviceInfoSection");
  const appointmentDate = document.getElementById("appointmentDate");
  const appointmentTime = document.getElementById("appointmentTime");
  const groomingConsentStatus = document.getElementById("groomingConsentStatus");
  const sedationConsentStatus = document.getElementById("sedationConsentStatus");
  const digitalSignatureValue = document.getElementById("digitalSignatureValue");
  const consentDateValue = document.getElementById("consentDateValue");
  const printConfirmationButton = document.getElementById("printConfirmationButton");
  const confirmationStatus = document.getElementById("confirmationStatus");

  // ── Data sources ────────────────────────────────────────────────
  // Primary: backend response from the booking submission
  const apiResponse = getStoredData("bookingApiResponse");
  // Review draft: pets with service selections and pricing
  const bookingReview =
    getStoredData("bookingStep4Review") || getStoredData("bookingReview");
  // Consent data captured on the previous step
  const bookingConsentStep = getStoredData("bookingConsentStep");
  // Authenticated user stored at login/register
  const authUser = getAuthUser();

  populateConfirmationData();

  printConfirmationButton.addEventListener("click", () => {
    window.print();
  });

  // ── MAIN POPULATION ─────────────────────────────────────────────

  function populateConfirmationData() {
    const booking = apiResponse?.booking || null;

    // Reference number — use official one from backend, fall back to notice
    bookingReferenceNumber.textContent =
      booking?.booking_reference || "Pending — reference will be assigned by the clinic.";

    // Owner information
    ownerName.textContent =
      buildFullName(authUser) ||
      bookingConsentStep?.digitalSignature ||
      "Not available.";

    ownerPhone.textContent = authUser?.phone || "Not available.";

    // Schedule — prefer API response (canonical), fall back to sessionStorage
    const rawDate = booking?.booking_date || bookingReview?.bookingDate || "";
    appointmentDate.textContent = rawDate
      ? formatBookingDate(rawDate)
      : "No booking date selected.";

    appointmentTime.textContent =
      booking?.window ||
      bookingReview?.bookingTime ||
      "No booking time selected.";

    // Pet and service sections
    const reviewPets = Array.isArray(bookingReview?.pets)
      ? bookingReview.pets
      : [];

    renderPetInfoSection(reviewPets);
    renderServiceInfoSection(reviewPets, bookingReview?.totalPricing);

    // Consent
    groomingConsentStatus.textContent = bookingConsentStep?.groomingAgreementAccepted
      ? "Agreed"
      : "Not confirmed";
    sedationConsentStatus.textContent = bookingConsentStep?.sedationConsentAccepted
      ? "Agreed"
      : "Not confirmed";
    digitalSignatureValue.textContent =
      bookingConsentStep?.digitalSignature || "No signature available.";
    consentDateValue.textContent =
      bookingConsentStep?.consentDate || "No consent date available.";

    updateConfirmationStatus(booking);
  }

  // ── PET INFO SECTION ────────────────────────────────────────────

  function renderPetInfoSection(reviewPets) {
    if (reviewPets.length === 0) {
      petInfoSection.innerHTML = `
        <div class="mb-4">
          <h3 class="text-lg font-semibold text-[#2f4b66]">Pet Information</h3>
        </div>
        <p class="text-sm text-slate-500">No pet information available.</p>
      `;
      return;
    }

    if (reviewPets.length === 1) {
      const pet = reviewPets[0];
      petInfoSection.innerHTML = `
        <div class="mb-4">
          <h3 class="text-lg font-semibold text-[#2f4b66]">Pet Information</h3>
        </div>
        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
          <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Pet Name</p>
            <p class="mt-2 text-sm text-slate-700">${escapeHtml(pet.petName || "Not specified")}</p>
          </div>
          <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Pet Type</p>
            <p class="mt-2 text-sm text-slate-700">${escapeHtml(formatLabel(pet.petType))}</p>
          </div>
          <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Breed</p>
            <p class="mt-2 text-sm text-slate-700">${escapeHtml(pet.breed || "Not specified")}</p>
          </div>
          <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Size</p>
            <p class="mt-2 text-sm text-slate-700">${escapeHtml(formatLabel(pet.size))}</p>
          </div>
        </div>
      `;
      return;
    }

    // Multiple pets — render as stacked cards
    const petCards = reviewPets
      .map(
        (pet, index) => `
          <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Pet ${index + 1}</p>
            <p class="mt-1 text-base font-bold text-[#2f4b66]">${escapeHtml(pet.petName || "Unnamed Pet")}</p>
            <div class="mt-3 grid gap-3 sm:grid-cols-3">
              <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Type</p>
                <p class="mt-1 text-sm text-slate-700">${escapeHtml(formatLabel(pet.petType))}</p>
              </div>
              <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Breed</p>
                <p class="mt-1 text-sm text-slate-700">${escapeHtml(pet.breed || "Not specified")}</p>
              </div>
              <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Size</p>
                <p class="mt-1 text-sm text-slate-700">${escapeHtml(formatLabel(pet.size))}</p>
              </div>
            </div>
          </div>
        `,
      )
      .join("");

    petInfoSection.innerHTML = `
      <div class="mb-4">
        <h3 class="text-lg font-semibold text-[#2f4b66]">Pet Information</h3>
        <p class="mt-1 text-sm text-slate-500">${reviewPets.length} pets included in this booking.</p>
      </div>
      <div class="space-y-3">${petCards}</div>
    `;
  }

  // ── SERVICE INFO SECTION ────────────────────────────────────────

  function renderServiceInfoSection(reviewPets, totalPricing) {
    if (reviewPets.length === 0) {
      serviceInfoSection.innerHTML = `
        <div class="mb-4">
          <h3 class="text-lg font-semibold text-[#2f4b66]">Service Information</h3>
        </div>
        <p class="text-sm text-slate-500">No service information available.</p>
      `;
      return;
    }

    if (reviewPets.length === 1) {
      const pet = reviewPets[0];
      const serviceSummary = buildServiceSummary(pet);
      const addOnSummary = buildAddOnSummary(pet);
      const instructions = pet.specialInstructions?.trim() || "";
      const priceSummary = buildPriceSummary(pet.pricing, totalPricing);

      serviceInfoSection.innerHTML = `
        <div class="mb-4">
          <h3 class="text-lg font-semibold text-[#2f4b66]">Service Information</h3>
        </div>
        <div class="grid gap-4 md:grid-cols-2">
          <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Selected Service</p>
            <p class="mt-2 text-sm text-slate-700">${escapeHtml(serviceSummary)}</p>
          </div>
          <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Add-On</p>
            <p class="mt-2 text-sm text-slate-700">${escapeHtml(addOnSummary)}</p>
          </div>
        </div>
        <div class="mt-4">
          <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Special Instructions</p>
          <p class="mt-2 text-sm text-slate-700">${escapeHtml(instructions || "No special instructions provided.")}</p>
        </div>
        ${renderPriceBlock(priceSummary)}
      `;
      return;
    }

    // Multiple pets — per-pet service cards
    const petServiceCards = reviewPets
      .map(
        (pet, index) => `
          <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Pet ${index + 1} — ${escapeHtml(pet.petName || "Unnamed Pet")}</p>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
              <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Service</p>
                <p class="mt-1 text-sm text-slate-700">${escapeHtml(buildServiceSummary(pet))}</p>
              </div>
              <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Add-On</p>
                <p class="mt-1 text-sm text-slate-700">${escapeHtml(buildAddOnSummary(pet))}</p>
              </div>
            </div>
            ${
              pet.specialInstructions?.trim()
                ? `<div class="mt-3">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Special Instructions</p>
                    <p class="mt-1 text-sm text-slate-700">${escapeHtml(pet.specialInstructions.trim())}</p>
                  </div>`
                : ""
            }
          </div>
        `,
      )
      .join("");

    const overallPrice = buildPriceSummary(null, totalPricing);

    serviceInfoSection.innerHTML = `
      <div class="mb-4">
        <h3 class="text-lg font-semibold text-[#2f4b66]">Service Information</h3>
      </div>
      <div class="space-y-3">${petServiceCards}</div>
      ${renderPriceBlock(overallPrice)}
    `;
  }

  function renderPriceBlock(priceSummary) {
    return `
      <div class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-4">
        <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
          <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">
              Estimated Total Price
            </p>
            <p class="mt-2 text-lg font-bold text-amber-800">${escapeHtml(priceSummary)}</p>
          </div>
          <span class="inline-flex w-fit rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700">
            Estimation only
          </span>
        </div>
        <p class="mt-2 text-xs text-amber-700">
          Final price may still change after clinic assessment, coat condition, pet size confirmation, and add-on confirmation.
        </p>
      </div>
    `;
  }

  // ── CONFIRMATION STATUS ─────────────────────────────────────────

  function updateConfirmationStatus(booking) {
    const hasBookingRef = Boolean(booking?.booking_reference);
    const hasConsent =
      bookingConsentStep?.groomingAgreementAccepted &&
      bookingConsentStep?.sedationConsentAccepted &&
      bookingConsentStep?.digitalSignature;

    if (hasBookingRef && hasConsent) {
      confirmationStatus.textContent =
        "Your booking has been submitted and recorded. Please check in at the clinic on your scheduled date.";
      confirmationStatus.className =
        "mb-6 rounded-2xl border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-700";
      return;
    }

    confirmationStatus.textContent =
      "Your booking request has been recorded. The clinic will confirm the final details.";
    confirmationStatus.className =
      "mb-6 rounded-2xl border border-[#9bb9d3] bg-white px-4 py-3 text-sm text-slate-600";
  }

  // ── HELPERS ─────────────────────────────────────────────────────

  function buildServiceSummary(pet) {
    const parts = [];

    if (pet?.servicePackage) {
      parts.push(formatServiceName(pet.servicePackage));
    }

    if (Array.isArray(pet?.alaCarteServices) && pet.alaCarteServices.length > 0) {
      parts.push(...pet.alaCarteServices.map(formatServiceName));
    }

    return parts.length > 0 ? parts.join(", ") : "No service selected.";
  }

  function buildAddOnSummary(pet) {
    if (!Array.isArray(pet?.addOns) || pet.addOns.length === 0) {
      return "No add-on selected.";
    }
    return pet.addOns.map(formatServiceName).join(", ");
  }

  function buildPriceSummary(petPricing, totalPricing) {
    const pricing = petPricing || totalPricing;

    if (!pricing) return "To be confirmed by clinic.";

    const min = pricing.minAmount ?? 0;
    const max = pricing.maxAmount ?? 0;

    if (min === 0 && max === 0) return "To be confirmed by clinic.";

    const formatted = (amount) =>
      "₱" + Number(amount).toLocaleString("en-PH");

    if (min === max) return formatted(min);

    return `${formatted(min)} – ${formatted(max)}${pricing.isEstimate ? "+" : ""}`;
  }

  function buildFullName(user) {
    if (!user) return "";
    const firstName = user.first_name || "";
    const lastName = user.last_name || "";
    return [firstName, lastName].filter(Boolean).join(" ");
  }

  function formatBookingDate(dateStr) {
    if (!dateStr) return "No date";
    // Parse as local date to avoid UTC-offset shifting (e.g. "2024-01-15" → Jan 15)
    const parts = String(dateStr).split("-");
    if (parts.length !== 3) return dateStr;
    const [year, month, day] = parts.map(Number);
    const months = [
      "January", "February", "March", "April", "May", "June",
      "July", "August", "September", "October", "November", "December",
    ];
    return `${months[month - 1]} ${day}, ${year}`;
  }

  function formatServiceName(value) {
    if (!value) return "";
    return value.replaceAll("_", " ").replace(/\b\w/g, (c) => c.toUpperCase());
  }

  function formatLabel(value) {
    if (!value) return "Not specified";
    return String(value).replaceAll("_", " ").replace(/\b\w/g, (c) => c.toUpperCase());
  }

  function escapeHtml(value) {
    return String(value ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function getStoredData(key) {
    try {
      const raw = sessionStorage.getItem(key);
      return raw ? JSON.parse(raw) : null;
    } catch (error) {
      console.error(`Failed to parse sessionStorage key: ${key}`, error);
      return null;
    }
  }

  function getAuthUser() {
    try {
      const raw = localStorage.getItem("auth_user");
      return raw ? JSON.parse(raw) : null;
    } catch {
      return null;
    }
  }
});
