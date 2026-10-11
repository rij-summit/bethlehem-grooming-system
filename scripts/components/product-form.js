/* Shared add-product field behavior used by Inventory and Stock In. */
window.ProductForm = (() => {
  const unitOptions = [
    ["pc", "Piece (pc)"], ["tablet", "Tablet"], ["capsule", "Capsule"],
    ["bottle", "Bottle"], ["vial", "Vial"], ["dose kit", "Dose Kit"], ["sachet", "Sachet"],
    ["pack", "Pack"], ["box", "Box"], ["can", "Can"],
    ["kg", "Kilogram (kg)"], ["g", "Gram (g)"], ["L", "Liter (L)"],
    ["mL", "Milliliter (mL)"], ["other", "Other…"],
  ].map(([value, label]) => ({ value, label }));
  const categoryOptions = [
    ["medicine", "Medicine"], ["vaccine", "Vaccine"], ["food", "Food"],
    ["grooming_supply", "Grooming Supply"], ["pet_shop", "Pet Shop"], ["miscellaneous", "Miscellaneous"],
  ].map(([value, label]) => ({ value, label }));
  const medicineTypeOptions = [
    ["deworming", "Deworming"], ["heartworm_prevention", "Heartworm Prevention"],
    ["heartworm_treatment", "Heartworm Treatment"], ["mange_treatment", "Mange Treatment"],
    ["general_medication", "General Medication"],
  ].map(([value, label]) => ({ value, label }));
  const doseUnitOptions = ["mL", "mg", "g", "tablet", "capsule", "dose", "vial", "other"]
    .map(value => ({ value, label: value === "other" ? "Other…" : value }));
  const isClinic = form => ["medicine", "vaccine"].includes(form.category);
  const isWeightBased = form => form.category === "medicine" && form.pricing_method === "weight_based";
  const validMoney = value => /^\d+(?:\.\d{1,2})?$/.test(String(value))
    && Number(value) >= 0.01 && Number(value) <= 999999.99;

  const validBoundary = value => /^\d+(?:\.\d{0,2})?$/.test(String(value))
    && Number(value) > 0 && Number(value) <= 999999.99;

  function validateRanges(ranges) {
    if (!Array.isArray(ranges) || !ranges.length) return "Add at least one weight range.";
    if (ranges.length > 100) return "Use at most 100 weight ranges.";
    for (let index = 0; index < ranges.length; index++) {
      const range = ranges[index];
      const below = index === 0 && String(range.min_weight_kg) === "0";
      const open = range.max_weight_kg == null;
      if (!below && !validBoundary(range.min_weight_kg))
        return "Enter positive starting weights using no more than 2 decimal places.";
      if (index && Number(range.min_weight_kg) <= Number(ranges[index - 1].min_weight_kg))
        return "Starting weights must be positive and increase with each band.";
      if (open && (below || index !== ranges.length - 1)) return "Only the final Starts band can be open-ended.";
      if (!open && !validBoundary(range.max_weight_kg))
        return "Enter a positive maximum weight using no more than 2 decimal places.";
      if (!open && Number(range.max_weight_kg) <= Number(range.min_weight_kg))
        return "Maximum weight must be greater than the starting weight.";
      if (index < ranges.length - 1 && Number(range.max_weight_kg) !== Number(ranges[index + 1].min_weight_kg))
        return "Each band must end at the next starting weight.";
      if (!validMoney(range.price)) return "Each range price must be a valid positive money amount.";
    }
    return "";
  }

  function scheduleRanges(form) {
    return form.weight_prices.map((range, index, ranges) => ({
      min_weight_kg: range.band === "below" ? "0" : String(range.min_weight_kg).trim(),
      max_weight_kg: index < ranges.length - 1 ? String(ranges[index + 1].min_weight_kg).trim()
        : range.band === "below" ? String(range.min_weight_kg).trim()
        : form.final_range === "open" ? null : String(form.weight_limit ?? "").trim(),
      price: String(range.price).trim(),
    }));
  }

  function rangeLabel(range, final = true, inclusive = false) {
    const min = Number(range.min_weight_kg);
    const max = range.max_weight_kg === "" || range.max_weight_kg == null ? null : Number(range.max_weight_kg);
    if (max === null) return !inclusive && final && min > 0 ? `${min} kg and above` : "Maximum weight not configured";
    if (!inclusive && min === 0) return `Below ${max} kg`;
    if (!inclusive && !final) return `${min} to below ${max} kg`;
    return inclusive ? `${min} kg to ${max} kg` : `${min}–${max} kg`;
  }

  function scheduleLabel(form, index) {
    const ranges = scheduleRanges(form);
    const range = ranges[index];
    if (!range || (!validBoundary(range.min_weight_kg) && range.min_weight_kg !== "0")
      || (range.max_weight_kg !== null && !validBoundary(range.max_weight_kg))) return "";
    return rangeLabel(range, index === ranges.length - 1);
  }

  function syncBelowStart(form, index) {
    if (form.weight_prices[0]?.band !== "below" || form.weight_prices.length < 2) return;
    if (index === 0) form.weight_prices[1].min_weight_kg = form.weight_prices[0].min_weight_kg;
    else if (index === 1) form.weight_prices[0].min_weight_kg = form.weight_prices[1].min_weight_kg;
  }

  function addBelowRange(form) {
    if (form.weight_prices[0]?.band === "below") return;
    form.weight_prices.unshift({ band: "below", min_weight_kg: form.weight_prices[0]?.min_weight_kg ?? "", price: "" });
  }

  function addRange(form) {
    const belowOnly = form.weight_prices.length === 1 && form.weight_prices[0].band === "below";
    form.weight_prices.push({ band: "starts", min_weight_kg: belowOnly ? form.weight_prices[0].min_weight_kg : "", price: "" });
  }

  function removeRange(form, index) {
    form.weight_prices.splice(index, 1);
    if (form.weight_prices[0]?.band === "below" && form.weight_prices.length > 1)
      form.weight_prices[0].min_weight_kg = form.weight_prices[1].min_weight_kg;
  }

  function clinicFields(item) {
    const dose = item.dose_unit || "";
    const standard = doseUnitOptions.some(option => option.value !== "other" && option.value === dose);
    return {
      medicine_type: item.medicine_type || "",
      dose_unit: standard ? dose : (dose ? "other" : ""), customDoseUnit: standard ? "" : dose,
      strength_concentration: item.category === "medicine" ? item.strength_concentration || "" : "",
      pricing_method: item.pricing_method || "flat",
      weight_prices: (item.weight_prices || []).map(range => ({
        band: Number(range.min_weight_kg) === 0 ? "below" : "starts",
        min_weight_kg: Number(range.min_weight_kg) === 0 ? range.max_weight_kg ?? "" : range.min_weight_kg,
        price: range.price,
      })),
      final_range: item.weight_prices?.length && item.weight_prices.at(-1).max_weight_kg == null ? "open" : "capped",
      weight_limit: (item.weight_prices || []).at(-1)?.max_weight_kg ?? "",
      savedWeightPricing: item.category === "medicine" && item.pricing_method === "weight_based",
    };
  }

  const standardUnits = unitOptions.filter(({ value }) => value !== "other").map(({ value }) => value);

  function empty(values = {}) {
    return {
      item_id: null,
      item_name: "",
      barcode: "",
      category: "",
      unit: "",
      customUnit: "",
      medicine_type: "", dose_unit: "", customDoseUnit: "", strength_concentration: "",
      pricing_method: "weight_based", weight_prices: [], final_range: "capped", weight_limit: "", savedWeightPricing: false,
      description: "",
      unit_cost: "",
      selling_price: "",
      reorder_level: "",
      ...values,
    };
  }

  function normalizeBarcode(value) {
    return String(value ?? "").replace(/\D/g, "").slice(0, 13);
  }

  function normalizeReorderLevel(value) {
    const reorderLevel = String(value ?? "");
    return /^\d+\.0+$/.test(reorderLevel)
      ? reorderLevel.slice(0, reorderLevel.indexOf("."))
      : reorderLevel;
  }

  function unitFields(unit) {
    return standardUnits.includes(unit)
      ? { unit, customUnit: "" }
      : { unit: unit ? "other" : "", customUnit: unit ?? "" };
  }

  function validate(form) {
    if (!form.item_name.trim()) return "Product name is required.";
    if (!form.category) return "Category is required.";
    if (!form.unit) return "Unit is required.";
    if (form.unit === "other" && !form.customUnit.trim()) return "Custom unit is required.";

    const barcode = String(form.barcode ?? "");
    const unitCost = String(form.unit_cost ?? "");
    const sellingPrice = String(form.selling_price ?? "");
    const reorderLevel = String(form.reorder_level ?? "");

    if (barcode !== "" && !/^[0-9]{1,13}$/.test(barcode)) return "Barcode must contain 1 to 13 digits";
    if (!validMoney(unitCost)) return "Unit cost must be a valid amount";
    if (!isWeightBased(form) && !validMoney(sellingPrice)) return "Selling price must be a valid amount";
    if (!/^\d+$/.test(reorderLevel) || Number(reorderLevel) > 99999999) return "Minimum stock must be a whole number";
    if (isClinic(form)) {
      if (!form.dose_unit || (form.dose_unit === "other" && !String(form.customDoseUnit || "").trim())) return "Dose unit is required.";
      const dose = form.dose_unit === "other" ? form.customDoseUnit.trim() : form.dose_unit;
      if (dose.length > 30) return "Dose unit must be at most 30 characters.";
    }
    if (form.category === "medicine") {
      if (String(form.strength_concentration || "").length > 150) return "Product strength must be at most 150 characters.";
      if (!medicineTypeOptions.some(option => option.value === form.medicine_type)) return "Medicine type is required.";
      if (!["flat", "weight_based"].includes(form.pricing_method)) return "Pricing method is required.";
      if (isWeightBased(form)) return validateRanges(scheduleRanges(form));
    }
    return "";
  }

  function payload(form) {
    return {
      item_name: form.item_name.trim(),
      barcode: String(form.barcode ?? "") || null,
      category: form.category,
      unit: form.unit === "other" ? form.customUnit.trim() : form.unit,
      description: form.description.trim() || null,
      unit_cost: parseFloat(form.unit_cost),
      selling_price: isWeightBased(form) ? null : parseFloat(form.selling_price),
      medicine_type: form.category === "medicine" ? form.medicine_type : null,
      dose_unit: isClinic(form) ? (form.dose_unit === "other" ? form.customDoseUnit.trim() : form.dose_unit) : null,
      strength_concentration: form.category === "medicine" ? String(form.strength_concentration || "").trim() || null : null,
      pricing_method: form.category === "medicine" ? form.pricing_method : "flat",
      weight_pricing_mode: "threshold",
      ...(isWeightBased(form) ? { weight_final_range: form.weight_prices.at(-1)?.band === "below" ? "capped" : form.final_range } : {}),
      weight_prices: isWeightBased(form) ? scheduleRanges(form) : [],
      reorder_level: parseInt(form.reorder_level, 10),
    };
  }

  function openBarcodeScanner(owner, readerId, onDecoded) {
    if (typeof Html5Qrcode === "undefined") {
      alert("Camera scanner library not loaded. Try refreshing the page.");
      return;
    }

    owner.scannerActive = true;
    requestAnimationFrame(() => requestAnimationFrame(() => {
      if (!owner.scannerActive) return;
      const config = { fps: 10, qrbox: { width: 260, height: 120 } };
      const start = (constraints) => {
        if (!owner.scannerActive) return Promise.resolve();
        owner._scanner = new Html5Qrcode(readerId);
        const scanner = owner._scanner;
        return scanner.start(constraints, config, onDecoded, () => {}).then(() => {
          if (!owner.scannerActive || owner._scanner !== scanner) return scanner.stop().catch(() => {});
        });
      };

      start({
        facingMode: { ideal: "environment" },
        advanced: [{ focusMode: "continuous" }],
      }).catch(() => start({ facingMode: "environment" })).catch(() => {
        owner.scannerActive = false;
        alert("Camera not available. Make sure the app is served on localhost or HTTPS, and that camera permission is granted.");
      });
    }));
  }

  function closeBarcodeScanner(owner) {
    if (owner._scanner) {
      // Navigation may close the scanner before camera startup has completed.
      try { owner._scanner.stop().catch(() => {}); } catch { /* startup will stop on completion */ }
      owner._scanner = null;
    }
    owner.scannerActive = false;
  }

  return { addBelowRange, addRange, removeRange, syncBelowStart, scheduleRanges, scheduleLabel, rangeLabel, clinicFields, doseUnitOptions, isClinic, isWeightBased, medicineTypeOptions, validateRanges, categoryOptions, closeBarcodeScanner, empty, normalizeBarcode, normalizeReorderLevel, openBarcodeScanner, payload, unitFields, unitOptions, validate };
})();
