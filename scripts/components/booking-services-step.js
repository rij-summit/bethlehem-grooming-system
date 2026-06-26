import {
  BOOKING_STEP_THREE_KEY,
  escapeHtml,
  formatPetSizeLabel,
  formatPetTypeLabel,
  getBookingDraft,
  readSessionJson,
} from "../services/booking-draft-service.js";
import { formatBookingSchedule } from "../services/booking-format-service.js";
import {
  buildStepThreeDraftPayload,
  calculatePetSelectionPricing,
  createEmptyPetServiceSelection,
  formatAmountRange,
  formatPriceOption,
  getAlaCarteServiceById,
  getAlaCarteServicesByPetType,
  getPackageById,
  getPackageAlaCarteRules,
  getPackagesByPetType,
  normalizeStepThreeDraft,
} from "../services/grooming-service.js";

/**
 * Booking Services Step Controller
 *
 * Purpose:
 * Renders Step 3 service choices for every pet in the active booking draft.
 *
 * Backend developer guide:
 * This step currently reads the active draft from browser storage and saves the
 * selected package/A la Carte choices in sessionStorage for later review pages.
 * In production, the backend should:
 * - return the current draft with stable pet IDs and schedule values
 * - accept one service selection object per pet using those same IDs
 * - recompute all pricing server-side instead of trusting browser totals
 */
const elements = {
  form: document.getElementById("bookingServicesForm"),
  scheduleSummaryText: document.getElementById("scheduleSummaryText"),
  petSummaryText: document.getElementById("petSummaryText"),
  serviceNotice: document.getElementById("serviceNotice"),
  bookingDateInput: document.getElementById("bookingDate"),
  bookingTimeInput: document.getElementById("bookingTime"),
  petIdInput: document.getElementById("petId"),
  petTypeInput: document.getElementById("petType"),
  petServiceSelections: document.getElementById("petServiceSelections"),
  nextButton:
    document.getElementById("reviewBookingBtn") ||
    document.querySelector('#bookingServicesForm button[type="submit"]'),
};

const state = {
  bookingDraft: null,
  petSelections: [],
};

document.addEventListener("DOMContentLoaded", () => {
  state.bookingDraft = getBookingDraft();

  hydrateServiceStepLayout();
  populateHiddenInputs(state.bookingDraft);
  populateSummary(state.bookingDraft);

  if (!Array.isArray(state.bookingDraft?.pets) || state.bookingDraft.pets.length === 0) {
    renderMissingPetState();
    bindEvents();
    return;
  }

  state.petSelections = normalizeStepThreeDraft(
    readSessionJson(BOOKING_STEP_THREE_KEY),
    state.bookingDraft,
  );

  renderPetServiceSelections();
  updateServiceNotice();
  bindEvents();
});

function bindEvents() {
  elements.petServiceSelections.addEventListener("change", handleSelectionChange);
  elements.petServiceSelections.addEventListener("input", handleSelectionInput);
  elements.form.addEventListener("submit", handleSubmit);
}

function hydrateServiceStepLayout() {
  if (elements.nextButton) {
    elements.nextButton.id = "reviewBookingBtn";
    elements.nextButton.textContent = "Next step";
  }

  if (elements.petServiceSelections) {
    return;
  }

  const buttonsRow = elements.nextButton?.parentElement || null;
  const hiddenInputs = new Set(
    Array.from(elements.form.querySelectorAll('input[type="hidden"]')),
  );

  Array.from(elements.form.children).forEach((child) => {
    if (child === buttonsRow || hiddenInputs.has(child)) {
      return;
    }

    child.remove();
  });

  const section = document.createElement("section");
  section.innerHTML = `
    <div class="mb-3">
      <h3 class="text-base font-semibold text-[#2f4b66]">
        Service Selection per Pet
      </h3>
    </div>

    <div id="petServiceSelections" class="space-y-6"></div>
  `;

  if (buttonsRow) {
    elements.form.insertBefore(section, buttonsRow);
  } else {
    elements.form.appendChild(section);
  }

  elements.petServiceSelections = section.querySelector("#petServiceSelections");
}

function populateHiddenInputs(data) {
  elements.bookingDateInput.value = data?.bookingDate || "";
  elements.bookingTimeInput.value = data?.bookingTime || "";
  elements.petIdInput.value = data?.petIds?.join(",") || data?.petId || "";
  elements.petTypeInput.value = data?.petTypes?.join(",") || data?.petType || "";
}

function populateSummary(data) {
  if (elements.scheduleSummaryText && (data?.bookingDate || data?.bookingTime)) {
    elements.scheduleSummaryText.textContent =
      data.bookingScheduleText ||
      formatBookingSchedule(data.bookingDate, data.bookingTime) ||
      "No schedule selected yet.";
  }

  const selectedPets = Array.isArray(data?.pets) ? data.pets : [];

  if (!elements.petSummaryText) {
    return;
  }

  if (selectedPets.length > 1) {
    elements.petSummaryText.textContent = `${selectedPets.length} pets selected: ${selectedPets
      .map((pet) => `${pet.petName || "Unnamed Pet"} (${formatPetTypeLabel(pet.petType)})`)
      .join(", ")}`;
    return;
  }

  if (selectedPets.length === 1) {
    const firstPet = selectedPets[0];

    elements.petSummaryText.textContent = `${firstPet.petName || "Unnamed Pet"} | ${formatPetTypeLabel(
      firstPet.petType,
    )} | ${firstPet.breed || "Breed not specified"}`;
  }
}

function renderMissingPetState() {
  elements.petServiceSelections.innerHTML = `
    <div class="rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-700">
      No pet was found in this pre-register draft. Please go back to Step 2 and select at least one pet before continuing.
    </div>
  `;

  elements.serviceNotice.className =
    "mb-6 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700";
  elements.serviceNotice.textContent =
    "Please complete Step 2 first so each pet can receive its own grooming selection.";

  syncNextButtonState(false);
}

function renderPetServiceSelections() {
  const pets = Array.isArray(state.bookingDraft?.pets) ? state.bookingDraft.pets : [];

  elements.petServiceSelections.innerHTML = pets
    .map((pet, index) => renderPetSelectionCard(pet, index))
    .join("");
}

function renderPetSelectionCard(pet, index) {
  const selection = getSelectionByPetId(pet.id);
  const petPricing = calculatePetSelectionPricing(selection, pet);
  const allowsAlaCarteOnly = petCanUseAlaCarteOnly(pet);
  const selectedPackage = selection.servicePackage
    ? getPackageById(selection.servicePackage)
    : null;
  const packageCards = getPackagesByPetType(pet.petType)
    .map((service) => renderPackageCard(pet, selection, service))
    .join("");
  const alaCarteServices = getAlaCarteServicesByPetType(pet.petType);

  return `
    <article
      data-pet-card="${escapeHtml(pet.id)}"
      class="rounded-[28px] border border-slate-200 bg-white p-5 shadow-sm md:p-6"
    >
      <div class="mb-6">
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
          <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Pet ${
              index + 1
            }</p>
            <h3 class="mt-1 text-2xl font-bold text-[#2f4b66]">${escapeHtml(
              pet.petName || "Unnamed Pet",
            )}</h3>
          </div>
          <span class="rounded-full bg-[#edf5fc] px-3 py-1 text-xs font-semibold text-[#315b7e]">
            ${escapeHtml(formatPetSizeLabel(pet.size))}
          </span>
        </div>

        <div class="mt-2 flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
          <p class="text-sm font-normal text-slate-500">${escapeHtml(
            formatPetTypeLabel(pet.petType),
          )} | ${escapeHtml(pet.breed || "Breed not specified")}</p>
          <span class="text-sm text-slate-500 md:text-right">${escapeHtml(
            getPetSelectionSummaryText(pet, selection, petPricing),
          )}</span>
        </div>
      </div>

      <section>
        <div class="mb-3">
          <h4 class="text-base font-semibold text-[#2f4b66]">
            ${
              allowsAlaCarteOnly
                ? "Package Options"
                : 'Service Package <span class="text-red-500">*</span>'
            }
          </h4>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
          ${packageCards}
        </div>
      </section>

      ${
        alaCarteServices.length > 0
          ? `
            <section class="mt-8">
              <div class="mb-3">
                <h4 class="text-base font-semibold text-[#2f4b66]">A la Carte Menu</h4>
              </div>

              <div class="space-y-2">
                ${alaCarteServices
                  .map((service) =>
                    renderAlaCarteCard(pet, selection, service, selectedPackage),
                  )
                  .join("")}
              </div>
            </section>
          `
          : ""
      }

      <section class="mt-8">
        <div class="mb-3">
          <h4 class="text-base font-semibold text-[#2f4b66]">
            Grooming Preferences &amp; Special Instructions
          </h4>
        </div>

        <textarea
          data-role="special-instructions"
          data-pet-id="${escapeHtml(pet.id)}"
          rows="4"
          maxlength="500"
          placeholder="Add pet-specific notes like haircut preference, sensitivity, or handling instructions."
          class="w-full rounded-2xl border border-slate-300 px-4 py-3 text-sm outline-none transition focus:border-[#315b7e] focus:ring-2 focus:ring-[#315b7e]/20"
        >${escapeHtml(selection.specialInstructions || "")}</textarea>
      </section>
    </article>
  `;
}

function renderPackageCard(pet, selection, service) {
  const isChecked = selection.servicePackage === service.id;
  const selectedSizeLabel = formatPetSizeLabel(pet.size);

  return `
    <div class="flex h-full flex-col gap-3">
      <label
        class="service-card ${isChecked ? "service-card--selected" : ""} flex h-full cursor-pointer items-start gap-3 rounded-2xl border border-[#91aeca] bg-white p-4 transition hover:border-[#315b7e] hover:shadow-sm"
      >
        <input
          type="radio"
          name="service_package_${escapeHtml(pet.id)}"
          value="${escapeHtml(service.id)}"
          data-role="service-package"
          data-pet-id="${escapeHtml(pet.id)}"
          class="mt-1 h-4 w-4 shrink-0 accent-[#315b7e]"
          ${isChecked ? "checked" : ""}
        />
        <div class="service-card__content">
          <div class="flex items-start justify-between gap-3">
            <h4 class="font-semibold text-[#2f4b66]">${escapeHtml(service.name)}</h4>
            <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-medium text-slate-600">
              ${escapeHtml(formatPetTypeLabel(pet.petType))}
            </span>
          </div>
          <ul class="mt-2 space-y-1 text-sm text-slate-600">
            ${service.descriptionItems
              .map((item) => `<li>&bull; ${escapeHtml(item)}</li>`)
              .join("")}
          </ul>
          <div class="service-card__pricing">
            <span class="service-card__pricing-label">Size &amp; Price</span>
            <div class="service-card__price-grid">
              ${service.priceOptions
                .map((priceOption) => renderPricePill(priceOption, selectedSizeLabel))
                .join("")}
            </div>
          </div>
        </div>
      </label>
    </div>
  `;
}

function renderPricePill(priceOption, selectedSizeLabel) {
  const shouldHighlight = priceOption.label === selectedSizeLabel;

  return `
    <div class="service-card__price-pill ${shouldHighlight ? "service-card__price-pill--active" : ""}">
      <span class="service-card__price-size">${escapeHtml(priceOption.label)}</span>
      <span class="service-card__price-value">${escapeHtml(
        formatPriceOption(priceOption),
      )}</span>
    </div>
  `;
}

function renderAlaCarteCard(pet, selection, service, selectedPackage) {
  const isChecked = selection.alaCarteServices.includes(service.id);
  const packageAlaCarteRules = getPackageAlaCarteRules(selection.servicePackage);
  const isDisabled =
    Boolean(selection.servicePackage) &&
    packageAlaCarteRules.canCombineWithAlaCarte &&
    packageAlaCarteRules.includedAlaCarteServiceIds.includes(service.id);
  const labelClasses = [
    isChecked ? "service-option--selected" : "",
    "flex items-center gap-3 rounded-xl border px-3 py-2 text-sm transition",
    isDisabled
      ? "cursor-not-allowed border-slate-200 bg-slate-50 text-slate-400"
      : "cursor-pointer border-slate-200 text-slate-600 hover:border-[#315b7e] hover:bg-slate-50",
  ]
    .filter(Boolean)
    .join(" ");
  const titleClasses = isDisabled ? "font-semibold text-slate-400" : "font-semibold text-[#2f4b66]";
  const priceClasses = isDisabled ? "mt-1 block text-sm text-slate-400" : "mt-1 block text-sm text-slate-500";

  return `
    <label
      class="${labelClasses}"
    >
      <input
        type="checkbox"
        value="${escapeHtml(service.id)}"
        data-role="ala-carte"
        data-pet-id="${escapeHtml(pet.id)}"
        class="h-4 w-4 shrink-0 accent-[#315b7e]"
        ${isDisabled ? "disabled" : ""}
        ${isChecked ? "checked" : ""}
      />
      <div class="flex min-w-0 flex-1 items-center justify-between gap-3">
        <div class="min-w-0">
          <span class="${titleClasses}">${escapeHtml(service.name)}</span>
          <span class="${priceClasses}">${escapeHtml(
            formatPriceOption(service.priceOptions[0]),
          )}</span>
        </div>
        ${
          isDisabled
            ? `<span class="ml-auto shrink-0 rounded-full bg-slate-100 px-2 py-1 text-right text-xs font-medium text-slate-400">Included in Package</span>`
            : ""
        }
      </div>
    </label>
  `;
}

function getSelectionByPetId(petId) {
  return (
    state.petSelections.find((selection) => selection.petId === petId) ||
    createEmptyPetServiceSelection({ id: petId })
  );
}

function petCanUseAlaCarteOnly(pet) {
  return getAlaCarteServicesByPetType(pet?.petType).length > 0;
}

function petRequiresPackage(pet) {
  return !petCanUseAlaCarteOnly(pet);
}

function hasCompletedRequiredServiceSelection(pet, selection) {
  if (!pet) {
    return false;
  }

  if (petRequiresPackage(pet)) {
    return Boolean(selection?.servicePackage);
  }

  return calculatePetSelectionPricing(selection, pet).hasSelection;
}

function getPetSelectionSummaryText(pet, selection, petPricing) {
  if (!petPricing.hasSelection) {
    return "No service selected yet.";
  }

  const alaCarteLabels = selection.alaCarteServices
    .map((serviceId) => getAlaCarteServiceById(serviceId)?.name)
    .filter(Boolean);

  if (selection.servicePackage) {
    const selectedPackage = getPackageById(selection.servicePackage);
    const summaryLabel =
      alaCarteLabels.length > 0
        ? `${selectedPackage?.name || "Selected package"} + A la Carte: ${alaCarteLabels.join(
            ", ",
          )}`
        : selectedPackage?.name || "Selected package";

    return `${summaryLabel} | ${formatAmountRange(petPricing.total)}`;
  }

  if (alaCarteLabels.length > 0) {
    return `${alaCarteLabels.join(", ")} | ${formatAmountRange(petPricing.total)}`;
  }

  return "No service selected yet.";
}

function updateServiceNotice(validationMessage = "") {
  const pets = Array.isArray(state.bookingDraft?.pets) ? state.bookingDraft.pets : [];
  const readyPetCount = pets.filter((pet) =>
    hasCompletedRequiredServiceSelection(pet, getSelectionByPetId(pet.id)),
  ).length;

  const allPetsReady = readyPetCount === pets.length && pets.length > 0;
  const hasValidationError = Boolean(validationMessage);

  syncNextButtonState(allPetsReady);

  elements.serviceNotice.className = hasValidationError
    ? "mb-6 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
    : "mb-6 rounded-2xl border border-[#9bb9d3] bg-white px-4 py-3 text-sm text-slate-600";

  elements.serviceNotice.innerHTML = "";

  if (hasValidationError) {
    const title = document.createElement("p");
    title.className = "font-semibold";
    title.textContent = validationMessage;
    elements.serviceNotice.appendChild(title);
    return;
  }

  const title = document.createElement("p");
  title.className = "text-xs font-semibold uppercase tracking-wide text-slate-400";
  title.textContent = "Service Rules";
  elements.serviceNotice.appendChild(title);

  const message = document.createElement("p");
  message.className = "mt-2";
  message.textContent =
    "Choose one package, A la Carte services, or both. Items already included in the selected package will be unavailable.";
  elements.serviceNotice.appendChild(message);
}

function syncNextButtonState(isEnabled) {
  if (!elements.nextButton) {
    return;
  }

  elements.nextButton.disabled = !isEnabled;
  elements.nextButton.setAttribute("aria-disabled", String(!isEnabled));
  elements.nextButton.classList.toggle("opacity-50", !isEnabled);
  elements.nextButton.classList.toggle("cursor-not-allowed", !isEnabled);
}

function handleSelectionChange(event) {
  const target = event.target;
  const petId = target.dataset.petId;

  if (!petId) {
    return;
  }

  const selection = state.petSelections.find(
    (petSelection) => petSelection.petId === petId,
  );

  if (!selection) {
    return;
  }

  if (target.dataset.role === "service-package") {
    selection.servicePackage = target.value;
    const packageAlaCarteRules = getPackageAlaCarteRules(selection.servicePackage);
    const blockedServiceIds = new Set(packageAlaCarteRules.includedAlaCarteServiceIds);

    selection.alaCarteServices = packageAlaCarteRules.canCombineWithAlaCarte
      ? selection.alaCarteServices.filter((serviceId) => !blockedServiceIds.has(serviceId))
      : [];
  }

  if (target.dataset.role === "ala-carte") {
    const petCard = elements.petServiceSelections.querySelector(
      `[data-pet-card="${CSS.escape(petId)}"]`,
    );
    const packageAlaCarteRules = getPackageAlaCarteRules(selection.servicePackage);
    const blockedServiceIds = new Set(packageAlaCarteRules.includedAlaCarteServiceIds);

    selection.alaCarteServices = Array.from(
      petCard?.querySelectorAll('[data-role="ala-carte"]:checked') || [],
    )
      .map((item) => item.value)
      .filter((serviceId) =>
        packageAlaCarteRules.canCombineWithAlaCarte
          ? !blockedServiceIds.has(serviceId)
          : true,
      );

    if (selection.alaCarteServices.length > 0 && !packageAlaCarteRules.canCombineWithAlaCarte) {
      selection.servicePackage = "";
    }
  }

  renderPetServiceSelections();
  updateServiceNotice();
  saveCurrentStepDraft();
}

function handleSelectionInput(event) {
  const target = event.target;
  const petId = target.dataset.petId;

  if (!petId || target.dataset.role !== "special-instructions") {
    return;
  }

  const selection = state.petSelections.find(
    (petSelection) => petSelection.petId === petId,
  );

  if (!selection) {
    return;
  }

  selection.specialInstructions = target.value;
  saveCurrentStepDraft();
}

function handleSubmit(event) {
  event.preventDefault();

  const incompletePets = state.bookingDraft.pets.filter((pet) => {
    const selection = getSelectionByPetId(pet.id);
    return !hasCompletedRequiredServiceSelection(pet, selection);
  });

  if (incompletePets.length > 0) {
    const packageRequiredPets = incompletePets.filter((pet) => petRequiresPackage(pet));
    const flexibleServicePets = incompletePets.filter(
      (pet) => !petRequiresPackage(pet),
    );
    const validationMessages = [];

    if (packageRequiredPets.length > 0) {
      validationMessages.push(
        `Please select a grooming package for ${packageRequiredPets
          .map((pet) => pet.petName || "each remaining dog")
          .join(", ")} before continuing.`,
      );
    }

    if (flexibleServicePets.length > 0) {
      validationMessages.push(
        `Please select one package or at least one a la carte service for ${flexibleServicePets
          .map((pet) => pet.petName || "each remaining pet")
          .join(", ")} before continuing.`,
      );
    }

    updateServiceNotice(validationMessages.join(" "));

    const firstIncompletePet = incompletePets[0];
    const firstCard = elements.petServiceSelections.querySelector(
      `[data-pet-card="${CSS.escape(firstIncompletePet.id)}"]`,
    );

    firstCard?.scrollIntoView({ behavior: "smooth", block: "start" });
    return;
  }

  saveCurrentStepDraft();
  window.location.href = "./booking-review.html";
}

function saveCurrentStepDraft() {
  sessionStorage.setItem(
    BOOKING_STEP_THREE_KEY,
    JSON.stringify(buildStepThreeDraftPayload(state.petSelections)),
  );
}
