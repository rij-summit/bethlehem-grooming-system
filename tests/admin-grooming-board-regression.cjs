const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const context = { window: {}, console, Date, Intl };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../scripts/components/admin-dashboard.js'), 'utf8'), context);
const ui = context.adminDashboard();
ui.$nextTick = () => {};
ui.dispatchDashboardEvent = () => {};
const pet = { bookingPetId: 7, petQueueNumber: 12, petName: 'Hopper', species: 'Dog', breed: 'Dachshund', groomingState: 'not_started' };
const booking = { id: 42, queueNumber: 3, ownerName: 'Erwin Francisco', pets: [pet], dropOffTime: '1:22 AM' };

for (const [tab, list, label, tone] of [
  ['queued', 'queuedList', 'Waiting', 'waiting'],
  ['in-progress', 'inProgressList', 'In Progress', 'progress'],
  ['for-payment', 'forPaymentList', 'Awaiting Payment', 'payment'],
  ['to-be-picked-up', 'releasedList', 'Paid & Ready', 'pickup'],
]) {
  ui.applyDashboardData({ [list]: [{ ...booking, status: tab }] });
  ui.setTab(tab);
  const record = ui.operationalBookings[0];
  assert.equal(record.id, booking.id);
  assert.equal(record.queueNumber, 3);
  assert.equal(ui.formatPetQueueNumber(record.pets[0]), '#P12');
  assert.equal(ui.groomingPetCount(record), '1 pet');
  assert.equal(ui.groomingStatusLabel(record), label);
  assert.equal(ui.groomingCardTone(record), tone);
}
assert.equal(ui.groomingStatusLabel({ status: 'for_payment', paid: true }), 'Paid & Ready');
assert.equal(ui.groomingBookingTiming({ ...booking, status: 'queued' }), 'Dropped off 1:22 AM');
assert.equal(ui.groomingBookingTiming({ ...booking, status: 'in_progress', startedAt: '1:25 AM' }), 'Started 1:25 AM');
assert.equal(ui.groomingBookingTiming({ ...booking, status: 'for-payment', completedAt: '3:10 AM' }), 'Finished 3:10 AM');
assert.equal(ui.groomingBookingTiming({ status: 'for-payment' }), '');
assert.equal(ui.shouldShowOwnerRevert({ ...booking, status: 'queued' }), true);
assert.equal(ui.shouldShowOwnerRevert({ ...booking, status: 'in-progress', pets: [{ ...pet, groomingState: 'in_progress' }] }), true);
assert.equal(ui.shouldShowOwnerRevert({ ...booking, status: 'in-progress', pets: [{ ...pet, groomingState: 'finished' }] }), false);
assert.equal(ui.shouldShowOwnerRevert({ ...booking, status: 'for-payment' }), false);
assert.equal(ui.shouldShowOwnerCancel({ ...booking, status: 'queued' }), true);
assert.equal(ui.shouldShowOwnerCancel({ ...booking, status: 'in-progress', paid: true }), true);
assert.equal(ui.shouldShowOwnerCancel({ ...booking, status: 'to-be-picked-up' }), false);

const multiple = { ...booking, pets: [pet, { ...pet, bookingPetId: 8, petName: 'Mark' }, { ...pet, bookingPetId: 9, petName: 'Milo' }] };
assert.equal(ui.incomingPetSummary(booking), 'Hopper');
assert.equal(ui.incomingPetSummary(multiple), '3 pets · Hopper + 2 more');
for (const status of ['queued', 'in-progress']) {
  const record = { ...multiple, status };
  assert.equal(ui.isGroomingBookingExpanded(record), false);
  ui.toggleGroomingBooking(record);
  assert.equal(ui.isGroomingBookingExpanded(record), true);
  ui.toggleGroomingBooking(record);
  assert.equal(ui.isGroomingBookingExpanded(record), false);
}
ui.activeTab = 'incoming';
assert.equal(ui.operationalBookings.length, 0);
ui.queuedList = [];
ui.inProgressList = [];
ui.groomingWorkload = { state: 'On track' };
ui.activeGroomers = 1;
ui.activeGroomingPets = 0;
assert.equal(ui.groomingWorkloadMessage, '', 'Normal workload stays quiet');
ui.activeGroomingPets = 1;
ui.queuedList = [booking];
assert.equal(ui.groomingWorkloadMessage, 'Groomer capacity reached');
ui.groomingWorkload.state = 'At risk';
assert.equal(ui.groomingWorkloadMessage, 'Schedule at risk');
ui.groomingWorkload.state = 'Needs staff action';
assert.equal(ui.groomingWorkloadMessage, 'Current grooming workload needs attention');
context.window.GroomingEstimates = { formatTimeWindow: () => '6:00 PM' };
ui.groomingWorkload.closing_time = '2026-10-16T18:00:00+08:00';
ui.groomingWorkload.projected_last_completion = '2026-10-16T18:30:00+08:00';
assert.match(ui.groomingWorkloadMessage, /Projected completion exceeds grooming hours/);
assert.match(ui.groomingWorkloadMessage, /6:00 PM/);

ui.groomingWorkload = { state: 'On track', pets: {} };
ui.queuedList = [];
ui.inProgressList = [];
ui.activeGroomingPets = 0;
assert.equal(ui.showGroomingWorkload, false, 'A groomer count alone does not create a workload row');
ui.groomingWorkload.state = 'At risk';
assert.equal(ui.showGroomingWorkload, true, 'Warnings stay visible even when estimates are unavailable');
ui.groomingWorkload = { state: 'On track', pets: { 7: { projected_completion: '2026-10-16T18:00:00+08:00' } } };
assert.equal(ui.showGroomingWorkload, true);

context.window.GroomingEstimates.factors = () => [
  { value: 'thick_coat', label: 'Thick coat' },
  { value: 'matted_tangled', label: 'Matted / tangled' },
];
const selectedPet = { ...pet, groomingEstimate: {
  preference: 'summer_cut', preferenceLabel: 'Summer Cut', factors: ['matted_tangled', 'thick_coat', 'matted_tangled'],
  minMinutes: 120, maxMinutes: 240,
} };
const snapshot = JSON.stringify(selectedPet);
assert.equal(JSON.stringify(ui.groomingSelectionLabels(selectedPet)), JSON.stringify(['Summer Cut', 'Matted / tangled', 'Thick coat']));
assert.equal(JSON.stringify(selectedPet), snapshot, 'Read-only labels never alter saved selections or duration');
assert.equal(ui.groomingSelectionLabels({}).length, 0);
assert.equal(ui.groomingSelectionLabels({ groomingEstimate: { preferenceLabel: '—', factors: ['unknown'] } }).length, 0);
assert.equal(ui.groomingPetIdentity({ ...pet, size: 'large', weight: '12 kg', furType: '—' }), 'Dog · Dachshund · Large · 12 kg');

const record = { ...booking, status: 'queued' };
for (const control of ['button', 'a', 'input', '[role="menu"]']) {
  ui.toggleGroomingCardFromPointer({ target: { closest: () => control } }, record);
  assert.equal(ui.isGroomingBookingExpanded(record), false, `${control} does not toggle disclosure`);
}
ui.toggleGroomingCardFromPointer({ target: { closest: () => null } }, record);
assert.equal(ui.isGroomingBookingExpanded(record), true);
ui.toggleGroomingCardFromPointer({ target: { closest: () => null } }, record);
assert.equal(ui.isGroomingBookingExpanded(record), false);

let restoredFocus = 0;
const event = { currentTarget: { focus() { restoredFocus++; } } };
ui.openGroomingMenu(record, event);
assert.equal(ui.groomingMenuBookingId, record.id);
ui.handleGroomingMenuKeydown({ key: 'Escape', preventDefault() {} });
assert.equal(ui.groomingMenuBookingId, null);
assert.equal(restoredFocus, 1);
ui.openGroomingMenu(record, event);
ui.toggleGroomingBooking(record);
assert.equal(ui.groomingMenuBookingId, null, 'Disclosure closes the secondary menu');
ui.openGroomingMenu(record, event);
ui.setTab('for-payment');
assert.equal(ui.groomingMenuBookingId, null, 'Changing stages dismisses any secondary menu');
console.log('Admin Grooming board regression tests passed.');
