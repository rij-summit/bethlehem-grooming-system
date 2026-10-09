const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const dashboardPath = path.resolve(
  __dirname,
  "../scripts/components/client-dashboard.js",
);
const source = fs.readFileSync(dashboardPath, "utf8");
const marker = source.indexOf("Appointments, Grooming Tracker & History");
const start = source.indexOf("(function () {", marker);

assert.notEqual(marker, -1, "Customer dashboard section marker is missing.");
assert.notEqual(start, -1, "Customer dashboard component could not be isolated.");

const dashboardComponent = source.slice(start);

function createElementStore() {
  const elements = new Map();

  function element(id) {
    if (!elements.has(id)) {
      const classes = new Set();
      elements.set(id, {
        id,
        innerHTML: "<initial>Loading...</initial>",
        textContent: "",
        value: "",
        min: "",
        max: "",
        disabled: false,
        style: {},
        className: "",
        classList: {
          add(...names) { names.forEach((name) => classes.add(name)); },
          remove(...names) { names.forEach((name) => classes.delete(name)); },
          toggle(name, force) {
            const add = force === undefined ? !classes.has(name) : force;
            if (add) classes.add(name);
            else classes.delete(name);
            return add;
          },
          contains(name) { return classes.has(name); },
        },
        addEventListener() {},
        setCustomValidity() {},
        querySelectorAll() { return []; },
      });
    }

    return elements.get(id);
  }

  return { element };
}

async function runDashboard(getBookingHistory, { pets = [], capacity = {} } = {}) {
  const { element } = createElementStore();
  const consoleErrors = [];
  const context = {
    API: {
      readCustomerCache: () => null,
      loadCustomerData: (endpoint, load, render) => load().then(render),
      getUserPets: async () => ({ pets }),
      getGroomingCapacity: async () => capacity,
      getBookingHistory,
    },
    console: {
      error(...args) { consoleErrors.push(args); },
    },
    document: {
      getElementById: element,
      addEventListener(type, callback) {
        if (type === "DOMContentLoaded") callback();
      },
    },
    setInterval: () => 0,
    setTimeout: (callback) => {
      callback();
      return 0;
    },
    window: { AppClock: null, location: {} },
  };

  vm.createContext(context);
  vm.runInContext(dashboardComponent, context);
  await new Promise((resolve) => setImmediate(resolve));

  return { element, consoleErrors, refresh: context.window._refreshAppointments };
}

async function testCompletedHistoryDoesNotBreakEmptySchedule() {
  const { element, consoleErrors } = await runDashboard(async () => ({
    bookings: [],
    history_total: 1,
    history: [{
      booking_id: 90,
      booking_reference: "BAC-20260803-0003",
      booking_date: "2026-08-03",
      paid: true,
      pets: [{ pet_name: "Peter" }],
    }],
  }));

  const schedule = element("appointmentsList").innerHTML;
  const history = element("groomingHistory").innerHTML;

  assert.match(schedule, /No upcoming grooming scheduled/);
  assert.doesNotMatch(schedule, /Failed to load schedule/);
  assert.match(history, /Peter/);
  assert.doesNotMatch(history, /<initial>Loading/);
  assert.equal(consoleErrors.length, 0, "Rendering emitted an unexpected error.");
}

async function testApiFailureSettlesEveryDashboardPanel() {
  const { element } = await runDashboard(async () => {
    throw new Error("Simulated history failure");
  });

  assert.match(element("appointmentsList").innerHTML, /Failed to load schedule/);
  assert.match(element("groomingTracker").innerHTML, /Failed to load grooming status/);
  assert.match(element("groomingHistory").innerHTML, /Failed to load grooming history/);

  for (const id of ["appointmentsList", "groomingTracker", "groomingHistory"]) {
    assert.doesNotMatch(
      element(id).innerHTML,
      /<initial>Loading/,
      `${id} remained in its loading state.`,
    );
  }
  assert.equal(element("groomingTrackerMetadata").innerHTML, "");
}

async function testTrackerPaymentMetadata() {
  const scenarios = [
    { status: "checked_in", paid: true, label: "Pre-Paid" },
    { status: "in_progress", paid: false, label: null },
    { status: "released", paid: true, label: "Paid" },
    { status: "released", paid: true, label: "Pre-Paid", paidAt: "2026-09-15T08:00:00+08:00", finishedAt: "2026-09-15T10:00:00+08:00" },
    { status: "released", paid: true, label: "Paid", paidAt: "2026-09-15T11:00:00+08:00", finishedAt: "2026-09-15T10:00:00+08:00" },
  ];

  for (const scenario of scenarios) {
    const { element, consoleErrors } = await runDashboard(async () => ({
      bookings: [{
        booking_id: 1,
        booking_reference: "BAC-20260915-0001",
        booking_date: "2026-09-15",
        status: scenario.status,
        paid: scenario.paid,
        payment_summary: { paid_at: scenario.paidAt },
        grooming_finished_timestamp: scenario.finishedAt,
        pets: [{
          pet_name: "Peter",
          grooming_status: scenario.status,
        }],
      }],
      history: [],
    }));

    const metadata = element("groomingTrackerMetadata").innerHTML;
    assert.match(metadata, /Ref\. BAC-20260915-0001/);
    if (scenario.label) {
      const labelPosition = metadata.indexOf(">" + scenario.label + "<");
      assert.notEqual(labelPosition, -1, "The correct payment label is present.");
      assert.ok(labelPosition < metadata.indexOf("Ref."), "Payment is above the reference.");
    } else {
      assert.doesNotMatch(metadata, /Pre-Paid|>Paid</);
    }
    assert.doesNotMatch(element("groomingTracker").innerHTML, /Ref\.|#bell|We’ll notify/);
    assert.equal(consoleErrors.length, 0);
  }
}

async function testStateHierarchyAndLiveTransitions() {
  let bookings = [];
  const dashboard = await runDashboard(async () => ({ bookings, history: [] }));
  const hidden = (id) => dashboard.element(id).classList.contains("hidden");
  assert.equal(hidden("trackerSection"), false);
  assert.equal(hidden("upcomingSection"), true);
  assert.match(dashboard.element("groomingTracker").innerHTML, /No grooming in progress/);
  assert.match(dashboard.element("groomingTracker").innerHTML, /Your pet’s status will appear here after clinic check-in\./);
  assert.doesNotMatch(dashboard.element("groomingTracker").innerHTML, /<svg/);

  bookings = [{ booking_id: 10, status: "waiting_to_arrive", booking_date: "2026-10-04", pets: [{ pet_name: "Luna", species: "cat" }] }];
  await dashboard.refresh();
  assert.equal(hidden("trackerSection"), false);
  assert.equal(hidden("upcomingSection"), false);
  assert.match(dashboard.element("groomingTracker").innerHTML, /No grooming in progress/);
  assert.match(dashboard.element("appointmentsList").innerHTML, /reschedule-10/);

  bookings.push({ booking_id: 11, status: "in_progress", pets: [{ pet_name: "Bruno", grooming_status: "in_progress" }] });
  await dashboard.refresh();
  assert.equal(hidden("trackerSection"), false);
  assert.doesNotMatch(dashboard.element("groomingTracker").innerHTML, /No grooming in progress/);
  assert.match(dashboard.element("groomingStatusAnnouncement").textContent, /Bruno: Grooming in Progress/);
  bookings[1].status = "for_payment";
  await dashboard.refresh();
  assert.match(dashboard.element("groomingStatusAnnouncement").textContent, /Bruno: Ready for Pickup/);
  bookings = [];
  await dashboard.refresh();
  assert.equal(hidden("trackerSection"), false);
  assert.match(dashboard.element("groomingTracker").innerHTML, /No grooming in progress/);
  assert.equal(dashboard.element("groomingTrackerMetadata").innerHTML, "");
  assert.equal(dashboard.consoleErrors.length, 0);
}

async function testMultiPetProgressAndPickupReadiness() {
  const { element, consoleErrors } = await runDashboard(async () => ({
    bookings: [{
      booking_id: 1, status: "in_progress", booking_reference: "REF",
      pets: [
        { pet_name: "Bruno", grooming_status: "in_progress" },
        { pet_name: "Mochi", grooming_status: "checked_in" },
        { pet_name: "Luna", grooming_status: "grooming_finished", active_in_grooming: false },
        { pet_name: "Referred", clinic_referred: true },
      ],
    }], history: [],
  }));
  const tracker = element("groomingTracker").innerHTML;
  assert.match(tracker, /Bruno/);
  assert.match(tracker, /Mochi/);
  assert.match(tracker, /Grooming finished/);
  assert.doesNotMatch(tracker, /Referred|Being Groomed/);
  assert.doesNotMatch(tracker, /Luna is ready\. Please/);
  assert.match(element("groomingStatusAnnouncement").textContent, /Luna: Grooming finished/);
  assert.equal((tracker.match(/<ol /g) || []).length, 1, "Mixed statuses share one booking progress tracker and compact pet status rows.");
  assert.equal(consoleErrors.length, 0);
}

async function testPreviewsAndNearestSchedule() {
  const pets = [
    { pet_id: 1, pet_name: "Breed only", breed: "Maltese", birthdate: null },
    { pet_id: 2, pet_name: "No details", breed: null, birthdate: null, species: "cat" },
    { pet_id: 3, pet_name: "<Pet>", breed: "-", birthdate: "2022-01-01" },
    { pet_id: 4, pet_name: "Outside preview" },
  ];
  const booking = (id, date, time, status = "waiting_to_arrive") => ({
    booking_id: id, booking_date: date, status,
    time_window: { window_label: time },
    pets: [{ pet_name: "Pet " + id, services: [{ service_name: "Regular Grooming" }] }],
  });
  const { element, consoleErrors } = await runDashboard(async () => ({
    bookings: [
      booking(4, "2026-10-05", "8:00 AM - 9:00 AM"),
      booking(3, "2026-10-04", "1:00 PM - 2:00 PM"),
      booking(2, "2026-10-04", "9:00 AM - 10:00 AM", "waiting"),
      booking(1, "2026-10-04", "8:00 AM - 9:00 AM"),
    ],
    history: [booking(5, "2026-10-02", ""), booking(6, "2026-10-01", ""), booking(7, "2026-09-30", ""), booking(8, "2026-09-29", "")],
  }), { pets });
  const schedule = element("appointmentsList").innerHTML;
  assert.ok(schedule.indexOf("Pet 1") < schedule.indexOf("Pet 2"));
  assert.ok(schedule.indexOf("Pet 2") < schedule.indexOf("Pet 3"));
  assert.doesNotMatch(schedule, /Pet 4|reschedule-2|cancel-2/);
  assert.match(schedule, /Regular Grooming/);
  const preview = element("myPetsPreview").innerHTML;
  assert.match(preview, /Maltese/);
  assert.doesNotMatch(preview, /Maltese ·|Outside preview|<Pet>|· -|· —/);
  assert.match(preview, /&lt;Pet&gt;/);
  assert.match(preview, /#cat/);
  assert.match(element("groomingHistory").innerHTML, /Pet 7/);
  assert.doesNotMatch(element("groomingHistory").innerHTML, /Pet 8/);
  assert.equal(consoleErrors.length, 0);
}

async function testQueueStates() {
  for (const [used, label] of [[1, "Open"], [16, "Nearly Full"], [20, "Full"]]) {
    const { element } = await runDashboard(async () => ({ bookings: [], history: [] }), {
      capacity: { capacity: { max: 20, used, remaining: 20 - used }, queue: { active: 1 } },
    });
    assert.equal(element("groomingCapacityBadge").textContent, label);
    assert.equal(element("groomingQueueLoading").classList.contains("hidden"), true);
    assert.equal(element("groomingQueueDetails").classList.contains("flex"), true);
    assert.equal(element("groomingQueueSummary").textContent, "1 pet currently in the grooming queue");
    assert.match(element("groomingCapacityText").textContent, /Grooming pets on-site|Grooming capacity/);
    assert.doesNotMatch(element("groomingCapacityText").textContent, /Clinic|grooming slots|daily capacity/);
  }
}

async function testInitialAndReturningSessionGreetings() {
  const shell = source.slice(0, source.indexOf("// ── Customer Notification Bell"));
  async function greeting(cookie, userId = 7) {
    const { element } = createElementStore();
    const context = {
      API: { getMe: async () => ({ user: { user_id: userId, first_name: "Gerald", last_name: "Senining" } }) },
      console,
      document: { cookie, getElementById: element, body: { classList: { toggle() {}, contains() {} } }, addEventListener() {} },
      window: {
        matchMedia: () => ({ matches: false, addEventListener() {} }),
        requestAnimationFrame: (callback) => callback(),
        requestIdleCallback: (callback) => callback(),
        addEventListener() {},
        location: { href: "http://127.0.0.1:8000/pages/client/dashboard.html" },
      },
    };
    element("clientSidebar").querySelectorAll = () => [];
    for (const id of ["clientSidebarToggle", "dashboardGreeting"]) element(id).setAttribute = () => {};
    vm.createContext(context);
    vm.runInContext(shell, context);
    await new Promise((resolve) => setImmediate(resolve));
    return element("dashboardGreeting").textContent;
  }
  const initialCookie = "other=value; bethlehem_customer_initial_session_7=1";
  assert.equal(await greeting(initialCookie), "Welcome, Gerald");
  assert.equal(await greeting(initialCookie), "Welcome, Gerald", "Reloads and new tabs in the initial session stay new.");
  assert.equal(await greeting(""), "Welcome back, Gerald", "A later browser session welcomes the customer back.");
  assert.equal(await greeting(initialCookie, 8), "Welcome back, Gerald", "The marker belongs only to the registered customer.");
}

function testExpiredRegistrationsHaveNeutralHistoryLabels() {
  const historySource = fs.readFileSync(path.resolve(__dirname, '../pages/client/grooming-history.html'), 'utf8');
  const helpers = historySource.slice(historySource.indexOf('function escapeHistoryHtml'), historySource.indexOf('function closeHistoryModal'));
  const context = vm.createContext({ Intl, Date, document: { getElementById: () => null } });
  vm.runInContext(helpers, context);
  for (const status of ['expired', 'no_show']) {
    const booking = { status, number_of_pets: 1, pets: [] };
    assert.equal(context.groomingOutcome(status)[0], 'Expired');
    assert.match(context.buildHistoryCard(booking, null), />Expired<\/span>/);
    assert.match(context.buildHistoryModalContent(booking, null), />Expired<\/span>/);
    assert.doesNotMatch(context.groomingOutcome(status)[1], /amber|red|rose/);
  }
  assert.equal(context.groomingOutcome('archived')[0], 'Completed');
}

(async () => {
  testExpiredRegistrationsHaveNeutralHistoryLabels();
  await testInitialAndReturningSessionGreetings();
  await testStateHierarchyAndLiveTransitions();
  await testMultiPetProgressAndPickupReadiness();
  await testPreviewsAndNearestSchedule();
  await testQueueStates();
  await testCompletedHistoryDoesNotBreakEmptySchedule();
  await testApiFailureSettlesEveryDashboardPanel();
  await testTrackerPaymentMetadata();
  console.log("Customer dashboard history regression tests passed.");
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
