/**
 * Pet Service
 *
 * Purpose:
 * Temporary frontend data service for saved pets and selected booking pets.
 *
 * Backend developer guide:
 * Replace sessionStorage/localStorage usage with real API calls.
 *
 * Suggested backend endpoints:
 * - GET /api/pets
 * - POST /api/pets
 * - GET /api/bookings/draft
 * - PATCH /api/bookings/draft/pets
 *
 * Notes:
 * - "clientPets" simulates the pet list shown in dashboard / account pets
 * - "bookingPets" simulates the pets attached to the current booking draft
 */

const CLIENT_PETS_KEY = "clientPets";
const BOOKING_PETS_KEY = "bookingPets";
const BOOKING_SCHEDULE_KEY = "bookingSchedule";

/**
 * Use this max limit both in frontend and backend to avoid mismatch.
 */
export const MAX_PETS_PER_BOOKING = 2;

export function getSavedPets() {
  try {
    const raw = sessionStorage.getItem(CLIENT_PETS_KEY);
    return raw ? JSON.parse(raw) : [];
  } catch (error) {
    console.error("Failed to read saved pets:", error);
    return [];
  }
}

export function saveSavedPets(pets) {
  sessionStorage.setItem(CLIENT_PETS_KEY, JSON.stringify(pets));
}

export function getBookingPets() {
  try {
    const raw = sessionStorage.getItem(BOOKING_PETS_KEY);
    return raw ? JSON.parse(raw) : [];
  } catch (error) {
    console.error("Failed to read booking pets:", error);
    return [];
  }
}

export function saveBookingPets(pets) {
  sessionStorage.setItem(BOOKING_PETS_KEY, JSON.stringify(pets));
}

export function getBookingSchedule() {
  try {
    const raw = sessionStorage.getItem(BOOKING_SCHEDULE_KEY);
    return raw ? JSON.parse(raw) : null;
  } catch (error) {
    console.error("Failed to read booking schedule:", error);
    return null;
  }
}

export function createPetObject(formData) {
  return {
    id: crypto.randomUUID(),
    petType: formData.petType,
    petName: formData.petName.trim(),
    breed: formData.breed.trim(),
    weight: formData.weight.trim(),
    furType: formData.furType,
    size: formData.size,
    medicalNotes: formData.medicalNotes.trim(),
  };
}

export function addPetToSavedPets(newPet) {
  const currentPets = getSavedPets();
  const updatedPets = [...currentPets, newPet];
  saveSavedPets(updatedPets);
  return updatedPets;
}

export function addPetToBooking(newPet) {
  const currentBookingPets = getBookingPets();

  if (currentBookingPets.length >= MAX_PETS_PER_BOOKING) {
    throw new Error(
      `Only ${MAX_PETS_PER_BOOKING} pets are allowed per booking.`,
    );
  }

  const alreadyExists = currentBookingPets.some((pet) => pet.id === newPet.id);
  if (alreadyExists) {
    return currentBookingPets;
  }

  const updatedBookingPets = [...currentBookingPets, newPet];
  saveBookingPets(updatedBookingPets);
  return updatedBookingPets;
}

export function removePetFromBooking(petId) {
  const currentBookingPets = getBookingPets();
  const updatedBookingPets = currentBookingPets.filter(
    (pet) => pet.id !== petId,
  );
  saveBookingPets(updatedBookingPets);
  return updatedBookingPets;
}

export function isPetAlreadySelected(petId) {
  return getBookingPets().some((pet) => pet.id === petId);
}
