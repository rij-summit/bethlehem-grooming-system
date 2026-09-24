const ACTIVE = ["border-[#315b7e]", "bg-[#315b7e]", "text-white", "hover:bg-[#274864]"];
const INACTIVE = ["border-[#315b7e]", "bg-white", "text-[#315b7e]", "hover:bg-[#edf5fc]"];

export function showPetSelectionSection({ existingButton, addButton, existingSection, addSection }, section) {
  const showExisting = section === "existing";
  existingSection.classList.toggle("hidden", !showExisting);
  addSection.classList.toggle("hidden", showExisting);

  for (const [button, active] of [[existingButton, showExisting], [addButton, !showExisting]]) {
    button.setAttribute("aria-pressed", String(active));
    button.classList.remove(...ACTIVE, ...INACTIVE);
    button.classList.add(...(active ? ACTIVE : INACTIVE));
  }
}

export function canSelectAllSavedPets(savedPets, selectedPets, maxPets) {
  const selectedIds = new Set(selectedPets.map((pet) => pet.id));
  const unselectedCount = savedPets.filter((pet) => !selectedIds.has(pet.id)).length;
  return unselectedCount > 0 && unselectedCount <= maxPets - selectedPets.length;
}
