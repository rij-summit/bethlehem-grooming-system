/* Shared add-product field behavior used by Inventory and Stock In. */
window.ProductForm = (() => {
  const unitOptions = [
    ["pc", "Piece (pc)"], ["tablet", "Tablet"], ["capsule", "Capsule"],
    ["bottle", "Bottle"], ["vial", "Vial"], ["sachet", "Sachet"],
    ["pack", "Pack"], ["box", "Box"], ["can", "Can"],
    ["kg", "Kilogram (kg)"], ["g", "Gram (g)"], ["L", "Liter (L)"],
    ["mL", "Milliliter (mL)"], ["other", "Other…"],
  ].map(([value, label]) => ({ value, label }));
  const categoryOptions = [
    ["medicine", "Medicine"], ["vaccine", "Vaccine"], ["food", "Food"],
    ["grooming_supply", "Grooming Supply"], ["pet_shop", "Pet Shop"], ["miscellaneous", "Miscellaneous"],
  ].map(([value, label]) => ({ value, label }));
  const standardUnits = unitOptions.filter(({ value }) => value !== "other").map(({ value }) => value);

  function empty(values = {}) {
    return {
      item_id: null,
      item_name: "",
      barcode: "",
      category: "",
      unit: "",
      customUnit: "",
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
    const validMoney = (value) => /^\d+(?:\.\d{1,2})?$/.test(value)
      && Number(value) >= 0.01
      && Number(value) <= 999999.99;

    if (barcode !== "" && !/^[0-9]{1,13}$/.test(barcode)) return "Barcode must contain 1 to 13 digits";
    if (!validMoney(unitCost)) return "Unit cost must be a valid amount";
    if (!validMoney(sellingPrice)) return "Selling price must be a valid amount";
    if (!/^\d+$/.test(reorderLevel) || Number(reorderLevel) > 99999999) return "Minimum stock must be a whole number";
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
      selling_price: parseFloat(form.selling_price),
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
      const config = { fps: 10, qrbox: { width: 260, height: 120 } };
      const start = (constraints) => {
        owner._scanner = new Html5Qrcode(readerId);
        return owner._scanner.start(constraints, config, onDecoded, () => {});
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
      owner._scanner.stop().catch(() => {});
      owner._scanner = null;
    }
    owner.scannerActive = false;
  }

  return { categoryOptions, closeBarcodeScanner, empty, normalizeBarcode, normalizeReorderLevel, openBarcodeScanner, payload, unitFields, unitOptions, validate };
})();
