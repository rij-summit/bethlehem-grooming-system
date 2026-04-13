/**
 * Pet Service
 *
 * Saved pets (clientPets) use localStorage so they persist across sessions.
 * Booking pets (current booking draft) stay in sessionStorage — they only
 * need to live for the duration of one booking flow.
 *
 * On the pet step, call loadPetsFromApi() first to sync the user's pets
 * from the database into localStorage before rendering.
 */

const CLIENT_PETS_KEY = "clientPets";     // localStorage — persists across tabs/sessions
const BOOKING_PETS_KEY = "bookingPets";   // sessionStorage — current booking draft only
const BOOKING_SCHEDULE_KEY = "bookingSchedule";

export const MAX_PETS_PER_BOOKING = 2;

// ── Saved pets (localStorage) ─────────────────────────────────────────────

export function getSavedPets() {
  try {
    const raw = localStorage.getItem(CLIENT_PETS_KEY);
    return raw ? JSON.parse(raw) : [];
  } catch (error) {
    console.error("Failed to read saved pets:", error);
    return [];
  }
}

export function saveSavedPets(pets) {
  localStorage.setItem(CLIENT_PETS_KEY, JSON.stringify(pets));
}

/**
 * Fetch the user's pets from the backend and merge into localStorage.
 * Backend pets take precedence — they are the source of truth.
 * Any locally-added pets that don't exist in the DB yet are kept.
 */
export async function loadPetsFromApi() {
  try {
    const { pets: apiPets } = await API.getUserPets();

    // Convert backend field names to frontend format
    const mappedPets = apiPets.map((p) => ({
      id: String(p.pet_id),
      petName: p.pet_name || "",
      petType: p.species || "Dog",
      breed: p.breed || "",
      weight: p.weight ? String(p.weight) : "",
      furType: p.fur_type || "",
      size: p.size || "",
      medicalNotes: p.medical_conditions || "",
    }));

    // Merge: keep local pets whose id isn't in the API response
    const apiIds = new Set(mappedPets.map((p) => p.id));
    const localOnly = getSavedPets().filter((p) => !apiIds.has(p.id));

    saveSavedPets([...mappedPets, ...localOnly]);
  } catch {
    // Network failure or not logged in — keep whatever is in localStorage
  }
}

// ── Booking pets (sessionStorage) ────────────────────────────────────────

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

// ── Pet object helpers ────────────────────────────────────────────────────

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
    throw new Error(`Only ${MAX_PETS_PER_BOOKING} pets are allowed per booking.`);
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
  const updatedBookingPets = getBookingPets().filter((pet) => pet.id !== petId);
  saveBookingPets(updatedBookingPets);
  return updatedBookingPets;
}

export function isPetAlreadySelected(petId) {
  return getBookingPets().some((pet) => pet.id === petId);
}
