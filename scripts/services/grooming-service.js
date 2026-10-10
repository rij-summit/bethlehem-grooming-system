import "./grooming-estimate-engine.js";
import {
  BOOKING_STEP_THREE_KEY,
  normalizePetSize,
  normalizePetType,
} from "./booking-draft-service.js";

export const GROOMING_PACKAGES = [
  {
    id: "partial_grooming",
    kind: "package",
    petType: "dog",
    name: "Partial Grooming",
    descriptionItems: [
      "Trimming, Nail Clipping and Ear Cleaning",
      "Cologne Spritz and Dry Shampoo",
    ],
    priceOptions: [],
    allowsAlaCarteServices: true,
    includedAlaCarteServiceIds: ["nail_clipping", "ear_cleaning"],
  },
  {
    id: "regular_dog_grooming",
    kind: "package",
    petType: "dog",
    name: "Regular Dog Grooming",
    descriptionItems: [
      "Bathing with Shampoo and Blow Drying",
      "Summer-cut, Semi-Kalbo, Kalbo or Haircut",
      "Spot, Sanitary area trim, paw pads, face, belly, or rear",
      "Nail Clipping, Ear Cleaning and Tooth-brushing",
    ],
    priceOptions: [],
    allowsAlaCarteServices: true,
    includedAlaCarteServiceIds: [
      "nail_clipping",
      "ear_cleaning",
      "tooth_brushing",
    ],
  },
  {
    id: "deluxe_dog_grooming",
    kind: "package",
    petType: "dog",
    name: "Deluxe Dog Grooming",
    descriptionItems: [
      "Bathing with Shampoo and Blow Drying",
      "Special haircut (Puppy Cut or Hairstyle of choice)",
      "Nail Clipping, Ear Cleaning and Tooth-brushing",
      "Cologne Spritzes",
    ],
    priceOptions: [],
    allowsAlaCarteServices: true,
    includedAlaCarteServiceIds: [
      "nail_clipping",
      "ear_cleaning",
      "tooth_brushing",
    ],
  },
  {
    id: "bath_and_go",
    kind: "package",
    petType: "dog",
    name: "Bath and Go!",
    descriptionItems: [
      "Bathing with Shampoo and Blow Drying",
      "Nail Clipping, Ear Cleaning and Tooth-brushing",
    ],
    priceOptions: [],
    allowsAlaCarteServices: true,
    includedAlaCarteServiceIds: [
      "nail_clipping",
      "ear_cleaning",
      "tooth_brushing",
    ],
  },
  {
    id: "cat_full_grooming",
    kind: "package",
    petType: "cat",
    name: "Full Grooming",
    descriptionItems: [
      "Bathing with Shampoo and Blow Drying",
      "Haircut (If requested)",
      "Nail Clipping",
      "Ear Cleaning",
    ],
    priceOptions: [],
    allowsAlaCarteServices: true,
    includedAlaCarteServiceIds: ["nail_clipping", "ear_cleaning"],
  },
];

export const ALA_CARTE_SERVICES = [
  {
    id: "nail_clipping",
    kind: "ala_carte",
    petTypes: ["cat", "dog"],
    name: "Nail Clipping",
    descriptionItems: [],
    priceOptions: [],
  },
  {
    id: "ear_cleaning",
    kind: "ala_carte",
    petTypes: ["cat", "dog"],
    name: "Ear Cleaning",
    descriptionItems: [],
    priceOptions: [],
  },
  {
    id: "facial_trimming",
    kind: "ala_carte",
    petTypes: ["cat", "dog"],
    name: "Facial Trimming",
    descriptionItems: [],
    priceOptions: [],
  },
  {
    id: "anal_sac_draining",
    kind: "ala_carte",
    petTypes: ["cat", "dog"],
    name: "Anal Sac Draining",
    descriptionItems: [],
    priceOptions: [],
  },
  {
    id: "tooth_brushing",
    kind: "ala_carte",
    petTypes: ["cat", "dog"],
    name: "Tooth Brushing",
    descriptionItems: [],
    priceOptions: [],
  },
];

export function applyGroomingCatalogue(catalogue) {
  if (!Array.isArray(catalogue)) throw new Error("Grooming pricing is unavailable.");
  const metadata = [...GROOMING_PACKAGES, ...ALA_CARTE_SERVICES];
  const byId = new Map(catalogue.map((service) => [service.id, service]));
  for (const service of metadata) {
    const live = byId.get(service.id);
    if (!live?.priceOptions?.length || live.priceOptions.some((option) =>
      !Number.isFinite(option.minAmount) || option.minAmount <= 0 ||
      !["fixed", "range", "starting_at"].includes(option.pricingType))) {
      throw new Error("Grooming pricing is unavailable. Please retry.");
    }
  }
  for (const service of metadata) {
    const live = byId.get(service.id);
    service.serviceId = live.serviceId;
    service.priceOptions = live.priceOptions.map((option) => ({ ...option,
      pricingType: option.pricingType === "starting_at" ? "plus" : option.pricingType }));
  }
  return metadata;
}

export async function loadGroomingCatalogue() {
  const response = await API.getGroomingCatalogue();
  globalThis.GroomingEstimates.configure(response.estimateRules);
  return applyGroomingCatalogue(response.data);
}

export function selectedPricingSignature(draft, selections) {
  return JSON.stringify(buildBookingReviewPayload(draft, selections).items.map((item) =>
    item.pricing.lineItems.map((line) => ({ id: line.serviceId, size: item.pet.size,
      options: line.pricing.selectedPriceOption || line.pricing.availablePriceOptions }))));
}

const PACKAGE_MAP = new Map(
  GROOMING_PACKAGES.map((service) => [service.id, service]),
);
const ALA_CARTE_MAP = new Map(
  ALA_CARTE_SERVICES.map((service) => [service.id, service]),
);

export function formatPhpAmount(amount) {
  return `₱${Number(amount || 0).toLocaleString("en-PH")}`;
}

export function formatPriceOption(priceOption) {
  if (!priceOption) {
    return "";
  }

  if (priceOption.pricingType === "plus") {
    return `${formatPhpAmount(priceOption.minAmount)}+`;
  }

  if (priceOption.pricingType === "range") {
    return `${formatPhpAmount(priceOption.minAmount)}–${formatPhpAmount(priceOption.maxAmount)}`;
  }

  return formatPhpAmount(priceOption.minAmount);
}

export function formatAmountRange(summary) {
  if (!summary) {
    return "";
  }

  if (summary.maxAmount === null) {
    return `${formatPhpAmount(summary.minAmount)}+`;
  }

  if (summary.minAmount === summary.maxAmount && !summary.isEstimate) {
    return formatPhpAmount(summary.minAmount);
  }

  if (summary.minAmount === summary.maxAmount) {
    return `${formatPhpAmount(summary.minAmount)} estimate`;
  }

  return `${formatPhpAmount(summary.minAmount)} - ${formatPhpAmount(summary.maxAmount)}`;
}

export function getGroomingPricePresentation(summary) {
  // The calculated flag preserves variable pricing types, even for equal bounds.
  // Saved summaries without that flag still expose a range or open-ended maximum.
  const isEstimate = Boolean(summary && (
    summary.isEstimate || summary.maxAmount === null || summary.minAmount !== summary.maxAmount
  ));

  return {
    label: isEstimate ? "Estimated Total Price" : "Total Price",
    amount: summary ? formatAmountRange(summary) : "To be confirmed",
    isEstimate,
    disclaimer: isEstimate ? "Final price is confirmed at the clinic." : "",
  };
}

export function getPackagesByPetType(petType) {
  const normalizedType = normalizePetType(petType);
  return GROOMING_PACKAGES.filter(
    (service) => service.petType === normalizedType,
  );
}

export function getAlaCarteServicesByPetType(petType) {
  const normalizedType = normalizePetType(petType);
  return ALA_CARTE_SERVICES.filter(
    (service) =>
      service.petType === normalizedType ||
      service.petType === "all" ||
      service.petTypes?.includes(normalizedType),
  );
}

export function getPackageById(serviceId) {
  return PACKAGE_MAP.get(serviceId) || null;
}

export function getPackageAlaCarteRules(packageId) {
  const selectedPackage = getPackageById(packageId);

  return {
    canCombineWithAlaCarte: Boolean(selectedPackage?.allowsAlaCarteServices),
    includedAlaCarteServiceIds: Array.from(
      new Set(
        Array.isArray(selectedPackage?.includedAlaCarteServiceIds)
          ? selectedPackage.includedAlaCarteServiceIds.filter(Boolean)
          : [],
      ),
    ),
  };
}

export function getAlaCarteServiceById(serviceId) {
  return ALA_CARTE_MAP.get(serviceId) || null;
}

export function createEmptyPetServiceSelection(pet) {
  return {
    petId: pet?.id || "",
    servicePackage: "",
    groomingPreference: "",
    estimateFactors: [],
    alaCarteServices: [],
    addOns: [],
    specialInstructions: "",
  };
}

export function sanitizePetServiceSelection(selection) {
  const nextSelection = {
    petId: selection?.petId || "",
    servicePackage: String(selection?.servicePackage || ""),
    groomingPreference: String(selection?.groomingPreference || ""),
    estimateFactors: [...new Set(selection?.estimateFactors || [])],
    alaCarteServices: Array.from(
      new Set(
        Array.isArray(selection?.alaCarteServices)
          ? selection.alaCarteServices.filter(Boolean)
          : [],
      ),
    ),
    addOns: [],
    specialInstructions:
      typeof selection?.specialInstructions === "string"
        ? selection.specialInstructions
        : "",
  };

  if (!nextSelection.servicePackage || nextSelection.alaCarteServices.length === 0) {
    return nextSelection;
  }

  const packageRules = getPackageAlaCarteRules(nextSelection.servicePackage);

  if (!packageRules.canCombineWithAlaCarte) {
    nextSelection.alaCarteServices = [];
    return nextSelection;
  }

  if (packageRules.includedAlaCarteServiceIds.length === 0) {
    return nextSelection;
  }

  const blockedServiceIds = new Set(packageRules.includedAlaCarteServiceIds);
  nextSelection.alaCarteServices = nextSelection.alaCarteServices.filter(
    (serviceId) => !blockedServiceIds.has(serviceId),
  );

  return nextSelection;
}

export function normalizeStepThreeDraft(rawDraft, bookingDraft) {
  const pets = Array.isArray(bookingDraft?.pets) ? bookingDraft.pets : [];
  const defaultSelectionMap = new Map(
    pets.map((pet) => [pet.id, createEmptyPetServiceSelection(pet)]),
  );

  if (rawDraft && Array.isArray(rawDraft.petSelections)) {
    rawDraft.petSelections.forEach((selection) => {
      if (!selection?.petId || !defaultSelectionMap.has(selection.petId)) {
        return;
      }

      defaultSelectionMap.set(
        selection.petId,
        sanitizePetServiceSelection({
          ...selection,
          petId: selection.petId,
          servicePackage: String(selection.servicePackage || ""),
          alaCarteServices: Array.from(
            new Set(
              Array.isArray(selection.alaCarteServices)
                ? selection.alaCarteServices.filter(Boolean)
                : [],
            ),
          ),
          addOns: Array.from(
            new Set(
              Array.isArray(selection.addOns) ? selection.addOns.filter(Boolean) : [],
            ),
          ),
          specialInstructions:
            typeof selection.specialInstructions === "string"
              ? selection.specialInstructions
              : "",
        }),
      );
    });
  } else if (rawDraft) {
    const firstPet = pets[0];

    if (firstPet) {
      defaultSelectionMap.set(
        firstPet.id,
        sanitizePetServiceSelection({
          petId: firstPet.id,
          servicePackage: String(rawDraft.servicePackage || ""),
          alaCarteServices: Array.from(
            new Set(
              Array.isArray(rawDraft.alaCarteServices)
                ? rawDraft.alaCarteServices.filter(Boolean)
                : [],
            ),
          ),
          addOns: Array.from(
            new Set(
              Array.isArray(rawDraft.addOns) ? rawDraft.addOns.filter(Boolean) : [],
            ),
          ),
          specialInstructions:
            typeof rawDraft.specialInstructions === "string"
              ? rawDraft.specialInstructions
              : "",
        }),
      );
    }
  }

  return pets.map(
    (pet) => defaultSelectionMap.get(pet.id) || createEmptyPetServiceSelection(pet),
  );
}

export function buildStepThreeDraftPayload(petSelections) {
  return {
    version: 2,
    petSelections: petSelections.map((selection) => {
      const sanitizedSelection = sanitizePetServiceSelection(selection);

      return {
        petId: sanitizedSelection.petId,
        servicePackage: sanitizedSelection.servicePackage,
        groomingPreference: sanitizedSelection.groomingPreference,
        estimateFactors: [...sanitizedSelection.estimateFactors],
        alaCarteServices: [...sanitizedSelection.alaCarteServices],
        addOns: [...sanitizedSelection.addOns],
        specialInstructions: sanitizedSelection.specialInstructions.trim(),
      };
    }),
  };
}

export function summarizePriceOptions(priceOptions) {
  let minimumAmount = Number.POSITIVE_INFINITY;
  let maximumAmount = 0;
  let hasMaximumAmount = false;
  let hasUnboundedMaximum = false;
  let hasVariablePricing = false;

  priceOptions.forEach((priceOption) => {
    if (!Number.isFinite(priceOption.minAmount)) {
      return;
    }

    minimumAmount = Math.min(minimumAmount, priceOption.minAmount);
    hasVariablePricing =
      hasVariablePricing || priceOption.pricingType !== "fixed";

    if (priceOption.maxAmount === null) {
      hasUnboundedMaximum = true;
      return;
    }

    maximumAmount = Math.max(maximumAmount, priceOption.maxAmount);
    hasMaximumAmount = true;
  });

  return {
    minAmount:
      minimumAmount === Number.POSITIVE_INFINITY ? 0 : minimumAmount,
    maxAmount: hasUnboundedMaximum
      ? null
      : hasMaximumAmount
        ? maximumAmount
        : 0,
    isEstimate: hasVariablePricing || hasUnboundedMaximum,
    hasUnboundedMaximum,
  };
}

export function evaluateServicePricing(service, petSize) {
  if (!service) {
    return {
      minAmount: 0,
      maxAmount: 0,
      isEstimate: false,
      displayPrice: formatPhpAmount(0),
      selectedPriceOption: null,
      availablePriceOptions: [],
      missingSize: false,
      unmatchedSize: false,
      hasClinicConfirmedAdjustment: false,
    };
  }

  const availablePriceOptions = Array.isArray(service.priceOptions)
    ? service.priceOptions
    : [];

  if (service.kind === "package") {
    const normalizedSize = normalizePetSize(petSize);
    const selectedPriceOption = availablePriceOptions.find(
      (priceOption) => priceOption.sizeKey === normalizedSize,
    );

    if (selectedPriceOption) {
      const isEstimate = selectedPriceOption.pricingType !== "fixed";

      return {
        minAmount: selectedPriceOption.minAmount,
        maxAmount: selectedPriceOption.maxAmount,
        isEstimate,
        displayPrice: formatPriceOption(selectedPriceOption),
        selectedPriceOption,
        availablePriceOptions,
        missingSize: false,
        unmatchedSize: false,
        hasClinicConfirmedAdjustment:
          selectedPriceOption.pricingType === "plus",
      };
    }

    const aggregatePricing = summarizePriceOptions(availablePriceOptions);

    return {
      ...aggregatePricing,
      displayPrice: formatAmountRange(aggregatePricing),
      selectedPriceOption: null,
      availablePriceOptions,
      missingSize: !normalizedSize,
      unmatchedSize: Boolean(normalizedSize),
      hasClinicConfirmedAdjustment:
        aggregatePricing.hasUnboundedMaximum || aggregatePricing.isEstimate,
    };
  }

  const singleOption = availablePriceOptions[0] || null;

  if (!singleOption) {
    return {
      minAmount: 0,
      maxAmount: 0,
      isEstimate: false,
      displayPrice: formatPhpAmount(0),
      selectedPriceOption: null,
      availablePriceOptions,
      missingSize: false,
      unmatchedSize: false,
      hasClinicConfirmedAdjustment: false,
    };
  }

  return {
    minAmount: singleOption.minAmount,
    maxAmount: singleOption.maxAmount,
    isEstimate: singleOption.pricingType !== "fixed",
    displayPrice: formatPriceOption(singleOption),
    selectedPriceOption: singleOption,
    availablePriceOptions,
    missingSize: false,
    unmatchedSize: false,
    hasClinicConfirmedAdjustment: singleOption.pricingType === "plus",
  };
}

export function mergePricingSummaries(pricingSummaries) {
  let minAmount = 0;
  let maxAmount = 0;
  let hasOpenEndedMaximum = false;
  let isEstimate = false;

  pricingSummaries.forEach((summary) => {
    if (!summary) {
      return;
    }

    minAmount += summary.minAmount || 0;
    isEstimate = isEstimate || Boolean(summary.isEstimate);

    if (summary.maxAmount === null) {
      hasOpenEndedMaximum = true;
      return;
    }

    maxAmount += summary.maxAmount || 0;
  });

  return {
    minAmount,
    maxAmount: hasOpenEndedMaximum ? null : maxAmount,
    isEstimate: isEstimate || hasOpenEndedMaximum,
  };
}

export function calculatePetSelectionPricing(selection, pet) {
  const lineItems = [];

  if (selection?.servicePackage) {
    const selectedPackage = getPackageById(selection.servicePackage);

    if (selectedPackage) {
      lineItems.push({
        serviceId: selectedPackage.id,
        serviceName: selectedPackage.name,
        kind: selectedPackage.kind,
        service: selectedPackage,
        pricing: evaluateServicePricing(selectedPackage, pet?.size),
      });
    }
  }

  if (Array.isArray(selection?.alaCarteServices)) {
    selection.alaCarteServices.forEach((serviceId) => {
      const alaCarteService = getAlaCarteServiceById(serviceId);

      if (!alaCarteService) {
        return;
      }

      lineItems.push({
        serviceId: alaCarteService.id,
        serviceName: alaCarteService.name,
        kind: alaCarteService.kind,
        service: alaCarteService,
        pricing: evaluateServicePricing(alaCarteService, pet?.size),
      });
    });
  }

  return {
    lineItems,
    total: mergePricingSummaries(lineItems.map((item) => item.pricing)),
    hasSelection: lineItems.length > 0,
  };
}

export function buildBookingReviewPayload(bookingDraft, petSelections) {
  const pets = Array.isArray(bookingDraft?.pets) ? bookingDraft.pets : [];

  const items = pets.map((pet) => {
    const rawSelection =
      petSelections.find((petSelection) => petSelection.petId === pet.id) ||
      createEmptyPetServiceSelection(pet);
    const selection = sanitizePetServiceSelection(rawSelection);
    const pricing = calculatePetSelectionPricing(selection, pet);

    return {
      pet,
      selection,
      pricing,
      groomingEstimate: estimatePetGrooming(selection, pet),
    };
  });

  const totalPricing = mergePricingSummaries(
    items.map((item) => item.pricing.total),
  );
  const notices = [];

  const hasDisplayedPlusPrice = items.some((item) =>
    item.pricing.lineItems.some(
      (lineItem) => {
        const selectedPriceOption = lineItem.pricing.selectedPriceOption;

        if (selectedPriceOption) {
          return selectedPriceOption.pricingType === "plus";
        }

        return lineItem.pricing.availablePriceOptions.some(
          (priceOption) => priceOption.pricingType === "plus",
        );
      },
    ),
  );

  if (hasDisplayedPlusPrice) {
    notices.push("The price is finalized at the clinic.");
  }

  return {
    storageKey: BOOKING_STEP_THREE_KEY,
    bookingDate: bookingDraft?.bookingDate || "",
    bookingTime: bookingDraft?.bookingTime || "",
    items,
    totalPricing,
    notices,
    isEstimate: totalPricing.isEstimate || notices.length > 0,
  };
}

export function getGroomingPreferences(packageId) {
  return globalThis.GroomingEstimates.preferences(packageId);
}

export function hasRequiredGroomingPreference(selection) {
  const choices = getGroomingPreferences(selection?.servicePackage);
  return !choices.length || choices.some((choice) => choice.value === selection?.groomingPreference);
}

export function estimatePetGrooming(selection, pet) {
  return globalThis.GroomingEstimates.calculate(selection, normalizePetSize(pet?.size));
}
