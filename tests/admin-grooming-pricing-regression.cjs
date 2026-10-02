const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const source = fs.readFileSync(path.join(__dirname, "../scripts/components/admin-dashboard.js"), "utf8");
const context = { window: { AppClock: { todayKey: () => "2026-09-27" } }, console, Date, Intl };
vm.runInNewContext(source, context);
const ui = context.adminDashboard();

const booking = {
  paid: false,
  pets: [{ bookingPetId: 1, petName: "Mochi", species: "Cat", size: "small" }],
  services: [
    { bookingServiceId: 1, bookingPetId: 1, slug: "cat_full_grooming", priceAtBooking: "500.00" },
    { bookingServiceId: 2, bookingPetId: 1, slug: "nail_clipping", priceAtBooking: "0.00", priceMinAtBooking: "50.00", priceMaxAtBooking: "100.00" },
  ],
};
assert.equal(ui.formatServiceAvailedPrice(booking.services[1], booking), "₱50–₱100");
assert.equal(ui.formatServicesAvailedTotal(booking), "₱550–₱600");
assert.equal(ui.formatPetServicesAvailedTotal(booking.pets[0], booking), "₱550–₱600");

const pet = { sizeKey: "small" };
const line = ui.normalizePaymentLine(booking.services[1], pet, "range", { lockFixedPrices: true });
assert.equal(line.isFixedPriceLocked, false);
assert.equal(line.amount, "");
const fixed = ui.normalizePaymentLine(booking.services[0], pet, "fixed", { lockFixedPrices: true });
assert.equal(fixed.isFixedPriceLocked, true);
assert.equal(fixed.amount, "500.00");
line.amount = "30";
assert.equal(ui.paymentLineError(line), "Enter an amount from ₱50 to ₱100.");
assert.equal(line.amount, "30");
line.amount = "101";
assert.equal(ui.paymentLineError(line), "Enter an amount from ₱50 to ₱100.");
assert.equal(line.amount, "101");
line.amount = "75.001";
assert.equal(ui.paymentLineError(line), "Enter an amount with no more than 2 decimal places.");
assert.equal(line.amount, "75.001");
for (const valid of ["50", "75", "100"]) {
  line.amount = valid;
  assert.equal(ui.paymentLineError(line), "");
}
ui.paymentModal.petBreakdown = [{ name: "Mochi", lines: [line] }];
ui.paymentModal.amountPaid = "100";
ui.paymentModal.paymentMethod = "cash";
line.amount = "101";
assert.equal(ui.canSubmitPayment, false);
line.amount = "75.001";
assert.equal(ui.canSubmitPayment, false);
line.amount = "80";
assert.equal(ui.canSubmitPayment, true);

const minimum = ui.normalizePaymentLine({ slug: "ear_cleaning", priceAtBooking: "150.00", priceMinAtBooking: "150.00" }, pet, "minimum");
minimum.amount = "149";
assert.equal(ui.paymentLineError(minimum), "Enter an amount of at least ₱150.");
assert.equal(minimum.amount, "149");
minimum.amount = "150";
assert.equal(ui.paymentLineError(minimum), "");

const largePet = { sizeKey: "large" };
const sizeDependent = ui.normalizePaymentLine({
  slug: "partial_grooming", priceAtBooking: "600.00", priceMinAtBooking: "600.00",
}, largePet, "package", { lockFixedPrices: true });
sizeDependent.amount = "650";
largePet.sizeKey = "extra_large";
ui.refreshPaymentLinePricing(largePet, sizeDependent);
assert.equal(sizeDependent.minAmount, 700);
assert.equal(sizeDependent.amount, "650");
assert.equal(ui.paymentLineError(sizeDependent), "Enter an amount of at least ₱700.");

booking.paid = true;
booking.services[1].priceAtBooking = "80.00";
assert.equal(ui.formatServiceAvailedPrice(booking.services[1], booking), "₱80.00");

const plusCases = [
  ["partial_grooming", "large", 600, 700, 1100],
  ["partial_grooming", "extra_large", 700, null, 1200],
  ["regular_dog_grooming", "large", 850, 1050, 1350],
  ["regular_dog_grooming", "extra_large", 1050, null, 1550],
  ["deluxe_dog_grooming", "large", 1000, 1200, 1500],
  ["deluxe_dog_grooming", "extra_large", 1200, null, 1700],
  ["bath_and_go", "large", 650, 750, 1150],
  ["bath_and_go", "extra_large", 750, null, 1250],
  ["ear_cleaning", "small", 150, null, 350],
  ["tooth_brushing", "small", 100, null, 300],
];
const ruleMoney = (amount) => `₱${amount.toLocaleString("en-PH")}`;
for (const [slug, sizeKey, min, threshold, max] of plusCases) {
  const service = ui.normalizePaymentLine({ slug, bookingServiceId: 1, priceMinAtBooking: String(min) }, { sizeKey }, slug, { lockFixedPrices: true });
  assert.equal(service.isFixedPriceLocked, false);
  assert.equal(service.priceHint, `${ruleMoney(min)}+`);
  assert.equal(service.maxAmount, null, "Safety caps must not become advertised ranges");
  assert.equal(service.safetyMaxAmount, max);
  assert.equal(service.reviewThreshold, threshold);
  const legacy = ui.normalizePaymentLine({ slug }, { sizeKey }, slug, { lockFixedPrices: true });
  assert.equal(legacy.safetyMaxAmount, max);
  assert.equal(legacy.reviewThreshold, threshold);
  ui.paymentModal.petBreakdown = [{ name: "Rigby", lines: [service] }];
  ui.paymentModal.amountPaid = String(max);
  service.amount = String(min - 0.01);
  assert.equal(ui.paymentLineError(service), `Enter an amount of at least ${ruleMoney(min)}.`);
  assert.equal(ui.canSubmitPayment, false);
  for (const valid of [min, min + 0.01, max]) {
    service.amount = String(valid);
    assert.equal(ui.paymentLineError(service), "");
    assert.equal(ui.canSubmitPayment, true);
  }
  if (threshold !== null) {
    service.amount = String(threshold - 0.01);
    assert.equal(ui.paymentLineWarning(service), "");
    for (const [amount, comparison] of [[threshold, "at"], [threshold + 0.01, "above"]]) {
      service.amount = String(amount);
      assert.equal(ui.paymentLineWarning(service), `This amount is ${comparison} the Extra Large starting price of ${ruleMoney(threshold)}. Confirm the pet's size and final charge.`);
      assert.equal(ui.canSubmitPayment, true, "A size-review warning must not block payment");
    }
  } else {
    assert.equal(ui.paymentLineWarning(service), "");
  }
  service.amount = String(max + 0.01);
  assert.equal(ui.paymentLineError(service), `Enter ${ruleMoney(max)} or less for this service.`);
  assert.equal(ui.canSubmitPayment, false);
  assert.equal(service.amount, String(max + 0.01), "Invalid input must not be clamped");
  for (const invalid of ["", "-1", "1e5", "1E3", "+850", "850.001", "NaN", "Infinity", " "]) {
    service.amount = invalid;
    assert.notEqual(ui.paymentLineError(service), "");
    assert.equal(ui.canSubmitPayment, false);
    assert.equal(service.amount, invalid);
  }
}

const resizedPet = { sizeKey: "large" };
const resizedLine = ui.normalizePaymentLine({ slug: "regular_dog_grooming", priceMinAtBooking: "850.00" }, resizedPet, "resized", { lockFixedPrices: true });
resizedLine.amount = "1400";
assert.notEqual(ui.paymentLineError(resizedLine), "");
resizedPet.sizeKey = "extra_large";
ui.refreshPaymentLinePricing(resizedPet, resizedLine);
assert.equal(resizedLine.safetyMaxAmount, 1550);
assert.equal(resizedLine.reviewThreshold, null);
assert.equal(resizedLine.priceHint, "₱1,050+");
assert.equal(resizedLine.amount, "1400");
assert.equal(ui.paymentLineError(resizedLine), "");
resizedPet.sizeKey = "medium";
ui.refreshPaymentLinePricing(resizedPet, resizedLine);
assert.equal(resizedLine.isFixedPriceLocked, true);
assert.equal(resizedLine.amount, "650.00");
assert.equal(resizedLine.safetyMaxAmount, null);
assert.equal(resizedLine.reviewThreshold, null);

for (const [slug, sizeKey, amount] of [["partial_grooming", "small", 400], ["regular_dog_grooming", "medium", 650], ["facial_trimming", "small", 150]]) {
  const locked = ui.normalizePaymentLine({ slug }, { sizeKey }, slug, { lockFixedPrices: true });
  assert.equal(locked.isFixedPriceLocked, true);
  assert.equal(locked.amount, amount.toFixed(2));
}
const fixedBounds = ui.normalizePaymentLine({ slug: "facial_trimming", priceMinAtBooking: "150", priceMaxAtBooking: "150" }, pet, "fixed-bounds", { lockFixedPrices: true });
assert.equal(fixedBounds.isFixedPriceLocked, true);

ui.paymentModal.petBreakdown = [{ lines: [{ amount: "1600" }] }];
assert.equal(ui.paymentMaximumAmount, 3000);
ui.paymentModal.amountPaid = "3000.01";
ui.enforcePaymentAmountLimit();
assert.equal(ui.paymentModal.amountPaid, "3000", "Cash Received keeps its existing bill-based clamp");

console.log("Admin grooming pricing regression tests passed.");
