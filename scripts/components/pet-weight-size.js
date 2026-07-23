const WEIGHT_PROFILES = Object.freeze({
  Dog: Object.freeze([
    Object.freeze({ size: "Small", min: 4, max: 10 }),
    Object.freeze({ size: "Medium", min: 11, max: 25 }),
    Object.freeze({ size: "Large", min: 26, max: 50 }),
    Object.freeze({ size: "Extra Large", min: 51, max: 70 }),
  ]),
  Cat: Object.freeze([
    Object.freeze({ size: "Small", min: 2, max: 4 }),
    Object.freeze({ size: "Medium", min: 5, max: 8 }),
  ]),
});

const MAX_WEIGHT_KG = 155.6;
const NORMAL_WEIGHT_PLACEHOLDER = "e.g. 12";
const WEIGHT_FIELD_MODE = "weightFieldMode";

function normalizePetType(petType) {
  const normalized = String(petType || "").trim().toLowerCase();

  return Object.keys(WEIGHT_PROFILES).find(
    (type) => type.toLowerCase() === normalized,
  ) || "";
}

function getProfile(petType) {
  return WEIGHT_PROFILES[normalizePetType(petType)] || [];
}

function hasWeight(rawWeight) {
  return String(rawWeight ?? "").trim() !== "";
}

function formatRangeList(profile) {
  return profile
    .map(({ size, min, max }) => `${size}: ${min} \u2013 ${max} kg`)
    .join(", ");
}

export function getSizeOptions(petType) {
  return getProfile(petType).map(({ size, min, max }) => ({
    value: size,
    label: `${size} \u00b7 ${min} \u2013 ${max} kg`,
  }));
}

export function getWeightLimits(petType) {
  const profile = getProfile(petType);

  if (profile.length === 0) {
    return null;
  }

  return {
    min: profile[0].min,
    max: profile[profile.length - 1].max,
  };
}

export function getWeightRangeHint(petType) {
  const normalizedType = normalizePetType(petType);
  const profile = getProfile(normalizedType);

  if (profile.length === 0) {
    return "Select a pet type to view its accepted weight ranges.";
  }

  return `Accepted ${normalizedType.toLowerCase()} weights: ${formatRangeList(profile)}.`;
}

export function getWeightRangeForSize(petType, size) {
  const range = getProfile(petType).find(({ size: optionSize }) => optionSize === size);

  return range ? `${range.min} \u2013 ${range.max} kg` : "";
}

export function getSizeForWeight(petType, rawWeight) {
  if (!hasWeight(rawWeight)) {
    return "";
  }

  const weight = Number(rawWeight);

  if (!Number.isFinite(weight)) {
    return "";
  }

  return getProfile(petType).find(
    ({ min, max }) => weight >= min && weight <= max,
  )?.size || "";
}

export function getWeightValidationMessage(petType, rawWeight) {
  if (!hasWeight(rawWeight)) {
    return "";
  }

  const weight = Number(rawWeight);

  if (!Number.isFinite(weight)) {
    return "Enter a valid weight in kilograms.";
  }

  if (weight <= 0 || weight > MAX_WEIGHT_KG) {
    return `Weight must be greater than 0 kg and no more than ${MAX_WEIGHT_KG} kg.`;
  }

  const normalizedType = normalizePetType(petType);
  const profile = getProfile(normalizedType);

  if (profile.length === 0) {
    return "Select Dog or Cat before entering a weight.";
  }

  if (!getSizeForWeight(normalizedType, weight)) {
    return `${normalizedType} weight must be within an accepted range: ${formatRangeList(profile)}.`;
  }

  return "";
}

export function getEnteredWeight(input) {
  if (!input || input.dataset[WEIGHT_FIELD_MODE] !== "input") {
    return "";
  }

  return input.value.trim();
}

export function resetWeightFieldForEntry(input) {
  if (!input || input.dataset[WEIGHT_FIELD_MODE] === "input") {
    return;
  }

  input.dataset[WEIGHT_FIELD_MODE] = "input";
  input.readOnly = false;
  input.value = "";
  input.placeholder = NORMAL_WEIGHT_PLACEHOLDER;
  input.setAttribute("aria-invalid", "false");
  input.classList.remove("border-red-400", "text-red-600");
  delete input.dataset.weightValidationMessage;
}

export function initializeWeightField(input) {
  if (!input) {
    return;
  }

  input.dataset[WEIGHT_FIELD_MODE] = "input";
  input.readOnly = false;
  input.value = "";
  input.placeholder = NORMAL_WEIGHT_PLACEHOLDER;
  input.setAttribute("aria-invalid", "false");
  input.classList.remove("border-red-400", "text-red-600");
  delete input.dataset.weightValidationMessage;
}

export function showWeightRangeInField(input, petType, size) {
  const rangeLabel = getWeightRangeForSize(petType, size);

  if (!input || !rangeLabel) {
    return false;
  }

  input.dataset[WEIGHT_FIELD_MODE] = "range";
  input.readOnly = true;
  input.value = rangeLabel;
  input.placeholder = NORMAL_WEIGHT_PLACEHOLDER;
  input.setAttribute("aria-invalid", "false");
  input.classList.remove("border-red-400", "text-red-600");
  delete input.dataset.weightValidationMessage;
  return true;
}

export function showWeightValidationInField(input, message) {
  if (!input || !message) {
    return;
  }

  input.dataset[WEIGHT_FIELD_MODE] = "error";
  input.readOnly = true;
  input.value = message;
  input.placeholder = NORMAL_WEIGHT_PLACEHOLDER;
  input.setAttribute("aria-invalid", "true");
  input.classList.add("border-red-400", "text-red-600");
  input.dataset.weightValidationMessage = message;
}

export function getWeightFieldValidationMessage(input) {
  return input?.dataset.weightValidationMessage || "";
}
