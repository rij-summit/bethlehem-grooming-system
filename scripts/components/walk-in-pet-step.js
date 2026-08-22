import { renderWalkInServicesStep } from "./walk-in-services-step.js";
import { renderWalkInClinicComplaintStep } from "./walk-in-clinic-complaint-step.js";
import { createBreedCombobox } from "./breed-combobox.js";
import { createBreedCoatCombobox } from "./breed-coat-combobox.js";
import { createFixedOptionCombobox } from "./fixed-option-combobox.js";
import {
  getEnteredWeight,
  getSizeForWeight,
  getSizeOptions,
  getWeightFieldValidationMessage,
  getWeightValidationMessage,
  initializeWeightField,
  resetWeightFieldForEntry,
  showWeightRangeInField,
  showWeightValidationInField,
} from "./pet-weight-size.js";

const MAX_PETS_PER_BOOKING = 10;
const WALK_IN_PET_STORAGE_KEY = "walkInPetStep";

const elements = {
  showAddPetBtn: document.getElementById("showAddPetBtn"),
  petStepError: document.getElementById("petStepError"),
  addPetSection: document.getElementById("addPetSection"),
  addPetForm: document.getElementById("addPetForm"),
  petType: document.getElementById("petType"),
  petName: document.getElementById("petName"),
  breed: document.getElementById("breed"),
  weight: document.getElementById("weight"),
  furType: document.getElementById("furType"),
  size: document.getElementById("size"),
  selectedPetCount: document.getElementById("selectedPetCount"),
  selectedPetsEmptyState: document.getElementById("selectedPetsEmptyState"),
  selectedPetCards: document.getElementById("selectedPetCards"),
  existingPetsSection: document.getElementById("existingPetsSection"),
  existingPetsOwnerText: document.getElementById("existingPetsOwnerText"),
  existingPetsCount: document.getElementById("existingPetsCount"),
  existingPetCards: document.getElementById("existingPetCards"),
  backBtn: document.getElementById("backBtn"),
  nextBtn: document.getElementById("nextBtn"),
  fieldErrors: {
    petType: document.getElementById("petTypeError"),
    petName: document.getElementById("petNameError"),
    size: document.getElementById("sizeError"),
  },
};

const petTypeCombobox = createFixedOptionCombobox({
  root: document.getElementById("petTypeCombobox"),
  input: elements.petType,
  listbox: document.getElementById("petTypeOptions"),
  toggleButton: document.getElementById("petTypeDropdownButton"),
  placeholder: "Select pet type",
  options: [
    { value: "Dog", label: "Dog" },
    { value: "Cat", label: "Cat" },
  ],
});

const breedCombobox = createBreedCombobox({
  root: document.getElementById("breedCombobox"),
  input: elements.breed,
  listbox: document.getElementById("breedOptions"),
  toggleButton: document.getElementById("breedDropdownButton"),
  errorElement: document.getElementById("breedError"),
  getPetType: () => elements.petType.value,
});

const breedCoatCombobox = createBreedCoatCombobox({
  root: document.getElementById("furTypeCombobox"),
  breedInput: elements.breed,
  petTypeInput: elements.petType,
  input: elements.furType,
  listbox: document.getElementById("furTypeOptions"),
  toggleButton: document.getElementById("furTypeDropdownButton"),
  errorElement: document.getElementById("furTypeError"),
});

const sizeCombobox = createFixedOptionCombobox({
  root: document.getElementById("sizeCombobox"),
  input: elements.size,
  listbox: document.getElementById("sizeOptions"),
  toggleButton: document.getElementById("sizeDropdownButton"),
  placeholder: "Select size",
  options: [],
  displaySelectedLabel: true,
});

const state = {
  pets: [],
  existingPets: [],
  owner: null,
};
let hasSubmittedOnce = false;

/*
  BACKEND TEAMMATE + CLAUDE CODE:
  Walk-in pet records are held in memory while the staff/admin user moves
  through the frontend flow. Persist these pets under the walk-in booking draft
  once a database-backed draft endpoint exists.
*/

function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function normalizeText(value) {
  return String(value || "").trim().replace(/\s+/g, " ");
}

function createPetId() {
  /*
    BACKEND TEAMMATE + CLAUDE CODE:
    This temporary browser ID only links pets between frontend steps. Replace
    it with the database pet ID or draft pet ID returned by the backend.
  */
  if (window.crypto?.randomUUID) {
    return window.crypto.randomUUID();
  }

  return `walk-in-pet-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function showStepError(message) {
  elements.petStepError.textContent = message;
  elements.petStepError.classList.remove("hidden");
}

function clearStepError() {
  elements.petStepError.textContent = "";
  elements.petStepError.classList.add("hidden");
}

function getFormValues(form) {
  return {
    petType: form.petType.value,
    petName: normalizeText(form.petName.value),
    breed: normalizeText(form.breed.value),
    weight: getEnteredWeight(elements.weight),
    furType: form.furType.value,
    size: sizeCombobox.getValue(),
    medicalNotes: normalizeText(form.medicalNotes.value),
  };
}

function readPetStepDraft() {
  try {
    return JSON.parse(sessionStorage.getItem(WALK_IN_PET_STORAGE_KEY) || "null");
  } catch {
    return null;
  }
}

function savePetStepDraft() {
  sessionStorage.setItem(
    WALK_IN_PET_STORAGE_KEY,
    JSON.stringify({
      pets: state.pets,
      newPetForm: getFormValues(elements.addPetForm),
    }),
  );
}

function restorePetStepDraft() {
  const draft = readPetStepDraft();
  const values = draft?.newPetForm || {};

  state.pets = Array.isArray(draft?.pets) ? draft.pets : [];
  petTypeCombobox.setValue(values.petType || "");
  elements.petName.value = values.petName || "";
  elements.breed.value = values.breed || "";

  if (values.weight) {
    resetWeightFieldForEntry(elements.weight);
    elements.weight.value = values.weight;
  }

  breedCoatCombobox.update();
  if (values.furType && !elements.furType.disabled) {
    elements.furType.value = values.furType;
  }

  syncWeightAndSize({ clearManualSize: true });
  if (values.size) {
    sizeCombobox.setValue(values.size);
  }

  elements.medicalNotes.value = values.medicalNotes || "";
}

function validatePetForm(values) {
  const errors = {};

  if (!values.petType.trim()) {
    errors.petType = "Pet type is required.";
  }

  if (!values.petName.trim()) {
    errors.petName = "Pet name is required.";
  } else {
    const normalizedName = values.petName.trim().toLowerCase();
    const duplicate = [...state.existingPets, ...state.pets].some((pet) =>
      String(pet.petName || "").trim().toLowerCase() === normalizedName,
    );
    if (duplicate) errors.petName = "This owner already has a pet with this name.";
  }

  const breedValidationMessage = breedCombobox.getValidationMessage();
  if (breedValidationMessage) {
    errors.breed = breedValidationMessage;
  }

  const furTypeValidationMessage = breedCoatCombobox.getValidationMessage();
  if (furTypeValidationMessage) {
    errors.furType = furTypeValidationMessage;
  }

  const weightValidationMessage = getWeightFieldValidationMessage(elements.weight)
    || getWeightValidationMessage(values.petType, values.weight);
  if (weightValidationMessage) {
    errors.weight = weightValidationMessage;
  }

  const allowedSizes = getSizeOptions(values.petType).map(({ value }) => value);
  if (!values.size.trim()) {
    errors.size = "Size is required.";
  } else if (allowedSizes.length > 0 && !allowedSizes.includes(values.size)) {
    errors.size = `${values.petType || "Selected pet type"} size must be one of: ${allowedSizes.join(", ")}.`;
  }

  if (state.pets.length >= MAX_PETS_PER_BOOKING) {
    errors.limit = `Only ${MAX_PETS_PER_BOOKING} pets are allowed per walk-in schedule.`;
  }

  return errors;
}

function createPetObject(formData) {
  return {
    id: createPetId(),
    petType: formData.petType,
    petName: formData.petName,
    breed: formData.breed,
    weight: formData.weight,
    furType: formData.furType,
    size: formData.size,
    medicalNotes: formData.medicalNotes,
    petId: null,
    isNew: true,
  };
}

function normalizeExistingPet(pet) {
  return {
    id: `saved-pet-${pet.id}`,
    petId: pet.id,
    petType: pet.species || "",
    petName: pet.petName || "",
    breed: pet.breed || "",
    weight: pet.weight ?? "",
    furType: pet.furType || "",
    size: pet.size || "",
    medicalNotes: pet.medicalConditions || "",
    isNew: false,
  };
}

function renderExistingPets() {
  if (!elements.existingPetsSection || !state.owner || state.owner.ownerRecordType === "new") return;

  elements.existingPetsSection.classList.remove("hidden");
  elements.existingPetsOwnerText.textContent = `Choose a saved pet for ${state.owner.fullName || "this customer"}, or add a new pet below.`;
  elements.existingPetsCount.textContent = `${state.existingPets.length} saved pet${state.existingPets.length === 1 ? "" : "s"}`;

  if (state.existingPets.length === 0) {
    elements.existingPetCards.innerHTML = `
      <div class="md:col-span-2 rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-5 py-6 text-center text-sm text-slate-500">
        This customer has no saved pets yet. Add a new pet below.
      </div>
    `;
    return;
  }

  elements.existingPetCards.innerHTML = state.existingPets.map((pet, index) => {
    const selected = state.pets.some((selectedPet) => selectedPet.petId === pet.petId);
    return `
      <article class="rounded-2xl border border-slate-200 bg-[#f8fbfd] p-4">
        <div class="flex items-start justify-between gap-3">
          <div>
            <h4 class="font-semibold text-[#2f4b66]">${escapeHtml(pet.petName)}</h4>
            <p class="mt-1 text-sm text-slate-500">${escapeHtml(pet.petType || "Pet")}${pet.breed ? ` &middot; ${escapeHtml(pet.breed)}` : ""}</p>
            <p class="mt-1 text-xs text-slate-400">${escapeHtml(pet.size || "Size not provided")}${pet.weight ? ` &middot; ${escapeHtml(pet.weight)} kg` : ""}</p>
          </div>
          <button
            type="button"
            data-select-existing-pet="${index}"
            ${selected ? "disabled" : ""}
            class="rounded-xl px-3 py-2 text-xs font-semibold transition ${selected ? "cursor-not-allowed bg-green-100 text-green-700" : "bg-[#315b7e] text-white hover:bg-[#274864]"}"
          >${selected ? "Selected" : "Select"}</button>
        </div>
      </article>
    `;
  }).join("");
}

function loadExistingOwnerPets() {
  try {
    state.owner = JSON.parse(sessionStorage.getItem("walkInOwnerStep") || "null");
  } catch {
    state.owner = null;
  }

  state.existingPets = Array.isArray(state.owner?.existingPets)
    ? state.owner.existingPets.map(normalizeExistingPet)
    : [];
  renderExistingPets();
}

function buildPetSummaryHtml(pet) {
  return `
    <div class="grid gap-2 text-sm text-slate-600">
      <p><span class="font-semibold text-slate-700">Type:</span> ${escapeHtml(
        pet.petType || "Not specified",
      )}</p>
      <p><span class="font-semibold text-slate-700">Breed:</span> ${escapeHtml(
        pet.breed || "Not specified",
      )}</p>
      <p><span class="font-semibold text-slate-700">Weight:</span> ${escapeHtml(
        pet.weight ? `${pet.weight} kg` : "Not specified",
      )}</p>
      <p><span class="font-semibold text-slate-700">Fur Type:</span> ${escapeHtml(
        pet.furType || "Not specified",
      )}</p>
      <p><span class="font-semibold text-slate-700">Size:</span> ${escapeHtml(
        pet.size || "Not specified",
      )}</p>
      <p><span class="font-semibold text-slate-700">Medical Notes:</span> ${escapeHtml(
        pet.medicalNotes || "None",
      )}</p>
    </div>
  `;
}

function renderSelectedPets() {
  elements.selectedPetCards.innerHTML = "";
  elements.selectedPetCount.textContent = `${state.pets.length} / ${MAX_PETS_PER_BOOKING} selected`;

  const hasPets = state.pets.length > 0;
  elements.selectedPetsEmptyState.classList.toggle("hidden", hasPets);
  elements.nextBtn.disabled = !hasPets;
  elements.nextBtn.classList.toggle("opacity-50", !hasPets);
  elements.nextBtn.classList.toggle("cursor-not-allowed", !hasPets);

  state.pets.forEach((pet) => {
    const card = document.createElement("article");
    card.className =
      "rounded-2xl border border-[#c6dbef] bg-[#f8fbfe] p-4 shadow-sm";

    card.innerHTML = `
      <div class="mb-3 flex items-start justify-between gap-3">
        <div>
          <h4 class="text-base font-bold text-[#2f4b66]">${escapeHtml(
            pet.petName,
          )}</h4>
          <p class="mt-1 text-xs text-slate-400">Included in this walk-in schedule</p>
        </div>

        <button
          type="button"
          data-remove-pet-id="${escapeHtml(pet.id)}"
          class="remove-selected-pet-btn rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-600 transition hover:bg-red-100"
        >
          Remove
        </button>
      </div>

      ${buildPetSummaryHtml(pet)}
    `;

    elements.selectedPetCards.appendChild(card);
  });

  bindRemoveButtons();
  renderExistingPets();
  savePetStepDraft();
}

function bindRemoveButtons() {
  const buttons = document.querySelectorAll(".remove-selected-pet-btn");

  buttons.forEach((button) => {
    button.addEventListener("click", () => {
      const petId = button.dataset.removePetId;

      state.pets = state.pets.filter((pet) => pet.id !== petId);
      renderSelectedPets();
      clearStepError();
    });
  });
}

function setFieldError(fieldName, message) {
  const errorElement = elements.fieldErrors[fieldName];
  const input = elements[fieldName];

  if (!errorElement) {
    return;
  }

  if (message) {
    input?.setAttribute("aria-invalid", "true");
    input?.classList.add("border-red-400");
    errorElement.textContent = message;
    errorElement.className = "mt-2 text-sm text-red-600";
    return;
  }

  input?.setAttribute("aria-invalid", "false");
  input?.classList.remove("border-red-400");
  errorElement.textContent = "";
  errorElement.className = "mt-2 hidden text-sm text-red-600";
}

function renderValidationErrors(values, { showAll = false } = {}) {
  const errors = validatePetForm(values);
  const shouldShow = showAll || hasSubmittedOnce;

  Object.keys(elements.fieldErrors).forEach((fieldName) => {
    const message = shouldShow ? errors[fieldName] || "" : "";
    setFieldError(fieldName, message);
  });

  if (shouldShow) {
    breedCombobox.showValidation(errors.breed || "");
    breedCoatCombobox.showValidation(errors.furType || "");
  }

  if (showAll || (hasSubmittedOnce && errors.limit)) {
    showStepError(errors.limit || "Please put valid inputs.");
    return;
  }

  if (showAll) {
    showStepError("Please put valid inputs.");
    return;
  }

  clearStepError();
}

function resetAddPetForm() {
  elements.addPetForm.reset();
  breedCoatCombobox.reset();
  sizeCombobox.reset();
  initializeWeightField(elements.weight);
  syncWeightAndSize({ clearManualSize: true });
  savePetStepDraft();
}

function handleAddPetSubmit(event) {
  event.preventDefault();
  hasSubmittedOnce = true;

  const formValues = getFormValues(elements.addPetForm);
  const errors = validatePetForm(formValues);

  if (Object.keys(errors).length > 0) {
    if (errors.weight) {
      showWeightValidationInField(elements.weight, errors.weight);
    }
    renderValidationErrors(formValues, { showAll: true });
    return;
  }

  const newPet = createPetObject(formValues);
  state.pets = [...state.pets, newPet];

  resetAddPetForm();
  renderSelectedPets();
  clearStepError();
}

function handleBack() {
  window.location.href = getOwnerAppointmentType() === "clinic"
    ? "./walk-in-booking.html?flow=clinic&source=clinic"
    : "./walk-in-booking.html";
}

function getOwnerAppointmentType() {
  try {
    const owner = JSON.parse(sessionStorage.getItem("walkInOwnerStep") || "{}");
    return owner.appointmentType || "grooming";
  } catch {
    return "grooming";
  }
}

function handleNext() {
  if (state.pets.length === 0) {
    showStepError("Please add at least one pet before continuing.");
    return;
  }

  const appointmentType = getOwnerAppointmentType();

  if (appointmentType === "clinic") {
    if (state.pets.length > 1) {
      showStepError("Clinic walk-in supports one pet per visit. Please remove extra pets.");
      return;
    }
    clearStepError();
    savePetStepDraft();
    window.history.pushState(null, "", "./walk-in-clinic-complaint.html");
    renderWalkInClinicComplaintStep({ pet: state.pets[0] });
    return;
  }

  clearStepError();
  savePetStepDraft();
  window.history.pushState(null, "", "./walk-in-grooming-services.html");
  renderWalkInServicesStep({ pets: state.pets });
}

function syncWeightAndSize({ clearManualSize = false } = {}) {
  const petType = elements.petType.value;
  const rawWeight = getEnteredWeight(elements.weight);
  const hasWeight = rawWeight.trim() !== "";
  const options = getSizeOptions(petType);

  sizeCombobox.setOptions(options, {
    preserveValue: !clearManualSize,
  });

  const computedSize = getSizeForWeight(petType, rawWeight);

  if (hasWeight && computedSize) {
    sizeCombobox.setValue(computedSize);
  }

  sizeCombobox.setDisabled(options.length === 0);
}

function validateWeightOnCommit() {
  const rawWeight = getEnteredWeight(elements.weight);

  if (!rawWeight) {
    if (elements.weight.dataset.weightFieldMode !== "error") {
      showWeightRangeInField(
        elements.weight,
        elements.petType.value,
        sizeCombobox.getValue(),
      );
    }
    return true;
  }

  const message = getWeightValidationMessage(elements.petType.value, rawWeight);

  if (message) {
    showWeightValidationInField(elements.weight, message);
    return false;
  }

  return true;
}

function handleManualSizeChange() {
  if (!getEnteredWeight(elements.weight)
    && elements.weight.dataset.weightFieldMode !== "error") {
    showWeightRangeInField(
      elements.weight,
      elements.petType.value,
      sizeCombobox.getValue(),
    );
  }
}

function guardAdminAccess() {
  /*
    BACKEND TEAMMATE + CLAUDE CODE:
    This only protects the page in the browser. Backend walk-in pet APIs should
    still require an authorized admin/staff token.
  */
  const token = API.getAdminToken?.();
  const role = API.getUserRole?.();

  if (token && (role === "admin" || role === "staff")) {
    return true;
  }

  window.location.href = "../client/sign-in.html";
  return false;
}

function bindEvents() {
  elements.showAddPetBtn.addEventListener("click", () => {
    elements.addPetSection.scrollIntoView({ behavior: "smooth", block: "start" });
  });
  elements.addPetForm.addEventListener("submit", handleAddPetSubmit);
  elements.addPetForm.addEventListener("input", savePetStepDraft);
  elements.addPetForm.addEventListener("change", savePetStepDraft);
  elements.backBtn.addEventListener("click", handleBack);
  elements.nextBtn.addEventListener("click", handleNext);
  elements.existingPetCards?.addEventListener("click", (event) => {
    const button = event.target.closest("[data-select-existing-pet]");
    if (!button || button.disabled || state.pets.length >= MAX_PETS_PER_BOOKING) return;
    const pet = state.existingPets[Number(button.dataset.selectExistingPet)];
    if (!pet || state.pets.some((selectedPet) => selectedPet.petId === pet.petId)) return;
    state.pets = [...state.pets, { ...pet }];
    renderSelectedPets();
    clearStepError();
  });

  const handlePetInputs = (event) => {
    if (event.target === elements.petType) {
      if (elements.weight.dataset.weightFieldMode === "range") {
        resetWeightFieldForEntry(elements.weight);
      }
      syncWeightAndSize({ clearManualSize: true });
      validateWeightOnCommit();
    } else if (event.target === elements.weight) {
      syncWeightAndSize();
      if (event.type === "change") {
        validateWeightOnCommit();
      }
    } else if (event.target === elements.size) {
      handleManualSizeChange();
    }

    const formValues = getFormValues(elements.addPetForm);
    renderValidationErrors(formValues, { showAll: false });
  };

  elements.petType.addEventListener("input", handlePetInputs);
  elements.petType.addEventListener("change", handlePetInputs);
  elements.petName.addEventListener("input", handlePetInputs);
  elements.breed.addEventListener("input", handlePetInputs);
  elements.breed.addEventListener("change", handlePetInputs);
  elements.weight.addEventListener("input", handlePetInputs);
  elements.weight.addEventListener("change", handlePetInputs);
  elements.weight.addEventListener("focus", () => {
    resetWeightFieldForEntry(elements.weight);
  });
  elements.furType.addEventListener("input", handlePetInputs);
  elements.size.addEventListener("change", handlePetInputs);
}

function initWalkInPetStep() {
  if (!guardAdminAccess()) {
    return;
  }

  bindEvents();
  loadExistingOwnerPets();
  initializeWeightField(elements.weight);
  syncWeightAndSize();
  restorePetStepDraft();
  renderSelectedPets();

  if (window.lucide) {
    window.lucide.createIcons();
  }
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", initWalkInPetStep, { once: true });
} else {
  initWalkInPetStep();
}
