import {
  escapeHtml,
  formatPetSizeLabel,
  formatPetTypeLabel,
} from "../services/booking-draft-service.js";
import {
  buildBookingReviewPayload,
  formatAmountRange,
  formatPriceOption,
  getPackageById,
} from "../services/grooming-service.js";
import { renderWalkInConsentStep } from "./walk-in-consent-step.js";

const state = {
  bookingDraft: null,
  petSelections: [],
  reviewPayload: null,
  onBack: null,
};

const WALK_IN_REVIEW_STORAGE_KEY = "walkInReviewStep";

/*
  BACKEND TEAMMATE + CLAUDE CODE:
  The review payload is a frontend snapshot of the walk-in draft. When database
  support is ready, load this page from the saved draft and use backend-priced
  totals as the source of truth.
*/

let elements = {};

function getReviewMainMarkup() {
  return `
    <main class="mx-auto max-w-6xl px-4 py-8 md:px-6 lg:px-8">
      <button
        id="walkInReviewBackTopBtn"
        type="button"
        class="inline-flex items-center gap-2 text-sm font-medium text-[#315b7e] hover:underline"
      >
        <span>&larr;</span>
        <span>Back to Grooming Services</span>
      </button>

      <section class="mt-4">
        <h1 class="text-3xl font-bold text-[#2f4b66]">Review your selected drop-off Schedule</h1>

        <div class="mt-6">
          <div class="mb-2 flex items-center justify-between text-xs font-medium text-slate-500">
            <span>Step 4 of 5</span>
            <span>80% Complete</span>
          </div>
          <div class="h-2 w-full rounded-full bg-slate-200">
            <div class="h-2 rounded-full bg-[#315b7e]" style="width: 80%"></div>
          </div>
        </div>
      </section>

      <section class="mt-6 rounded-[28px] border border-slate-200 bg-[#d9e8f4] p-5 shadow-sm md:p-8">
        <div class="mb-6">
          <h2 class="text-2xl font-bold text-[#2f4b66]">Schedule Review</h2>
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
              Pet Summary
            </p>
            <p id="petReviewText" class="mt-2 text-sm text-slate-600">
              No pet selected yet.
            </p>
          </div>
        </div>

        <div
          id="reviewNotice"
          class="hidden"
        ></div>

        <div id="reviewSelections" class="space-y-6"></div>

        <section class="mt-8 rounded-2xl border border-slate-200 bg-white p-5">
          <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">
            Price Summary
          </p>
          <h3
            id="totalPriceHeading"
            class="mt-3 text-2xl font-bold text-[#2f4b66]"
          >
            Total Price
          </h3>
          <p id="totalPriceText" class="mt-2 text-3xl font-bold text-[#244764]">
            P0
          </p>
          <p id="totalPriceSubtext" class="mt-3 text-sm text-slate-500">
            Pricing details will appear once services are selected.
          </p>
        </section>

        <div
          id="reviewActionNotice"
          class="mt-6 hidden rounded-2xl border px-4 py-3 text-sm"
        ></div>

        <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <button
            id="walkInReviewBackBtn"
            type="button"
            class="inline-flex items-center justify-center rounded-xl border border-[#315b7e] px-5 py-3 text-sm font-medium text-[#315b7e] transition hover:bg-white"
          >
            Back
          </button>

          <button
            id="confirmBookingBtn"
            type="button"
            class="inline-flex items-center justify-center rounded-xl bg-[#315b7e] px-5 py-3 text-sm font-semibold text-white transition hover:bg-[#274a67]"
          >
            Next Step
          </button>
        </div>
      </section>
    </main>
  `;
}

function refreshElements() {
  elements = {
    petReviewText: document.getElementById("petReviewText"),
    reviewNotice: document.getElementById("reviewNotice"),
    reviewSelections: document.getElementById("reviewSelections"),
    totalPriceHeading: document.getElementById("totalPriceHeading"),
    totalPriceText: document.getElementById("totalPriceText"),
    totalPriceSubtext: document.getElementById("totalPriceSubtext"),
    reviewActionNotice: document.getElementById("reviewActionNotice"),
    confirmBookingBtn: document.getElementById("confirmBookingBtn"),
    backButtons: [
      document.getElementById("walkInReviewBackTopBtn"),
      document.getElementById("walkInReviewBackBtn"),
    ].filter(Boolean),
  };
}

function bindEvents() {
  elements.confirmBookingBtn?.addEventListener("click", handleConfirmClick);
  elements.backButtons.forEach((button) => {
    button.addEventListener("click", handleBackClick);
  });
}

function handleBackClick() {
  if (typeof state.onBack === "function") {
    state.onBack();
    return;
  }

  window.location.href = "./walk-in-grooming-services.html";
}

function handleConfirmClick() {
  if (!state.reviewPayload) {
    return;
  }

  saveReviewDraft();
  window.history.pushState(null, "", "./walk-in-consent.html");
  renderWalkInConsentStep({
    reviewPayload: state.reviewPayload,
    onBack: () => {
      window.history.pushState(null, "", "./walk-in-booking-review.html");
      renderWalkInReviewStep({
        pets: state.bookingDraft.pets,
        petSelections: state.petSelections,
        onBack: state.onBack,
      });
    },
  });
}

function saveReviewDraft() {
  /*
    BACKEND TEAMMATE + CLAUDE CODE:
    sessionStorage keeps the review available for the consent and confirmation
    screens only. Replace this with a walk-in draft save / fetch when available.
  */
  sessionStorage.setItem(
    WALK_IN_REVIEW_STORAGE_KEY,
    JSON.stringify(state.reviewPayload),
  );
}

function renderEmptyState(message) {
  elements.reviewNotice.className =
    "mb-6 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700";
  elements.reviewNotice.textContent = message;
  elements.reviewSelections.innerHTML = `
    <div class="rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-700">
      ${escapeHtml(message)}
    </div>
  `;
  elements.totalPriceHeading.textContent = "Total Price";
  elements.totalPriceText.textContent = "P0";
  elements.totalPriceSubtext.textContent =
    "Review data is unavailable until the earlier walk-in schedule steps are completed.";
  elements.confirmBookingBtn.disabled = true;
  elements.confirmBookingBtn.classList.add("opacity-50", "cursor-not-allowed");
}

function renderSummary() {
  const petSummary = state.bookingDraft.pets
    .map(
      (pet) =>
        `${pet.petName || "Unnamed Pet"} (${formatPetTypeLabel(pet.petType)})`,
    )
    .join(", ");

  elements.petReviewText.textContent =
    state.bookingDraft.pets.length > 1
      ? `${state.bookingDraft.pets.length} pets selected: ${petSummary}`
      : petSummary;
}

function renderReviewNotice() {
  elements.reviewNotice.innerHTML = "";
  elements.reviewNotice.className = "hidden";
}

function renderReviewSelections() {
  elements.reviewSelections.innerHTML = state.reviewPayload.items
    .map((item, index) => renderPetReviewCard(item, index))
    .join("");
}

function renderPetReviewCard(item, index) {
  const packageId = item.selection.servicePackage;
  const selectedPackage = packageId ? getPackageById(packageId) : null;
  const alaCarteLineItems = item.pricing.lineItems.filter(
    (lineItem) => lineItem.kind === "ala_carte",
  );
  const packageCard = selectedPackage
    ? renderPackageReview(selectedPackage, item)
    : "";
  const alaCarteCard =
    alaCarteLineItems.length > 0
      ? renderAlaCarteReview(alaCarteLineItems)
      : "";
  const missingSelectionNotice =
    !selectedPackage && item.pricing.lineItems.length === 0
      ? `
        <div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
          No service is attached to this pet yet. Please go back to Step 3 and finish the selection.
        </div>
      `
      : "";

  return `
    <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
        <div>
          <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Pet ${
            index + 1
          }</p>
          <h3 class="mt-1 text-xl font-bold text-[#2f4b66]">${escapeHtml(
            item.pet.petName || "Unnamed Pet",
          )}</h3>
          <p class="mt-2 text-sm text-slate-500">${escapeHtml(
            formatPetTypeLabel(item.pet.petType),
          )} | ${escapeHtml(item.pet.breed || "Breed not specified")}</p>
        </div>

        <div class="flex flex-col gap-2 md:items-end">
          <span class="rounded-full bg-[#edf5fc] px-3 py-1 text-xs font-semibold text-[#315b7e]">
            ${escapeHtml(formatPetSizeLabel(item.pet.size))}
          </span>
          <span class="text-sm text-slate-500">${escapeHtml(
            item.pricing.hasSelection ? formatAmountRange(item.pricing.total) : "No price available",
          )}</span>
        </div>
      </div>

      <div class="mt-6 space-y-4">
        ${packageCard}
        ${alaCarteCard}
        ${missingSelectionNotice}

        ${
          item.selection.specialInstructions.trim()
            ? `
              <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                  Special Instructions
                </p>
                <p class="mt-2 text-sm text-slate-600">${escapeHtml(
                  item.selection.specialInstructions.trim(),
                )}</p>
              </div>
            `
            : ""
        }
      </div>
    </article>
  `;
}

function renderPackageReview(selectedPackage, item) {
  const packageLineItem = item.pricing.lineItems.find(
    (lineItem) =>
      lineItem.kind === "package" && lineItem.serviceId === selectedPackage.id,
  );

  if (!packageLineItem) {
    return "";
  }

  const pricingNote = getPackagePricingNote(packageLineItem);
  const pricingNoteMarkup = pricingNote
    ? `<p class="mt-3 text-sm text-slate-500">${escapeHtml(pricingNote)}</p>`
    : "";

  return `
    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">
        Selected Package
      </p>
      <div class="mt-2 flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
        <h4 class="text-lg font-semibold text-[#2f4b66]">${escapeHtml(
          selectedPackage.name,
        )}</h4>
        <span class="rounded-full bg-[#edf5fc] px-3 py-1 text-xs font-semibold text-[#315b7e]">
          ${escapeHtml(packageLineItem.pricing.displayPrice)}
        </span>
      </div>
      <div class="service-card__pricing">
        <span class="service-card__pricing-label">Size &amp; Price</span>
        <div class="service-card__price-grid">
          ${selectedPackage.priceOptions
            .map((priceOption) =>
              renderReviewPricePill(priceOption, packageLineItem.pricing.selectedPriceOption),
            )
            .join("")}
        </div>
      </div>
      ${pricingNoteMarkup}
    </div>
  `;
}

function renderReviewPricePill(priceOption, selectedPriceOption) {
  const isSelected =
    selectedPriceOption &&
    selectedPriceOption.label === priceOption.label &&
    selectedPriceOption.sizeKey === priceOption.sizeKey;

  return `
    <div class="service-card__price-pill ${isSelected ? "service-card__price-pill--active" : ""}">
      <span class="service-card__price-size">${escapeHtml(priceOption.label)}</span>
      <span class="service-card__price-value">${escapeHtml(
        formatPriceOption(priceOption),
      )}</span>
    </div>
  `;
}

function renderAlaCarteReview(alaCarteLineItems) {
  return `
    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">
        A la Carte Services
      </p>
      <div class="mt-3 space-y-2">
        ${alaCarteLineItems
          .map(
            (lineItem) => `
              <div class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 px-3 py-3">
                <span class="font-semibold text-[#2f4b66]">${escapeHtml(
                  lineItem.serviceName,
                )}</span>
                <span class="text-sm text-slate-500">${escapeHtml(
                  lineItem.pricing.displayPrice,
                )}</span>
              </div>
            `,
          )
          .join("")}
      </div>
    </div>
  `;
}

function getPackagePricingNote(packageLineItem) {
  if (packageLineItem.pricing.selectedPriceOption) {
    if (packageLineItem.pricing.selectedPriceOption.pricingType === "plus") {
      return "Final rate will still be confirmed at the clinic.";
    }
    return "";
  }

  if (packageLineItem.pricing.missingSize) {
    return "This pet does not have a saved size yet, so the package total is shown as an estimate.";
  }

  if (packageLineItem.pricing.unmatchedSize) {
    return "The clinic will confirm the applicable rate for this pet size.";
  }

  return "The clinic will confirm the final applicable package rate during review.";
}

function renderTotalPricing() {
  /*
    BACKEND TEAMMATE + CLAUDE CODE:
    These totals are display estimates calculated in the browser. Recompute and
    return the final trusted price from the backend before creating a booking.
  */
  const totalPricing = state.reviewPayload.totalPricing;

  elements.totalPriceHeading.textContent = state.reviewPayload.isEstimate
    ? "Estimated Total"
    : "Total Price";
  elements.totalPriceText.textContent = formatAmountRange(totalPricing);

  if (state.reviewPayload.isEstimate) {
    elements.totalPriceSubtext.classList.remove("hidden");
    elements.totalPriceSubtext.textContent =
      "This total includes at least one estimate because of a missing pet size, a price range, or a clinic-confirmed + rate.";
    return;
  }

  elements.totalPriceSubtext.textContent = "";
  elements.totalPriceSubtext.classList.add("hidden");
}

function guardAdminAccess() {
  /*
    BACKEND TEAMMATE + CLAUDE CODE:
    This browser-only guard should not replace backend authorization on review,
    draft, or submit endpoints.
  */
  const token = API.getAdminToken?.();
  const role = API.getUserRole?.();

  if (token && (role === "admin" || role === "staff")) {
    return true;
  }

  window.location.href = "../client/sign-in.html";
  return false;
}

function initReviewState({ pets = [], petSelections = [], onBack = null } = {}) {
  state.bookingDraft = {
    bookingType: "walk_in",
    pets: Array.isArray(pets) ? pets : [],
  };
  state.petSelections = Array.isArray(petSelections) ? petSelections : [];
  state.onBack = onBack;
  state.reviewPayload = state.bookingDraft.pets.length
    ? buildBookingReviewPayload(state.bookingDraft, state.petSelections)
    : null;
}

export function renderWalkInReviewStep(options = {}) {
  document.title = "Walk-in Schedule - Review";
  document.body.className = "min-h-screen bg-slate-50 text-slate-800";
  document.body.innerHTML = getReviewMainMarkup();

  refreshElements();
  initReviewState(options);
  bindEvents();

  if (!Array.isArray(state.bookingDraft?.pets) || state.bookingDraft.pets.length === 0) {
    renderEmptyState(
      "No pets were found for this walk-in review. Please go back to Step 2 and Step 3 before reviewing.",
    );
    return;
  }

  renderSummary();
  renderReviewNotice();
  renderReviewSelections();
  renderTotalPricing();
}

document.addEventListener("DOMContentLoaded", () => {
  if (!document.getElementById("confirmBookingBtn")) {
    return;
  }

  if (!guardAdminAccess()) {
    return;
  }

  refreshElements();
  initReviewState();
  bindEvents();
  renderEmptyState(
    "No pets were found for this walk-in review. Please go back to Step 2 and Step 3 before reviewing.",
  );
});
