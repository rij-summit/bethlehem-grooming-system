import { renderWalkInServicesStep } from "./walk-in-services-step.js";
import { renderWalkInClinicComplaintStep } from "./walk-in-clinic-complaint-step.js";
import { createBreedCombobox } from "./breed-combobox.js";
import { createBreedCoatCombobox } from "./breed-coat-combobox.js";
import { createFixedOptionCombobox } from "./fixed-option-combobox.js";

const MAX_PETS_PER_BOOKING = 10;

const elements = {
  showAddPetBtn: document.getElementById("showAddPetBtn"),
  petStepMessage: document.getElementById("petStepMessage"),
  addPetSection: document.getElementById("addPetSection"),
  addPetForm: document.getElementById("addPetForm"),
  petType: document.getElementById("petType"),
  petName: document.getElementById("petName"),
  breed: document.getElementById("breed"),
  furType: document.getElementById("furType"),
  size: document.getElementById("size"),
  selectedPetCount: document.getElementById("selectedPetCount"),
  selectedPetsEmptyState: document.getElementById("selectedPetsEmptyState"),
  selectedPetCards: document.getElementById("selectedPetCards"),
  backBtn: document.getElementById("backBtn"),
  nextBtn: document.getElementById("nextBtn"),
  fieldErrors: {
    petType: document.getElementById("petTypeError"),
    petName: document.getElementById("petNameError"),
    size: document.getElementById("sizeError"),
  },
};

createFixedOptionCombobox({
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

const state = {
  pets: [],
};
let hasSubmittedOnce = false;

/*
  BACKEND TEAMMATE + CLAUDE CODE:
  Walk-in pet records are held in memory while the staff/admin user moves
  through the frontend flow. Persist these pets under the walk-in booking draft
  once a database-backed draft endpoint exists.
*/

const sizeOptionsByType = {
  Dog: ["Small", "Medium", "Large", "Extra Large"],
  Cat: ["Small", "Medium"],
};

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

let messageTimeoutId = null;

function clearMessageTimeout() {
  if (messageTimeoutId) {
    window.clearTimeout(messageTimeoutId);
    messageTimeoutId = null;
  }
}

function showMessage(message, variant = "default", autoHide = false) {
  const styleMap = {
    default: "border-[#9ebedf] bg-white/80 text-slate-500",
    success: "border-emerald-200 bg-emerald-50 text-emerald-700",
    warning: "border-amber-200 bg-amber-50 text-amber-700",
    error: "border-red-200 bg-red-50 text-red-700",
  };

  clearMessageTimeout();
  elements.petStepMessage.className = `mb-6 rounded-2xl border p-5 text-sm ${
    styleMap[variant] || styleMap.default
  }`;
  elements.petStepMessage.textContent = message;

  if (autoHide) {
    messageTimeoutId = window.setTimeout(() => {
      hideMessage();
    }, 3200);
  }
}

function hideMessage() {
  clearMessageTimeout();
  elements.petStepMessage.className = "mb-6 hidden rounded-2xl border p-5 text-sm";
  elements.petStepMessage.textContent = "";
}

function getFormValues(form) {
  return {
    petType: form.petType.value,
    petName: normalizeText(form.petName.value),
    breed: normalizeText(form.breed.value),
    weight: normalizeText(form.weight.value),
    furType: form.furType.value,
    size: form.size.value,
    medicalNotes: normalizeText(form.medicalNotes.value),
  };
}

function validatePetForm(values) {
  const errors = {};

  if (!values.petType.trim()) {
    errors.petType = "Pet type is required.";
  }

  if (!values.petName.trim()) {
    errors.petName = "Pet name is required.";
  }

  const breedValidationMessage = breedCombobox.getValidationMessage();
  if (breedValidationMessage) {
    errors.breed = breedValidationMessage;
  }

  const furTypeValidationMessage = breedCoatCombobox.getValidationMessage();
  if (furTypeValidationMessage) {
    errors.furType = furTypeValidationMessage;
  }

  const allowedSizes = sizeOptionsByType[values.petType] || [];
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
  };
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
        pet.weight || "Not specified",
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
}

function bindRemoveButtons() {
  const buttons = document.querySelectorAll(".remove-selected-pet-btn");

  buttons.forEach((button) => {
    button.addEventListener("click", () => {
      const petId = button.dataset.removePetId;
      const petToRemove = state.pets.find((pet) => pet.id === petId);

      state.pets = state.pets.filter((pet) => pet.id !== petId);
      renderSelectedPets();

      if (petToRemove) {
        showMessage(
          `${petToRemove.petName} was removed from this walk-in schedule.`,
          "warning",
          true,
        );
      }
    });
  });
}

function setFieldError(fieldName, message) {
  const errorElement = elements.fieldErrors[fieldName];

  if (!errorElement) {
    return;
  }

  if (message) {
    errorElement.textContent = message;
    errorElement.className = "mt-2 text-sm text-red-600";
    return;
  }

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
    showMessage(errors.limit || "Please put valid inputs.", "error");
    return;
  }

  if (showAll) {
    showMessage("Please put valid inputs.", "error");
    return;
  }

  hideMessage();
}

function resetAddPetForm() {
  elements.addPetForm.reset();
  elements.size.value = "";
  breedCoatCombobox.reset();
  updateSizeOptions("");
}

function handleAddPetSubmit(event) {
  event.preventDefault();
  hasSubmittedOnce = true;

  const formValues = getFormValues(elements.addPetForm);
  const errors = validatePetForm(formValues);

  if (Object.keys(errors).length > 0) {
    renderValidationErrors(formValues, { showAll: true });
    return;
  }

  const newPet = createPetObject(formValues);
  state.pets = [...state.pets, newPet];

  resetAddPetForm();
  renderSelectedPets();
  showMessage(`${newPet.petName} was added to this walk-in schedule.`, "success", true);
}

function handleBack() {
  window.location.href = "./walk-in-booking.html";
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
    showMessage("Please add at least one pet before continuing.", "error");
    return;
  }

  const appointmentType = getOwnerAppointmentType();

  if (appointmentType === "clinic") {
    if (state.pets.length > 1) {
      showMessage("Clinic walk-in supports one pet per visit. Please remove extra pets.", "error");
      return;
    }
    window.history.pushState(null, "", "./walk-in-clinic-complaint.html");
    renderWalkInClinicComplaintStep({ pet: state.pets[0] });
    return;
  }

  window.history.pushState(null, "", "./walk-in-grooming-services.html");
  renderWalkInServicesStep({ pets: state.pets });
}

function updateSizeOptions(petType) {
  const selectedSize = elements.size.value;
  const options = sizeOptionsByType[petType] || [];

  elements.size.innerHTML = `<option value="">Select size</option>`;

  options.forEach((optionValue) => {
    const option = document.createElement("option");
    option.value = optionValue;
    option.textContent = optionValue;

    if (optionValue === selectedSize) {
      option.selected = true;
    }

    elements.size.appendChild(option);
  });

  if (selectedSize && options.includes(selectedSize)) {
    elements.size.value = selectedSize;
  } else {
    elements.size.value = "";
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
  elements.backBtn.addEventListener("click", handleBack);
  elements.nextBtn.addEventListener("click", handleNext);

  const handlePetInputs = (event) => {
    const formValues = getFormValues(elements.addPetForm);
    renderValidationErrors(formValues, { showAll: false });

    if (event.target === elements.petType) {
      updateSizeOptions(event.target.value);
    }
  };

  elements.petType.addEventListener("input", handlePetInputs);
  elements.petType.addEventListener("change", handlePetInputs);
  elements.petName.addEventListener("input", handlePetInputs);
  elements.breed.addEventListener("input", handlePetInputs);
  elements.breed.addEventListener("change", handlePetInputs);
  elements.furType.addEventListener("input", handlePetInputs);
  elements.size.addEventListener("input", handlePetInputs);
}

function initWalkInPetStep() {
  if (!guardAdminAccess()) {
    return;
  }

  bindEvents();
  updateSizeOptions(elements.petType.value);
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
