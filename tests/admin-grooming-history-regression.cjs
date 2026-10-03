const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const context = { Date, Intl, window: {} };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../scripts/components/admin-archive.js'), 'utf8'), context);
const ui = context.adminArchive();
ui.$nextTick = (callback) => callback();
ui.$refs = {};

// Repeat visits must keep their own booking-pet links, sizes and recorded prices.
const session = {
  id: 1, ownerName: 'Owner', bookingReference: 'BAC-20261003-0001', bookingType: 'Pre-Register',
  appointmentDate: '2026-10-03', appointmentTime: '8:00 AM - 9:00 AM',
  startedAt: '11:30 AM', completedAt: '12:30 PM',
  pets: [
    { bookingPetId: 11, petId: 1, petName: 'Hopper', confirmedSize: 'medium' },
    { bookingPetId: 12, petId: 2, petName: 'Rigby', confirmedSize: 'large' },
    { bookingPetId: 13, petId: 3, petName: 'Ocon', confirmedSize: 'small' },
  ],
  services: [
    { id: 1, bookingPetId: 11, name: 'Regular Dog Grooming', paidPrice: '650.00', priceAtBooking: '650.00', price: 9999 },
    { id: 2, bookingPetId: 11, name: 'Nail Clipping', paidPrice: '0.00', price: 9999 },
    { id: 3, bookingPetId: 12, name: 'Regular Dog Grooming', priceAtBooking: '850.00', price: 9999 },
    { id: 4, bookingPetId: 13, name: 'Regular Dog Grooming', paidPrice: '550.00', price: 9999 },
  ],
  productAddons: [{ itemName: 'Shampoo', quantity: 2, priceAtSale: 75, subtotal: 150, sellingPrice: 9999 }],
  paidAmount: 2200,
  payment: { id: 10, finalPrice: 2200, amountPaid: 2500, paymentMethod: 'cash' },
  specialNotes: 'Customer note', internalStaffNote: 'Staff note',
};
const repeat = {
  id: 2, ownerName: 'Owner', bookingReference: 'WI-20261004-001', bookingType: 'Walk-In', appointmentDate: '2026-10-04',
  pets: [{ bookingPetId: 21, petId: 1, petName: 'Hopper', confirmedSize: 'large' }],
  services: [{ id: 5, bookingPetId: 21, name: 'Regular Dog Grooming', paidPrice: 900 }],
  productAddons: [], payment: { id: 11, finalPrice: 900, paymentMethod: 'gcash' },
};
const original = JSON.stringify([session, repeat]);
ui.archivedList = [session, repeat];
ui.selectedYear = '2026';
assert.equal(ui.filteredCount, 2);
assert.equal(ui.groupedByDate.length, 2);
assert.equal(ui.petSummary(session), 'Hopper, Rigby +1 pet');
assert.equal(ui.petSummary(repeat), 'Hopper');
assert.equal(ui.serviceSummary(session), 'Regular Dog Grooming +1 more');
assert.equal(ui.serviceSummary(repeat), 'Regular Dog Grooming');
assert.equal(ui.sessionTime(session), '11:30 AM – 12:30 PM');
assert.equal(ui.sessionTime(repeat), 'Time not recorded');
assert.equal(ui.sessionTime({ startedAt: '11:59 PM', completedAt: '1:00 AM', startedAtIso: '2026-10-03T23:59:00+08:00', completedAtIso: '2026-10-04T01:00:00+08:00' }), '11:59 PM – 1:00 AM (Sun, Oct 4, 2026)');
assert.equal(ui.sessionTime({ startedAt: '11:59 PM', completedAt: '1:00 AM', startedAtIso: '2026-10-03T23:59:00+08:00', completedAtIso: '2026-10-05T01:00:00+08:00' }), '11:59 PM – 1:00 AM (Mon, Oct 5, 2026)');
ui.viewDetails(session);
assert.equal(ui.servicesForPet(session.pets[0]).map((line) => line.id).join(','), '1,2');
assert.equal(ui.formatServiceAvailedPrice(session.services[0]), '₱650.00');
assert.equal(ui.formatServiceAvailedPrice(session.services[1]), '₱0.00');
assert.equal(ui.formatServiceAvailedPrice(session.services[2]), '₱850.00');
assert.equal(ui.paymentAmount(), '₱2200.00');
assert.equal(ui.paymentMethod(), 'Cash');
assert.equal(ui.formatServicesAvailedTotal(), '₱2050.00');
ui.closeDetails();
ui.viewDetails(repeat);
assert.equal(ui.servicesForPet(repeat.pets[0])[0].id, 5);
assert.equal(ui.servicesForPet(session.pets[0]).length, 0);
assert.equal(ui.detailsBooking.pets[0].confirmedSize, 'large');
assert.equal(ui.paymentAmount(), '₱900.00');
assert.equal(ui.paymentMethod(), 'Gcash');
assert.equal(ui.formatServicesAvailedTotal(), '₱900.00');
assert.equal(ui.servicesForPet({}, { services: [{}] }).length, 0);
assert.equal(ui.formatServiceAvailedPrice({ paidPrice: null, price: 9999 }), 'Price unavailable');
assert.equal(ui.formatServicesAvailedTotal({ services: [{ paidPrice: 100 }, {}] }), 'Price unavailable');
assert.equal(ui.paymentAmount({ payment: { finalPrice: 0 }, paidAmount: 900 }), '₱0.00');
assert.equal(ui.paymentAmount({ paidAmount: 123 }), '₱123.00');
assert.equal(ui.paymentAmount({}), 'Not recorded');
ui.selectedMonth = '2026-10';
assert.equal(ui.filteredCount, 2);
ui.sortOrder = 'oldest';
assert.equal(ui.filteredList[0].id, 1);
ui.sortOrder = 'newest';
assert.equal(ui.filteredList[0].id, 2);
assert.equal(JSON.stringify([session, repeat]), original, 'Displaying history must never mutate stored records');
console.log('Grooming history regression tests passed.');
