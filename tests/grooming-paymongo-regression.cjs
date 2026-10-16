const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const timers = new Map();
let nextTimer = 0;
const calls = [];
let status = { success: true, online_payment: { id: 1, status: 'pending', amount: '500.00', flow: 'early', checkout_url: 'https://checkout.paymongo.com/cs_fixture' } };
const context = {
  console, Date, Intl,
  window: { AppClock: { todayKey: () => '2026-10-16' } },
  setTimeout: (callback) => { timers.set(++nextTimer, callback); return nextTimer; },
  clearTimeout: (id) => timers.delete(id),
  API: {
    createGroomingOnlinePayment: async (id, payload) => { calls.push({ id, payload }); return status; },
    groomingOnlinePaymentStatus: async () => status,
    cancelGroomingOnlinePayment: async () => ({ success: true, online_payment: { ...status.online_payment, status: 'cancelled' } }),
    payNow: async (id, payload) => { calls.push({ id, payload }); return { final_price: 500, amount_paid: 600, change: 100, payment_method: 'cash', booking_status: 'checked_in' }; },
  },
};
vm.createContext(context);
for (const file of ['cash-payment.js', 'admin-dashboard.js']) {
  vm.runInContext(fs.readFileSync(`${__dirname}/../scripts/components/${file}`, 'utf8'), context);
}

async function main() {
  const { applyGroomingCatalogue } = await import('../scripts/services/grooming-service.js');
  const catalogue = JSON.parse(fs.readFileSync(`${__dirname}/grooming-catalogue-fixture.json`, 'utf8').replace(/^\uFEFF/, '')).data;
  context.applyPaymentCatalogue(applyGroomingCatalogue(catalogue));
  const ui = context.adminDashboard();
  ui.$nextTick = () => {};
  ui.loadAdminBookings = ui.loadNotifications = async () => {};
  ui.setTab = () => {};
  const booking = { id: 1, ownerName: 'Test Customer', bookingReference: 'TEST-ONLINE',
    pets: [{ bookingPetId: 1, petName: 'Mochi', species: 'Cat', size: 'small' }],
    services: [{ bookingServiceId: 1, bookingPetId: 1, slug: 'cat_full_grooming', priceAtBooking: '500.00' }] };
  const prepare = (method) => {
    ui.paymentModal = { ...ui.paymentModal, open: true, booking, isEarlyPayment: true, busy: false, error: '',
      petBreakdown: ui.buildPaymentBreakdown(booking, { lockFixedPrices: true }), products: [],
      paymentMethod: method, amountPaid: '', onlinePayment: null };
  };
  prepare('cash');
  assert.equal(ui.canSubmitPayment, false, 'Cash still requires tendered money');
  ui.paymentModal.amountPaid = '600';
  assert.equal(ui.canSubmitPayment, true);
  await ui.submitPayment();
  assert.equal(ui.receiptModal.paymentMethod, 'Cash');
  assert.equal(ui.receiptModal.change, 100);
  assert.equal(calls.at(-1).payload.amount_paid, 600);

  prepare('online');
  assert.equal(ui.canSubmitPayment, true, 'Online payment never requires amount tendered');
  await ui.submitPayment();
  assert.equal(calls.at(-1).payload.payment_method, 'online');
  assert.equal('amount_paid' in calls.at(-1).payload, false);
  assert.equal(ui.paymentModal.onlinePayment.status, 'pending');
  assert.equal(ui.canSubmitPayment, false, 'Active checkout prevents another payment');
  assert.equal(timers.size, 1);
  await ui.cancelOnlinePayment();
  assert.equal(ui.paymentModal.onlinePayment, null);
  assert.equal(timers.size, 0);

  await ui.submitPayment();
  ui.closePaymentModal();
  assert.equal(timers.size, 0, 'Closing the modal stops polling');
  assert.equal(ui.paymentModal.open, false);

  prepare('online');
  await ui.submitPayment();
  status = { online_payment: { ...status.online_payment, status: 'paid' }, payment: {
    final_price: '500.00', amount_paid: null, change: null, payment_method: 'online', payment_method_label: 'Online Payment',
    product_addons: [], paid_at: 'Oct 16, 2026 1:00 PM', booking_status: 'checked_in',
  } };
  const callback = [...timers.values()][0];
  timers.clear();
  await callback();
  assert.equal(ui.paymentModal.open, false);
  assert.equal(ui.receiptModal.open, true);
  assert.equal(ui.receiptModal.paymentMethod, 'Online Payment');
  assert.equal(ui.receiptModal.amountPaid, null);
  assert.equal(ui.receiptModal.change, null);
  assert.equal(timers.size, 0, 'Paid webhook status stops polling');
  prepare('cash');
  await ui.applyOnlinePaymentStatus(status);
  assert.equal(ui.receiptModal.paymentMethod, 'Online Payment', 'Reopening a completed checkout restores the recorded method');
  assert.equal(ui.receiptModal.amountPaid, null);
  assert.equal(ui.receiptModal.change, null);

  status = { online_payment: { ...status.online_payment, status: 'pending' } };
  prepare('online');
  await ui.submitPayment();
  let resolveStalePoll;
  context.API.groomingOnlinePaymentStatus = () => new Promise((resolve) => { resolveStalePoll = resolve; });
  const staleCallback = [...timers.values()][0];
  timers.clear();
  const inFlightPoll = staleCallback();
  await ui.cancelOnlinePayment();
  resolveStalePoll(status);
  await inFlightPoll;
  assert.equal(ui.paymentModal.onlinePayment, null, 'An old pending poll cannot revive a cancelled checkout');
  assert.equal(timers.size, 0);

  const html = fs.readFileSync(`${__dirname}/../pages/admin/appointments.html`, 'utf8');
  const modal = html.slice(html.indexOf('<!-- Payment Modal -->'), html.indexOf('<!-- Grooming product barcode scanner -->'));
  assert.doesNotMatch(modal, /QR Ph|Coming soon|iframe/i);
  assert.match(modal, /target="_blank" rel="noopener noreferrer"/);
  assert.match(modal, /Create Online Payment/);
  assert.match(modal, /Confirm Cash Payment/);
  assert.match(modal, /:disabled="paymentModal.busy \|\| !!paymentModal.onlinePayment"/);
  console.log('Grooming Cash and PayMongo UI regression tests passed.');
}
main().catch((error) => { console.error(error); process.exitCode = 1; });
