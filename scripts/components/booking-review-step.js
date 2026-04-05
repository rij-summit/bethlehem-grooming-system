import {
  BOOKING_STEP_THREE_KEY,
  escapeHtml,
  formatPetSizeLabel,
  formatPetTypeLabel,
  getBookingDraft,
  readSessionJson,
} from "../services/booking-draft-service.js";
import {
  buildBookingReviewPayload,
  formatAmountRange,
  formatPriceOption,
  getAddOnById,
  getPackageById,
  normalizeStepThreeDraft,
} from "../services/grooming-service.js";

/**
 * Booking Review Step Controller
 *
 * Purpose:
 * Builds the Step 4 review preview from the active booking draft and Step 3
 * grooming selections.
 *
 * Backend developer guide:
 * This page currently assembles a client-side review payload and stores it in
 * sessionStorage. In production, rebuild the review from the saved draft on the
 * backend, recompute totals and pricing notes there, and return the final review
 * payload that consent and submission should use.
 */
const REVIEW_STORAGE_KEY = "bookingStep4Review";
const LEGACY_REVIEW_STORAGE_KEY = "bookingReview";

const elements = {
  scheduleReviewText: document.getElementById("scheduleReviewText"),
  petReviewText: document.getElementById("petReviewText"),
  reviewNotice: document.getElementById("reviewNotice"),
  reviewSelections: document.getElementById("reviewSelections"),
  totalPriceHeading: document.getElementById("totalPriceHeading"),
  totalPriceText: document.getElementById("totalPriceText"),
  totalPriceSubtext: document.getElementById("totalPriceSubtext"),
  reviewActionNotice: document.getElementById("reviewActionNotice"),
  confirmBookingBtn: document.getElementById("confirmBookingBtn"),
};

const state = {
  bookingDraft: null,
  petSelections: [],
  reviewPayload: null,
};

document.addEventListener("DOMContentLoaded", () => {
  state.bookingDraft = getBookingDraft();

  if (!Array.isArray(state.bookingDraft?.pets) || state.bookingDraft.pets.length === 0) {
    renderEmptyState(
      "No pets were found in the current booking draft. Please go back to Step 2 and Step 3 before reviewing.",
    );
    bindEvents();
    return;
  }

  state.petSelections = normalizeStepThreeDraft(
    readSessionJson(BOOKING_STEP_THREE_KEY),
    state.bookingDraft,
  );
  state.reviewPayload = buildBookingReviewPayload(
    state.bookingDraft,
    state.petSelections,
  );

  renderSummary();
  renderReviewNotice();
  renderReviewSelections();
  renderTotalPricing();
  saveReviewDraft();
  bindEvents();
});

function bindEvents() {
  elements.confirmBookingBtn.addEventListener("click", handleConfirmClick);
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
    "Review data is unavailable until the earlier booking steps are completed.";
  elements.confirmBookingBtn.disabled = true;
  elements.confirmBookingBtn.classList.add("opacity-50", "cursor-not-allowed");
}

function renderSummary() {
  elements.scheduleReviewText.textContent = `${state.bookingDraft.bookingDate || "No date"} | ${
    state.bookingDraft.bookingTime || "No time"
  }`;

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
  if (!state.reviewPayload) {
    return;
  }

  elements.reviewNotice.className =
    "mb-6 rounded-2xl border border-[#9bb9d3] bg-white px-4 py-3 text-sm text-slate-600";
  elements.reviewNotice.innerHTML = "";

  const headline = document.createElement("p");
  headline.className = "font-semibold";
  headline.textContent = state.reviewPayload.isEstimate
    ? "This booking contains estimated pricing details."
    : "All selected pets are ready for final confirmation.";

  elements.reviewNotice.appendChild(headline);

  const lines = state.reviewPayload.notices.length
    ? state.reviewPayload.notices
    : ["Each pet is matched to its selected service and pricing summary below."];

  const list = document.createElement("div");
  list.className = "mt-2 space-y-1";

  lines.forEach((lineText) => {
    const line = document.createElement("p");
    line.textContent = lineText;
    list.appendChild(line);
  });

  elements.reviewNotice.appendChild(list);
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
  const addOnNames = item.selection.addOns
    .map((addOnId) => getAddOnById(addOnId)?.name)
    .filter(Boolean);
  const packageCard = selectedPackage
    ? renderPackageReview(selectedPackage, item)
    : "";
  const alaCarteCard =
    alaCarteLineItems.length > 0
      ? renderAlaCarteReview(item, alaCarteLineItems, Boolean(selectedPackage))
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
          addOnNames.length > 0
            ? `
              <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                  Add-Ons
                </p>
                <p class="mt-2 text-sm text-slate-600">${escapeHtml(
                  addOnNames.join(", "),
                )}</p>
              </div>
            `
            : ""
        }

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

  const pricingNote = getPackagePricingNote(item, packageLineItem);

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
      <p class="mt-3 text-sm text-slate-500">${escapeHtml(pricingNote)}</p>
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

function renderAlaCarteReview(item, alaCarteLineItems, hasPackage) {
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
      <p class="mt-3 text-sm text-slate-500">
        ${escapeHtml(
          hasPackage
            ? `These a la carte extras are added on top of the selected package and included in this pet total of ${formatAmountRange(
                item.pricing.total,
              )}.`
            : `A la carte totals are combined into ${formatAmountRange(
                item.pricing.total,
              )}.`,
        )}
      </p>
    </div>
  `;
}

function getPackagePricingNote(item, packageLineItem) {
  if (packageLineItem.pricing.selectedPriceOption) {
    if (packageLineItem.pricing.selectedPriceOption.pricingType === "plus") {
      return `Saved size "${formatPetSizeLabel(
        item.pet.size,
      )}" is highlighted, but the final rate will still be confirmed at the clinic.`;
    }

    return `Saved size "${formatPetSizeLabel(
      item.pet.size,
    )}" is highlighted because it matches the current pet size on file.`;
  }

  if (packageLineItem.pricing.missingSize) {
    return "This pet does not have a saved size yet, so the package total is shown as an estimate.";
  }

  if (packageLineItem.pricing.unmatchedSize) {
    return `Saved size "${formatPetSizeLabel(
      item.pet.size,
    )}" does not have a listed price in this menu, so the clinic will confirm the applicable rate.`;
  }

  return "The clinic will confirm the final applicable package rate during review.";
}

function renderTotalPricing() {
  const totalPricing = state.reviewPayload.totalPricing;

  elements.totalPriceHeading.textContent = state.reviewPayload.isEstimate
    ? "Estimated Total"
    : "Total Price";
  elements.totalPriceText.textContent = formatAmountRange(totalPricing);

  if (state.reviewPayload.isEstimate) {
    elements.totalPriceSubtext.textContent =
      "This total includes at least one estimate because of a missing pet size, a price range, or a clinic-confirmed + rate.";
    return;
  }

  elements.totalPriceSubtext.textContent =
    "All selected services have an exact total based on the saved pet size and current menu pricing.";
}

function saveReviewDraft() {
  const reviewDraft = {
    bookingDate: state.reviewPayload?.bookingDate || "",
    bookingTime: state.reviewPayload?.bookingTime || "",
    pets: state.reviewPayload?.items.map((item) => ({
      petId: item.pet.id,
      petName: item.pet.petName,
      petType: item.pet.petType,
      breed: item.pet.breed,
      size: item.pet.size,
      servicePackage: item.selection.servicePackage,
      alaCarteServices: [...item.selection.alaCarteServices],
      addOns: [...item.selection.addOns],
      specialInstructions: item.selection.specialInstructions.trim(),
      pricing: {
        minAmount: item.pricing.total.minAmount,
        maxAmount: item.pricing.total.maxAmount,
        isEstimate: item.pricing.total.isEstimate,
      },
    })),
    totalPricing: state.reviewPayload?.totalPricing || {
      minAmount: 0,
      maxAmount: 0,
      isEstimate: false,
    },
    notices: state.reviewPayload?.notices || [],
  };

  const serializedReviewDraft = JSON.stringify(reviewDraft);

  sessionStorage.setItem(REVIEW_STORAGE_KEY, serializedReviewDraft);
  sessionStorage.setItem(LEGACY_REVIEW_STORAGE_KEY, serializedReviewDraft);
}

function handleConfirmClick() {
  if (!state.reviewPayload) {
    return;
  }

  saveReviewDraft();
  window.location.href = "./booking-consent.html";
}
