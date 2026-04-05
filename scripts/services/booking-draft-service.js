/**
 * Booking Draft Service
 *
 * Purpose:
 * Centralizes the temporary browser-storage contract shared by booking Steps 2
 * to 4.
 *
 * Backend developer guide:
 * Replace these sessionStorage helpers with a real booking-draft source, or map
 * your API response to the same field names so the existing UI can keep working
 * during integration.
 *
 * Draft fields expected by the booking flow:
 * - bookingDate / bookingTime
 * - pets[] with id, petName, petType, breed, size, furType, weight, medicalNotes
 * - derived petIds / petTypes values used by later steps
 */
export const BOOKING_STEP_TWO_KEY = "bookingStep2";
export const BOOKING_STEP_THREE_KEY = "bookingStep3";

export function readSessionJson(key) {
  try {
    const rawValue = sessionStorage.getItem(key);
    return rawValue ? JSON.parse(rawValue) : null;
  } catch (error) {
    console.error(`Failed to parse ${key} sessionStorage:`, error);
    return null;
  }
}

export function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

export function normalizePetType(value) {
  return String(value || "").trim().toLowerCase();
}

export function normalizePetSize(value) {
  const normalizedSize = String(value || "").trim().toLowerCase();

  const sizeMap = {
    small: "small",
    medium: "medium",
    large: "large",
    "extra large": "extra_large",
    "extra-large": "extra_large",
    extralarge: "extra_large",
    "extra_large": "extra_large",
    xl: "extra_large",
  };

  return sizeMap[normalizedSize] || "";
}

export function formatPetTypeLabel(value) {
  const normalizedType = normalizePetType(value);

  if (!normalizedType) {
    return "Unknown Type";
  }

  return normalizedType.charAt(0).toUpperCase() + normalizedType.slice(1);
}

export function formatPetSizeLabel(value) {
  const normalizedSize = normalizePetSize(value);

  const sizeLabels = {
    small: "Small",
    medium: "Medium",
    large: "Large",
    extra_large: "Extra Large",
  };

  return sizeLabels[normalizedSize] || "Size not specified";
}

function normalizePetRecord(pet, index) {
  return {
    ...pet,
    id: pet?.id || `booking-pet-${index + 1}`,
    petName: pet?.petName || "",
    petType: pet?.petType || "",
    breed: pet?.breed || "",
    size: pet?.size || "",
    weight: pet?.weight || "",
    furType: pet?.furType || "",
    medicalNotes: pet?.medicalNotes || "",
  };
}

export function normalizeBookingDraft(data) {
  const petsFromDraft = Array.isArray(data?.pets) ? data.pets.filter(Boolean) : [];
  const legacyPet =
    data?.petId || data?.petName || data?.petType || data?.petBreed
      ? [
          {
            id: data.petId || "",
            petName: data.petName || "",
            petType: data.petType || "",
            breed: data.petBreed || "",
            size: data.petSize || "",
          },
        ]
      : [];

  const pets = (petsFromDraft.length > 0 ? petsFromDraft : legacyPet).map(
    (pet, index) => normalizePetRecord(pet, index),
  );

  const firstPet = pets[0] || null;
  const petTypes = [
    ...new Set(
      pets.map((pet) => normalizePetType(pet.petType)).filter(Boolean),
    ),
  ];

  return {
    bookingDate: data?.bookingDate || data?.date || "",
    bookingTime: data?.bookingTime || data?.time || "",
    pets,
    petIds: pets.map((pet) => pet.id).filter(Boolean),
    petTypes,
    petId: data?.petId || firstPet?.id || "",
    petType: data?.petType || firstPet?.petType || "",
    petName: data?.petName || firstPet?.petName || "",
    petBreed: data?.petBreed || firstPet?.breed || "",
  };
}

export function getBookingDraft() {
  const savedStepTwoDraft = readSessionJson(BOOKING_STEP_TWO_KEY);

  if (savedStepTwoDraft) {
    return normalizeBookingDraft(savedStepTwoDraft);
  }

  const legacySchedule = readSessionJson("bookingSchedule");
  const legacyPets = readSessionJson("bookingPets");

  if (!legacySchedule && !Array.isArray(legacyPets)) {
    return null;
  }

  const normalizedDraft = normalizeBookingDraft({
    bookingDate: legacySchedule?.date || "",
    bookingTime: legacySchedule?.time || "",
    pets: Array.isArray(legacyPets) ? legacyPets : [],
  });

  sessionStorage.setItem(BOOKING_STEP_TWO_KEY, JSON.stringify(normalizedDraft));
  return normalizedDraft;
}
