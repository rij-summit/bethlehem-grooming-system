import {
  MAX_PETS_PER_BOOKING,
  getSavedPets,
  getBookingPets,
  saveBookingPets,
  getBookingSchedule,
  createPetObject,
  addPetToSavedPets,
  addPetToBooking,
  removePetFromBooking,
  loadPetsFromApi,
} from "../services/pet-service.js";
import { formatBookingSchedule } from "../services/booking-format-service.js";

/**
 * Booking Pet Step Controller
 *
 * Purpose:
 * Handles Step 2 of booking:
 * - choose existing pet
 * - add new pet
 * - display selected pets
 * - save selected pets into current booking draft
 *
 * Backend developer guide:
 * Frontend currently uses sessionStorage as a temporary draft store.
 * In production, this should connect to a booking draft or session endpoint.
 *
 * Recommended backend validations:
 * 1. Enforce max 2 pets per booking
 * 2. Verify selected pet belongs to authenticated user
 * 3. Save newly added pet to user's pet list
 * 4. Attach chosen pets to active booking draft
 */

const elements = {
  bookingScheduleSummary: document.getElementById("bookingScheduleSummary"),
  showExistingPetBtn: document.getElementById("showExistingPetBtn"),
  showAddPetBtn: document.getElementById("showAddPetBtn"),
  petStepMessage: document.getElementById("petStepMessage"),

  existingPetSection: document.getElementById("existingPetSection"),
  existingPetEmptyState: document.getElementById("existingPetEmptyState"),
  existingPetList: document.getElementById("existingPetList"),

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

const BOOKING_STEP_TWO_KEY = "bookingStep2";
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

function renderScheduleSummary() {
  const schedule = getBookingSchedule();

  if (!schedule || !schedule.date || !schedule.time) {
    elements.bookingScheduleSummary.textContent =
      "No selected schedule found. Please go back to Step 1 and choose a valid booking time.";
    return;
  }

  elements.bookingScheduleSummary.textContent = formatBookingSchedule(
    schedule.date,
    schedule.time,
  );
}

function showMessage(message, variant = "default") {
  const styleMap = {
    default: "border-[#9ebedf] bg-white/80 text-slate-500",
    success: "border-emerald-200 bg-emerald-50 text-emerald-700",
    warning: "border-amber-200 bg-amber-50 text-amber-700",
    error: "border-red-200 bg-red-50 text-red-700",
  };

  elements.petStepMessage.className = `mb-6 rounded-2xl border p-5 text-sm ${styleMap[variant]}`;

  elements.petStepMessage.textContent = message;
}

function buildStepTwoDraft() {
  const schedule = getBookingSchedule();
  const bookingPets = getBookingPets();
  const firstPet = bookingPets[0] || null;
  const petTypes = [
    ...new Set(bookingPets.map((pet) => pet.petType).filter(Boolean)),
  ];

  const bookingDate = schedule?.date || "";
  const bookingTime = schedule?.time || "";

  return {
    bookingDate,
    bookingTime,
    bookingScheduleText: formatBookingSchedule(bookingDate, bookingTime),
    pets: bookingPets,
    petIds: bookingPets.map((pet) => pet.id).filter(Boolean),
    petTypes,
    petId: firstPet?.id || "",
    petType: firstPet?.petType || "",
    petName: firstPet?.petName || "",
    petBreed: firstPet?.breed || "",
  };
}

function saveStepTwoDraft() {
  const draft = buildStepTwoDraft();
  sessionStorage.setItem(BOOKING_STEP_TWO_KEY, JSON.stringify(draft));
  return draft;
}

function hideSections() {
  elements.existingPetSection.classList.add("hidden");
  elements.addPetSection.classList.add("hidden");
}

function showExistingPetSection() {
  hideSections();
  elements.existingPetSection.classList.remove("hidden");
  renderExistingPets();
}

function showAddPetSection() {
  hideSections();
  elements.addPetSection.classList.remove("hidden");
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

function renderExistingPets() {
  const savedPets = getSavedPets();
  const bookingPets = getBookingPets();

  elements.existingPetList.innerHTML = "";

  if (savedPets.length === 0) {
    elements.existingPetEmptyState.classList.remove("hidden");
    showMessage(
      "You do not have any saved pets yet. Please add a new pet first.",
      "warning",
    );
    return;
  }

  elements.existingPetEmptyState.classList.add("hidden");
  showMessage(
    "Select an existing pet from your saved pet list or add a new one.",
    "default",
  );

  savedPets.forEach((pet) => {
    const isSelected = bookingPets.some(
      (selectedPet) => selectedPet.id === pet.id,
    );
    const bookingIsFull = bookingPets.length >= MAX_PETS_PER_BOOKING;

    const wrapper = document.createElement("article");
    wrapper.className =
      "rounded-2xl border border-slate-200 bg-white p-4 shadow-sm";

    wrapper.innerHTML = `
      <div class="mb-3 flex items-start justify-between gap-3">
        <div>
          <h4 class="text-base font-bold text-[#2f4b66]">${escapeHtml(
            pet.petName,
          )}</h4>
          <p class="mt-1 text-xs text-slate-400">Saved pet</p>
        </div>
        <button
          type="button"
          data-pet-id="${escapeHtml(pet.id)}"
          class="select-existing-pet-btn rounded-xl px-4 py-2 text-xs font-semibold transition ${
            isSelected
              ? "cursor-not-allowed bg-slate-300 text-slate-500"
              : bookingIsFull
                ? "cursor-not-allowed bg-slate-300 text-slate-500"
                : "bg-[#315b7e] text-white hover:bg-[#274864]"
          }"
          ${isSelected || bookingIsFull ? "disabled" : ""}
        >
          ${isSelected ? "Already Selected" : bookingIsFull ? "Limit Reached" : "Select Pet"}
        </button>
      </div>

      ${buildPetSummaryHtml(pet)}
    `;

    elements.existingPetList.appendChild(wrapper);
  });

  bindExistingPetButtons();
}

function bindExistingPetButtons() {
  const buttons = document.querySelectorAll(".select-existing-pet-btn");

  buttons.forEach((button) => {
    button.addEventListener("click", () => {
      const petId = button.dataset.petId;
      const savedPets = getSavedPets();
      const selectedPet = savedPets.find((pet) => pet.id === petId);

      if (!selectedPet) {
        showMessage("Selected pet could not be found.", "error");
        return;
      }

      try {
        addPetToBooking(selectedPet);
        renderExistingPets();
        renderSelectedPets();
        showMessage(
          `${selectedPet.petName} was added to this booking.`,
          "success",
        );
      } catch (error) {
        showMessage(error.message, "error");
      }
    });
  });
}

function renderSelectedPets() {
  const bookingPets = getBookingPets();

  elements.selectedPetCards.innerHTML = "";
  elements.selectedPetCount.textContent = `${bookingPets.length} / ${MAX_PETS_PER_BOOKING} selected`;
  saveStepTwoDraft();

  if (bookingPets.length === 0) {
    elements.selectedPetsEmptyState.classList.remove("hidden");
    elements.nextBtn.disabled = true;
    elements.nextBtn.classList.add("opacity-50", "cursor-not-allowed");
    return;
  }

  elements.selectedPetsEmptyState.classList.add("hidden");
  elements.nextBtn.disabled = false;
  elements.nextBtn.classList.remove("opacity-50", "cursor-not-allowed");

  bookingPets.forEach((pet) => {
    const card = document.createElement("article");
    card.className =
      "rounded-2xl border border-[#c6dbef] bg-[#f8fbfe] p-4 shadow-sm";

    card.innerHTML = `
      <div class="mb-3 flex items-start justify-between gap-3">
        <div>
          <h4 class="text-base font-bold text-[#2f4b66]">${escapeHtml(
            pet.petName,
          )}</h4>
          <p class="mt-1 text-xs text-slate-400">Included in this booking</p>
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
      const currentPets = getBookingPets();
      const petToRemove = currentPets.find((pet) => pet.id === petId);

      removePetFromBooking(petId);
      renderExistingPets();
      renderSelectedPets();

      if (petToRemove) {
        showMessage(
          `${petToRemove.petName} was removed from this booking.`,
          "warning",
        );
      }
    });
  });
}

function getFormValues(form) {
  return {
    petType: form.petType.value,
    petName: form.petName.value,
    breed: form.breed.value,
    weight: form.weight.value,
    furType: form.furType.value,
    size: form.size.value,
    medicalNotes: form.medicalNotes.value,
  };
}

function validatePetForm(values) {
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

  if (getBookingPets().length >= MAX_PETS_PER_BOOKING) {
    return `Only ${MAX_PETS_PER_BOOKING} pets are allowed per booking.`;
  }

  return "";
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

  try {
    // Save to global pet list
    addPetToSavedPets(newPet);

    // Add to current booking
    addPetToBooking(newPet);

    resetAddPetForm();
    renderExistingPets();
    renderSelectedPets();
    showMessage(
      `${newPet.petName} was added and saved to your pet list.`,
      "success",
    );
  } catch (error) {
    showMessage(error.message, "error");
  }
}

function handleBack() {
  window.location.href = "./booking.html";
}

function handleNext() {
  const bookingSchedule = getBookingSchedule();
  const bookingPets = getBookingPets();

  if (!bookingSchedule?.date || !bookingSchedule?.time) {
    showMessage(
      "Please go back to Step 1 and select a valid date and time before continuing.",
      "error",
    );
    return;
  }

  if (bookingPets.length === 0) {
    showMessage(
      "Please select or add at least one pet before continuing.",
      "error",
    );
    return;
  }

  saveStepTwoDraft();
  window.location.href = "./booking-services.html";
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

function bindEvents() {
  elements.showExistingPetBtn.addEventListener("click", showExistingPetSection);
  elements.showAddPetBtn.addEventListener("click", showAddPetSection);
  elements.addPetForm.addEventListener("submit", handleAddPetSubmit);
  elements.backBtn.addEventListener("click", handleBack);
  elements.nextBtn.addEventListener("click", handleNext);

  elements.petType.addEventListener("change", (event) => {
    updateFurOptions(event.target.value);
    updateSizeOptions(event.target.value);
  });
}

async function initStepState() {
  renderScheduleSummary();
  updateSizeOptions(elements.petType.value);

  if (!Array.isArray(getBookingPets())) {
    saveBookingPets([]);
  }

  renderSelectedPets();

  // Sync pets from the backend into localStorage before rendering the list
  showMessage("Loading your saved pets...", "default");
  await loadPetsFromApi();

  const savedPets = getSavedPets();
  if (savedPets.length > 0) {
    showMessage(
      "You have saved pets available. You can select an existing pet or add a new one.",
      "default",
    );
  } else {
    showMessage(
      "No saved pets found yet. Please add a new pet to continue.",
      "warning",
    );
  }
}

document.addEventListener("DOMContentLoaded", () => {
  bindEvents();
  initStepState();
});
