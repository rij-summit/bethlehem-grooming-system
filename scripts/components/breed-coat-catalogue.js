export const MIXED_BREED = "Mixed Breed / Aspin";
export const UNKNOWN_BREED = "Unknown Breed";
export const OTHER_BREED = "Other \u2014 please specify";

const SPECIAL_BREEDS = new Set([MIXED_BREED, UNKNOWN_BREED]);
const catalogueUrl = new URL("../data/breed-coat-options.json", import.meta.url);

export let BREED_COAT_OPTIONS = {};
export let BREEDS_BY_PET_TYPE = { Dog: [], Cat: [] };

export const breedCoatCatalogueReady = fetch(catalogueUrl)
  .then((response) => {
    if (!response.ok) {
      throw new Error("Breed and coat options could not be loaded.");
    }

    return response.json();
  })
  .then((catalogue) => {
    BREED_COAT_OPTIONS = catalogue;
    BREEDS_BY_PET_TYPE = Object.fromEntries(
      Object.entries(catalogue).map(([petType, breeds]) => [
        petType,
        Object.keys(breeds).filter((breed) => !SPECIAL_BREEDS.has(breed)),
      ]),
    );

    return catalogue;
  })
  .catch((error) => {
    console.error(error);
    return BREED_COAT_OPTIONS;
  });

function normalize(value) {
  return String(value || "").trim().toLocaleLowerCase();
}

function findMatchingKey(record, value) {
  const normalizedValue = normalize(value);
  return Object.keys(record || {}).find((key) => normalize(key) === normalizedValue) || "";
}

export function hasLetter(value) {
  return /\p{L}/u.test(String(value || ""));
}

export function getMatchingBreeds(query, petType, limit = 4) {
  const normalizedQuery = normalize(query);

  if (!hasLetter(normalizedQuery)) {
    return [];
  }

  const breedPool = BREEDS_BY_PET_TYPE[petType]
    || Object.values(BREEDS_BY_PET_TYPE).flat();

  return breedPool
    .filter((breed) => normalize(breed).includes(normalizedQuery))
    .sort((first, second) => {
      const firstStartsWith = normalize(first).startsWith(normalizedQuery);
      const secondStartsWith = normalize(second).startsWith(normalizedQuery);

      if (firstStartsWith !== secondStartsWith) {
        return firstStartsWith ? -1 : 1;
      }

      return first.localeCompare(second);
    })
    .slice(0, limit);
}

export function getCoatOptions(breed, petType) {
  const normalizedBreed = normalize(breed);

  if (!normalizedBreed) {
    return [];
  }

  if (
    [MIXED_BREED, UNKNOWN_BREED].some((specialBreed) => normalize(specialBreed) === normalizedBreed)
    && !BREED_COAT_OPTIONS[petType]
  ) {
    return [];
  }

  const typesToSearch = BREED_COAT_OPTIONS[petType]
    ? [petType]
    : Object.keys(BREED_COAT_OPTIONS);

  for (const type of typesToSearch) {
    const matchingBreed = findMatchingKey(BREED_COAT_OPTIONS[type], breed);

    if (matchingBreed) {
      return [...BREED_COAT_OPTIONS[type][matchingBreed]];
    }
  }

  return [];
}
