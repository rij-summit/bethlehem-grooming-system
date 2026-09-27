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

console.log("Admin grooming pricing regression tests passed.");
