import { normalizePetSize, readSessionJson } from "./booking-draft-service.js";

export function groomingForecastPets() {
  const pets = readSessionJson("bookingPets") || [];
  const selections = readSessionJson("bookingStep3")?.petSelections || [];
  if (!pets.length || pets.some((pet) => {
    const selection = selections.find((item) => String(item.petId) === String(pet.id));
    return !selection || (!selection.servicePackage && !selection.alaCarteServices?.length);
  })) return null;
  return pets.map((pet) => {
    const selection = selections.find((item) => String(item.petId) === String(pet.id));
    return { pet_id: Number.isFinite(Number(pet.id)) ? Number(pet.id) : null,
      species: pet.petType, size: normalizePetSize(pet.size) || null,
      weight: pet.weight ? parseFloat(pet.weight) : null,
      grooming_preference: selection.groomingPreference || null,
      services: { package: selection.servicePackage || null, ala_carte: selection.alaCarteServices || [] } };
  });
}

export function forecastGroomingWindows(date) {
  const pets = groomingForecastPets();
  return pets ? API.forecastGroomingWindows(date, pets) : API.getTimeslots(date);
}
