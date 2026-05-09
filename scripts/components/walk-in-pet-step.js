import { renderWalkInServicesStep } from "./walk-in-services-step.js";

const MAX_PETS_PER_BOOKING = 2;

const elements = {
  showAddPetBtn: document.getElementById("showAddPetBtn"),
  petStepMessage: document.getElementById("petStepMessage"),
  addPetSection: document.getElementById("addPetSection"),
  addPetForm: document.getElementById("addPetForm"),
  petType: document.getElementById("petType"),
  furType: document.getElementById("furType"),
  size: document.getElementById("size"),
  selectedPetCount: document.getElementById("selectedPetCount"),
  selectedPetsEmptyState: document.getElementById("selectedPetsEmptyState"),
  selectedPetCards: document.getElementById("selectedPetCards"),
  backBtn: document.getElementById("backBtn"),
  nextBtn: document.getElementById("nextBtn"),
};

const state = {
  pets: [],
};

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

function showMessage(message, variant = "default") {
  const styleMap = {
    default: "border-[#9ebedf] bg-white/80 text-slate-500",
    success: "border-emerald-200 bg-emerald-50 text-emerald-700",
    warning: "border-amber-200 bg-amber-50 text-amber-700",
    error: "border-red-200 bg-red-50 text-red-700",
  };

  elements.petStepMessage.className = `mb-6 rounded-2xl border p-5 text-sm ${
    styleMap[variant] || styleMap.default
  }`;
  elements.petStepMessage.textContent = message;
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
  /*
    BACKEND TEAMMATE + CLAUDE CODE:
    These are UI-level pet checks only. Mirror the final allowed species, size,
    and per-booking pet limit in backend validation before storing walk-ins.
  */
  if (!values.petType.trim()) {
    return "Pet type is required.";
  }

  if (!values.petName.trim()) {
    return "Pet name is required.";
  }

  const allowedSizes = sizeOptionsByType[values.petType] || [];
  if (values.size && !allowedSizes.includes(values.size)) {
    return `${values.petType} size must be one of: ${allowedSizes.join(", ")}.`;
  }

  if (state.pets.length >= MAX_PETS_PER_BOOKING) {
    return `Only ${MAX_PETS_PER_BOOKING} pets are allowed per walk-in schedule.`;
  }

  return "";
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
        );
      }
    });
  });
}

function resetAddPetForm() {
  elements.addPetForm.reset();
  elements.furType.innerHTML = `<option value="">Select fur type</option>`;
  updateSizeOptions("");
}

function handleAddPetSubmit(event) {
  event.preventDefault();

  const formValues = getFormValues(elements.addPetForm);
  const validationMessage = validatePetForm(formValues);

  if (validationMessage) {
    showMessage(validationMessage, "error");
    return;
  }

  const newPet = createPetObject(formValues);
  state.pets = [...state.pets, newPet];

  resetAddPetForm();
  renderSelectedPets();
  showMessage(`${newPet.petName} was added to this walk-in schedule.`, "success");
}

function handleBack() {
  window.location.href = "./walk-in-booking.html";
}

function handleNext() {
  if (state.pets.length === 0) {
    showMessage("Please add at least one pet before continuing.", "error");
    return;
  }

  window.history.pushState(null, "", "./walk-in-grooming-services.html");
  renderWalkInServicesStep({ pets: state.pets });
}

function updateFurOptions(petType) {
  const furOptionsByType = {
    Dog: ["Short", "Medium", "Long", "Curly", "Double Coat"],
    Cat: ["Short Hair", "Long Hair", "Hairless"],
  };

  const options = furOptionsByType[petType] || [];

  elements.furType.innerHTML = `<option value="">Select fur type</option>`;

  options.forEach((optionValue) => {
    const option = document.createElement("option");
    option.value = optionValue;
    option.textContent = optionValue;
    elements.furType.appendChild(option);
  });
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

  elements.petType.addEventListener("change", (event) => {
    updateFurOptions(event.target.value);
    updateSizeOptions(event.target.value);
  });
}

document.addEventListener("DOMContentLoaded", () => {
  if (!guardAdminAccess()) {
    return;
  }

  bindEvents();
  updateSizeOptions(elements.petType.value);
  renderSelectedPets();

  if (window.lucide) {
    window.lucide.createIcons();
  }
});
