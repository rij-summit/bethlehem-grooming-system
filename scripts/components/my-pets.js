import { normalizePetSize } from "../services/booking-draft-service.js";
import { createBreedCombobox } from "./breed-combobox.js";
import { breedCoatCatalogueReady } from "./breed-coat-catalogue.js";
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

// Connected to pages/client/my-pets.html
// Depends on: api.js and the shared pre-registration pet field components

document.addEventListener("DOMContentLoaded", () => {
  // ── Auth guard ───────────────────────────────────────
  if (!API.getCustomerToken()) {
    window.location.href = "./sign-in.html";
    return;
  }

  // ── State ────────────────────────────────────────────
  let allPets = [];
  let allKnownPets = [];
  let showingArchived = false;
  let editingPet = null;
  let confirmationResolver = null;
  let confirmationReturnFocus = null;

  const clinicVerifiedFieldLabels = {
    breed: "Breed",
    fur_type: "Fur Type",
    weight: "Weight",
    size: "Size",
  };

  // ── DOM refs ─────────────────────────────────────────
  const petsGrid         = document.getElementById("petsGrid");
  const petSearch        = document.getElementById("petSearch");
  const filterActiveBtn  = document.getElementById("filterActiveBtn");
  const filterArchivedBtn= document.getElementById("filterArchivedBtn");
  const addPetBtn        = document.getElementById("addPetBtn");
  const petModal         = document.getElementById("petModal");
  const closePetModal    = document.getElementById("closePetModal");
  const petForm          = document.getElementById("petForm");
  const petModalTitle    = document.getElementById("petModalTitle");
  const petFormError     = document.getElementById("petFormError");
  const petFormSubmit    = document.getElementById("petFormSubmit");
  const petSpecies       = document.getElementById("petSpecies");
  const petBreed         = document.getElementById("petBreed");
  const petGender        = document.getElementById("petGender");
  const petBirthdate     = document.getElementById("petBirthdate");
  const petSize          = document.getElementById("petSize");
  const petFurType       = document.getElementById("petFurType");
  const petWeight        = document.getElementById("petWeight");
  const logoutBtn        = document.getElementById("clientLogoutBtn");
  const petConfirmationModal = document.getElementById("petConfirmationModal");
  const petConfirmationCard = document.getElementById("petConfirmationCard");
  const petConfirmationTitle = document.getElementById("petConfirmationTitle");
  const petConfirmationMessage = document.getElementById("petConfirmationMessage");
  const petConfirmationDetails = document.getElementById("petConfirmationDetails");
  const cancelPetConfirmation = document.getElementById("cancelPetConfirmation");
  const confirmPetAction = document.getElementById("confirmPetAction");
  const editVerifiedIndicators = {
    breed: document.getElementById("petBreedVerified"),
    fur_type: document.getElementById("petFurTypeVerified"),
    weight: document.getElementById("petWeightVerified"),
    size: document.getElementById("petSizeVerified"),
  };

  const petTypeCombobox = createFixedOptionCombobox({
    root: document.getElementById("petSpeciesCombobox"),
    input: petSpecies,
    listbox: document.getElementById("petSpeciesOptions"),
    toggleButton: document.getElementById("petSpeciesDropdownButton"),
    placeholder: "Select species",
    options: [
      { value: "Dog", label: "Dog" },
      { value: "Cat", label: "Cat" },
    ],
  });

  const genderCombobox = createFixedOptionCombobox({
    root: document.getElementById("petGenderCombobox"),
    input: petGender,
    listbox: document.getElementById("petGenderOptions"),
    toggleButton: document.getElementById("petGenderDropdownButton"),
    placeholder: "Select gender",
    options: [
      { value: "male", label: "Male" },
      { value: "female", label: "Female" },
    ],
    displaySelectedLabel: true,
  });

  const breedCombobox = createBreedCombobox({
    root: document.getElementById("petBreedCombobox"),
    input: petBreed,
    listbox: document.getElementById("petBreedOptions"),
    toggleButton: document.getElementById("petBreedDropdownButton"),
    getPetType: () => petSpecies.value,
  });

  const breedCoatCombobox = createBreedCoatCombobox({
    root: document.getElementById("petFurTypeCombobox"),
    breedInput: petBreed,
    petTypeInput: petSpecies,
    input: petFurType,
    listbox: document.getElementById("petFurTypeOptions"),
    toggleButton: document.getElementById("petFurTypeDropdownButton"),
  });

  const sizeCombobox = createFixedOptionCombobox({
    root: document.getElementById("petSizeCombobox"),
    input: petSize,
    listbox: document.getElementById("petSizeOptions"),
    toggleButton: document.getElementById("petSizeDropdownButton"),
    placeholder: "Select size",
    options: [],
    displaySelectedLabel: true,
  });

  // ── Icons ─────────────────────────────────────────────
  if (window.lucide) window.lucide.createIcons();

  // ── Profile ───────────────────────────────────────────
  (async () => {
    try {
      const { user } = await API.getMe("customer");
      const name = `${user.first_name} ${user.last_name}`;
      document.getElementById("clientProfileName").textContent = name;
      document.getElementById("clientProfileInitials").textContent =
        (user.first_name[0] + user.last_name[0]).toUpperCase();
    } catch {
      // silently fail — not critical
    }
  })();

  // ── Logout ────────────────────────────────────────────
  logoutBtn?.addEventListener("click", async () => {
    await API.logout("customer");
    window.location.href = "./sign-in.html";
  });

  // ── Sidebar toggle (mobile) ───────────────────────────
  const sidebarToggle   = document.getElementById("clientSidebarToggle");
  const sidebarBackdrop = document.getElementById("clientSidebarBackdrop");
  const sidebarClose    = document.getElementById("clientSidebarClose");
  const sidebar         = document.getElementById("clientSidebar");
  const mobileSidebarQuery = window.matchMedia("(max-width: 1180px)");
  const sidebarLinks = sidebar?.querySelectorAll("a") || [];

  function setSidebarState(isOpen) {
    document.body.classList.toggle("client-sidebar-open", isOpen);
    sidebarToggle?.setAttribute("aria-expanded", String(isOpen));
    sidebarToggle?.setAttribute(
      "aria-label",
      isOpen ? "Close navigation menu" : "Open navigation menu",
    );
  }

  function closeSidebar() {
    setSidebarState(false);
  }

  function toggleSidebar() {
    if (!mobileSidebarQuery.matches) return;
    const isOpen = document.body.classList.contains("client-sidebar-open");
    setSidebarState(!isOpen);
  }

  setSidebarState(false);
  sidebarToggle?.addEventListener("click", toggleSidebar);
  sidebarBackdrop?.addEventListener("click", closeSidebar);
  sidebarClose?.addEventListener("click", closeSidebar);
  sidebarLinks.forEach((link) => link.addEventListener("click", closeSidebar));
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") closeSidebar();
  });
  mobileSidebarQuery.addEventListener("change", (event) => {
    if (!event.matches) closeSidebar();
  });

  // ── Load pets ─────────────────────────────────────────
  async function loadPets() {
    renderGrid(null); // loading state
    try {
      const [activeData, archivedData] = await Promise.all([
        API.getUserPets({ archived: 0 }),
        API.getUserPets({ archived: 1 }),
      ]);
      const activePets = activeData.pets || [];
      const archivedPets = archivedData.pets || [];
      allKnownPets = [...activePets, ...archivedPets];
      allPets = showingArchived ? archivedPets : activePets;
    } catch {
      allPets = [];
      allKnownPets = [];
    }
    applyFilter();
  }

  // ── Filter + search ───────────────────────────────────
  function applyFilter() {
    const q = petSearch.value.trim().toLowerCase();
    const filtered = q
      ? allPets.filter((p) => p.pet_name.toLowerCase().includes(q))
      : allPets;
    renderGrid(filtered);
  }

  petSearch.addEventListener("input", applyFilter);

  filterActiveBtn.addEventListener("click", () => {
    showingArchived = false;
    filterActiveBtn.className =
      "rounded-full px-5 py-2.5 text-sm font-semibold bg-[#355c84] text-white transition";
    filterArchivedBtn.className =
      "rounded-full px-5 py-2.5 text-sm font-semibold bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 transition";
    loadPets();
  });

  filterArchivedBtn.addEventListener("click", () => {
    showingArchived = true;
    filterArchivedBtn.className =
      "rounded-full px-5 py-2.5 text-sm font-semibold bg-[#355c84] text-white transition";
    filterActiveBtn.className =
      "rounded-full px-5 py-2.5 text-sm font-semibold bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 transition";
    loadPets();
  });

  // ── Render grid ───────────────────────────────────────
  function renderGrid(pets) {
    if (pets === null) {
      petsGrid.innerHTML = `
        <div class="col-span-full text-center py-16">
          <i data-lucide="loader" class="w-8 h-8 mx-auto text-slate-300 animate-spin"></i>
          <p class="mt-3 text-slate-400 text-sm">Loading...</p>
        </div>`;
      window.lucide?.createIcons();
      return;
    }

    if (pets.length === 0) {
      const msg = showingArchived
        ? "No archived pets."
        : "No pets yet. Add your first pet using the button above.";
      petsGrid.innerHTML = `
        <div class="col-span-full text-center py-16">
          <i data-lucide="paw-print" class="w-10 h-10 mx-auto text-slate-200"></i>
          <p class="mt-3 text-slate-400 text-sm">${msg}</p>
        </div>`;
      window.lucide?.createIcons();
      return;
    }

    petsGrid.innerHTML = pets.map((pet) => buildCard(pet)).join("");
    window.lucide?.createIcons();

    petsGrid.querySelectorAll("[data-edit]").forEach((btn) => {
      btn.addEventListener("click", () => openEditModal(Number(btn.dataset.edit)));
    });
    petsGrid.querySelectorAll("[data-archive]").forEach((btn) => {
      btn.addEventListener("click", () => handleArchive(Number(btn.dataset.archive)));
    });
    petsGrid.querySelectorAll("[data-unarchive]").forEach((btn) => {
      btn.addEventListener("click", () => handleUnarchive(Number(btn.dataset.unarchive)));
    });
  }

  function buildCard(pet) {
    const sizeLabel = { small: "Small", medium: "Medium", large: "Large", extra_large: "Extra Large" };
    const furLabel  = { short: "Short", medium: "Medium", long: "Long", wire: "Wire", curl: "Curl" };
    const resolvedFurType = furLabel[pet.fur_type] || pet.fur_type;

    const rows = [
      ["Species", pet.species],
      ["Breed", pet.breed],
      ["Size", sizeLabel[pet.size]],
      ["Fur Type", resolvedFurType],
      ["Weight", pet.weight ? `${pet.weight} kg` : null],
      ["Color", pet.color],
      ["Medical", pet.medical_conditions],
    ].filter(([, v]) => v);

    const detailsHtml = rows.length
      ? rows.map(([label, val]) => `
          <div class="flex gap-2 text-sm">
            <span class="text-slate-400 shrink-0 w-20">${label}</span>
            <span class="min-w-0 break-words text-slate-700 font-medium">${escHtml(String(val))}</span>
          </div>`).join("")
      : `<p class="text-sm text-slate-400">No additional details.</p>`;

    const archiveBtn = pet.is_archived
      ? `<button type="button" data-unarchive="${pet.pet_id}"
            class="flex items-center gap-1.5 rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50 transition">
            <i data-lucide="rotate-ccw" class="w-3.5 h-3.5"></i> Restore
          </button>`
      : `<button type="button" data-archive="${pet.pet_id}"
            class="flex items-center gap-1.5 rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-500 hover:bg-slate-50 transition">
            <i data-lucide="archive" class="w-3.5 h-3.5"></i> Archive
          </button>`;

    const editBtn = pet.is_archived ? "" : `
      <button type="button" data-edit="${pet.pet_id}"
        class="flex items-center gap-1.5 rounded-xl bg-[#dbe8f5] px-3 py-2 text-xs font-semibold text-[#2f4b66] hover:bg-[#ccddf0] transition">
        <i data-lucide="pencil" class="w-3.5 h-3.5"></i> Edit
      </button>`;

    const viewProfileLink = `
      <a href="./pet-details.html?pet_id=${encodeURIComponent(pet.pet_id)}"
        class="flex items-center gap-1.5 rounded-xl bg-[#355c84] px-3 py-2 text-xs font-semibold text-white hover:bg-[#2d4f73] transition">
        <i data-lucide="user-round-search" class="w-3.5 h-3.5"></i> View Profile
      </a>`;

    return `
      <div class="rounded-3xl bg-white border border-slate-200 p-5 shadow-sm flex flex-col gap-4">
        <div class="flex items-start justify-between gap-2">
          <div class="flex items-center gap-3">
            <div class="h-12 w-12 rounded-2xl bg-[#dbe8f5] flex items-center justify-center shrink-0">
              <i data-lucide="paw-print" class="w-5 h-5 text-[#355c84]"></i>
            </div>
            <div>
              <p class="font-bold text-[#2f4b66] text-base leading-tight">${escHtml(pet.pet_name)}</p>
              <p class="text-xs text-slate-400 mt-0.5">${escHtml(pet.species || "Dog")}</p>
            </div>
          </div>
          ${pet.is_archived ? `<span class="shrink-0 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-400">Archived</span>` : ""}
        </div>

        <div class="space-y-1.5 flex-1">
          ${detailsHtml}
        </div>

        <div class="flex flex-wrap gap-2 pt-2 border-t border-slate-100">
          ${viewProfileLink}
          ${editBtn}
          ${archiveBtn}
        </div>
      </div>`;
  }

  // ── Modal ─────────────────────────────────────────────
  function openAddModal() {
    editingPet = null;
    syncEditVerifiedIndicators(null);
    petModalTitle.textContent = "Add Pet";
    resetPetForm();
    hideFormError();
    showModal();
  }

  async function openEditModal(id) {
    const pet = allPets.find((p) => p.pet_id === id);
    if (!pet) return;

    editingPet = pet;
    syncEditVerifiedIndicators(pet);
    petModalTitle.textContent = "Edit Pet";
    document.getElementById("petId").value = pet.pet_id;
    document.getElementById("petName").value = pet.pet_name || "";
    petTypeCombobox.setValue(normalizeSpecies(pet.species));
    genderCombobox.setValue(String(pet.gender || "").toLowerCase());
    petBirthdate.value = pet.birthdate || "";
    petBreed.value = pet.breed || "";
    document.getElementById("petColor").value = pet.color || "";
    document.getElementById("petMedical").value = pet.medical_conditions || "";

    initializeWeightField(petWeight);
    petWeight.value = pet.weight ?? "";
    syncWeightAndSize({ clearManualSize: true });

    if (!getSizeForWeight(petSpecies.value, getEnteredWeight(petWeight))) {
      setStoredSize(pet.size);
    }

    await breedCoatCatalogueReady;
    breedCoatCombobox.update();
    petFurType.value = pet.fur_type || "";
    breedCoatCombobox.update();

    hideFormError();
    showModal();
  }

  function showModal() {
    petModal.classList.remove("hidden");
    petModal.classList.add("flex");
  }

  function closeModal() {
    petModal.classList.add("hidden");
    petModal.classList.remove("flex");
    editingPet = null;
  }

  addPetBtn.addEventListener("click", openAddModal);
  closePetModal.addEventListener("click", closeModal);
  petModal.addEventListener("click", (e) => { if (e.target === petModal) closeModal(); });
  cancelPetConfirmation.addEventListener("click", () => resolvePetConfirmation(false));
  confirmPetAction.addEventListener("click", () => resolvePetConfirmation(true));
  petConfirmationModal.addEventListener("click", (event) => {
    if (event.target === petConfirmationModal) resolvePetConfirmation(false);
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !petConfirmationModal.classList.contains("hidden")) {
      resolvePetConfirmation(false);
    }
  });
  petSpecies.addEventListener("change", () => {
    if (petWeight.dataset.weightFieldMode === "range") {
      resetWeightFieldForEntry(petWeight);
    }
    syncWeightAndSize({ clearManualSize: true });
    validateWeightOnCommit();
  });
  petWeight.addEventListener("input", () => syncWeightAndSize());
  petWeight.addEventListener("change", validateWeightOnCommit);
  petWeight.addEventListener("focus", () => resetWeightFieldForEntry(petWeight));
  petSize.addEventListener("change", handleManualSizeChange);

  // ── Form submit ───────────────────────────────────────
  petForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    hideFormError();

    const id = document.getElementById("petId").value;
    const weight = getEnteredWeight(petWeight);
    const payload = {
      pet_name:           document.getElementById("petName").value.trim(),
      species:            petSpecies.value,
      breed:              petBreed.value.trim() || null,
      gender:             genderCombobox.getValue() || null,
      birthdate:          petBirthdate.value || null,
      color:              document.getElementById("petColor").value.trim() || null,
      size:               normalizePetSize(sizeCombobox.getValue()) || null,
      fur_type:           petFurType.value || null,
      weight:             weight || null,
      medical_conditions: document.getElementById("petMedical").value.trim() || null,
    };

    const validationMessage = validatePetForm(payload);
    if (validationMessage) {
      breedCombobox.showValidation();
      breedCoatCombobox.showValidation();
      const weightMessage = getWeightFieldValidationMessage(petWeight)
        || getWeightValidationMessage(payload.species, weight);
      if (weightMessage) {
        showWeightValidationInField(petWeight, weightMessage);
      }
      showFormError(validationMessage);
      return;
    }

    if (id && editingPet) {
      const changedVerifiedFields = getChangedVerifiedFieldLabels(
        editingPet,
        payload,
      );

      if (changedVerifiedFields.length > 0) {
        const confirmed = await openPetConfirmation({
          title: "Change clinic-verified information?",
          message: "Some of the information you’re changing was verified by Bethlehem Animal Clinic. Are you sure you want to continue?",
          details: `Clinic-verified details being changed: ${formatFieldList(changedVerifiedFields)}.`,
          confirmLabel: "Save changes",
        });

        if (!confirmed) return;
      }
    }

    petFormSubmit.disabled = true;
    petFormSubmit.textContent = "Saving...";

    try {
      if (id) {
        await API.updatePet(id, payload);
      } else {
        await API.addPet(payload);
      }
      closeModal();
      await loadPets();
    } catch (err) {
      showFormError(err.errors
        ? Object.values(err.errors).flat()[0]
        : (err.message || "Something went wrong."));
    } finally {
      petFormSubmit.disabled = false;
      petFormSubmit.textContent = "Save Pet";
    }
  });

  // ── Archive / Unarchive ───────────────────────────────
  async function handleArchive(id) {
    const pet = allPets.find((p) => p.pet_id === id);
    if (!pet) return;
    const confirmed = await openPetConfirmation({
      title: "Archive pet?",
      message: `Archive ${pet.pet_name}? This pet will be hidden from the booking form.`,
      confirmLabel: "Archive pet",
    });
    if (!confirmed) return;
    try {
      await API.archivePet(id);
      await loadPets();
    } catch (err) {
      alert(err.message || "Could not archive pet.");
    }
  }

  async function handleUnarchive(id) {
    const pet = allPets.find((p) => p.pet_id === id);
    if (!pet) return;
    const confirmed = await openPetConfirmation({
      title: "Restore pet?",
      message: `Restore ${pet.pet_name} to your active pets?`,
      confirmLabel: "Restore pet",
    });
    if (!confirmed) return;
    try {
      await API.unarchivePet(id);
      await loadPets();
    } catch (err) {
      alert(err.message || "Could not restore pet.");
    }
  }

  // ── Helpers ───────────────────────────────────────────
  function showFormError(msg) {
    petFormError.textContent = msg;
    petFormError.classList.remove("hidden");
  }

  function hideFormError() {
    petFormError.textContent = "";
    petFormError.classList.add("hidden");
  }

  function openPetConfirmation({ title, message, details = "", confirmLabel }) {
    confirmationReturnFocus = document.activeElement instanceof HTMLElement
      ? document.activeElement
      : null;
    petConfirmationTitle.textContent = title;
    petConfirmationMessage.textContent = message;
    petConfirmationDetails.textContent = details;
    petConfirmationDetails.classList.toggle("hidden", !details);
    confirmPetAction.textContent = confirmLabel;
    petConfirmationModal.classList.remove("hidden");
    petConfirmationModal.classList.add("flex");

    window.setTimeout(() => petConfirmationCard.focus(), 0);

    return new Promise((resolve) => {
      confirmationResolver = resolve;
    });
  }

  function resolvePetConfirmation(confirmed) {
    if (!confirmationResolver) return;

    const resolve = confirmationResolver;
    const returnFocus = confirmationReturnFocus;
    confirmationResolver = null;
    confirmationReturnFocus = null;
    petConfirmationModal.classList.add("hidden");
    petConfirmationModal.classList.remove("flex");
    resolve(confirmed);
    window.setTimeout(() => returnFocus?.focus(), 0);
  }

  function isClinicVerified(pet, field) {
    return Boolean(field)
      && Array.isArray(pet?.clinic_verified_fields)
      && pet.clinic_verified_fields.includes(field);
  }

  function syncEditVerifiedIndicators(pet) {
    Object.entries(editVerifiedIndicators).forEach(([field, indicator]) => {
      indicator?.classList.toggle("hidden", !isClinicVerified(pet, field));
    });
  }

  function getChangedVerifiedFieldLabels(pet, payload) {
    return Object.entries(clinicVerifiedFieldLabels)
      .filter(([field]) => isClinicVerified(pet, field))
      .filter(([field]) => !petFieldValuesMatch(field, pet[field], payload[field]))
      .map(([, label]) => label);
  }

  function petFieldValuesMatch(field, original, updated) {
    if (field === "weight") {
      if ((original === null || original === "")
        && (updated === null || updated === "")) {
        return true;
      }

      const originalWeight = Number(original);
      const updatedWeight = Number(updated);

      return Number.isFinite(originalWeight)
        && Number.isFinite(updatedWeight)
        && Math.abs(originalWeight - updatedWeight) < 0.001;
    }

    const normalize = (value) => value === null || String(value).trim() === ""
      ? null
      : String(value).trim();

    return normalize(original) === normalize(updated);
  }

  function formatFieldList(fields) {
    if (fields.length <= 1) return fields[0] || "";
    if (fields.length === 2) return `${fields[0]} and ${fields[1]}`;

    return `${fields.slice(0, -1).join(", ")}, and ${fields.at(-1)}`;
  }

  function normalizeSpecies(value) {
    return String(value || "").trim().toLowerCase() === "cat" ? "Cat" : "Dog";
  }

  function resetPetForm() {
    document.getElementById("petId").value = "";
    document.getElementById("petName").value = "";
    document.getElementById("petColor").value = "";
    document.getElementById("petMedical").value = "";
    petBirthdate.value = "";

    petTypeCombobox.setValue("Dog");
    genderCombobox.reset();
    breedCombobox.reset();
    breedCoatCombobox.reset();
    initializeWeightField(petWeight);
    syncWeightAndSize({ clearManualSize: true });
  }

  function setStoredSize(storedSize) {
    const normalizedStoredSize = normalizePetSize(storedSize);
    const matchingOption = getSizeOptions(petSpecies.value).find(
      ({ value }) => normalizePetSize(value) === normalizedStoredSize,
    );

    sizeCombobox.setValue(matchingOption?.value || "");
  }

  function syncWeightAndSize({ clearManualSize = false } = {}) {
    const rawWeight = getEnteredWeight(petWeight);
    const options = getSizeOptions(petSpecies.value);

    sizeCombobox.setOptions(options, {
      preserveValue: !clearManualSize,
    });

    const computedSize = getSizeForWeight(petSpecies.value, rawWeight);
    if (computedSize) {
      sizeCombobox.setValue(computedSize);
    }

    sizeCombobox.setDisabled(options.length === 0);
  }

  function validateWeightOnCommit() {
    const rawWeight = getEnteredWeight(petWeight);

    if (!rawWeight) {
      if (petWeight.dataset.weightFieldMode !== "error") {
        showWeightRangeInField(
          petWeight,
          petSpecies.value,
          sizeCombobox.getValue(),
        );
      }
      return true;
    }

    const message = getWeightValidationMessage(petSpecies.value, rawWeight);
    if (message) {
      showWeightValidationInField(petWeight, message);
      return false;
    }

    return true;
  }

  function handleManualSizeChange() {
    if (!getEnteredWeight(petWeight)
      && petWeight.dataset.weightFieldMode !== "error") {
      showWeightRangeInField(
        petWeight,
        petSpecies.value,
        sizeCombobox.getValue(),
      );
    }
  }

  function validatePetForm(payload) {
    const normalizedPetName = normalizePetNameForComparison(payload.pet_name);
    const duplicatePetName = allKnownPets.some((pet) => (
      String(pet.pet_id) !== String(editingPet?.pet_id ?? "")
      && normalizePetNameForComparison(pet.pet_name) === normalizedPetName
    ));

    if (duplicatePetName) {
      return "You already have a pet with this name.";
    }

    const breedValidationMessage = breedCombobox.getValidationMessage();
    if (breedValidationMessage) {
      return breedValidationMessage;
    }

    const furTypeValidationMessage = breedCoatCombobox.getValidationMessage();
    if (furTypeValidationMessage) {
      return furTypeValidationMessage;
    }

    const weightValidationMessage = getWeightFieldValidationMessage(petWeight)
      || getWeightValidationMessage(payload.species, payload.weight);
    if (weightValidationMessage) {
      return weightValidationMessage;
    }

    const allowedSizes = getSizeOptions(payload.species).map(
      ({ value }) => normalizePetSize(value),
    );
    if (payload.size && !allowedSizes.includes(payload.size)) {
      return `${payload.species} size must be one of: ${allowedSizes.join(", ")}.`;
    }

    return "";
  }

  function normalizePetNameForComparison(value) {
    return String(value ?? "")
      .trim()
      .replace(/\s+/g, " ")
      .toLocaleLowerCase();
  }

  function escHtml(str) {
    return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
  }

  // ── Init ──────────────────────────────────────────────
  loadPets();

  if (new URLSearchParams(window.location.search).get("add") === "1") {
    openAddModal();
  }
});
