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
        classList: { add() {}, remove() {}, toggle() {} },
        addEventListener() {},
        setCustomValidity() {},
        querySelectorAll() { return []; },
      });
    }

    return elements.get(id);
  }

  return { element };
}

async function runDashboard(getBookingHistory) {
  const { element } = createElementStore();
  const consoleErrors = [];
  const context = {
    API: {
      getUserPets: async () => ({ pets: [] }),
      getGroomingCapacity: async () => ({ capacity: {}, queue: {} }),
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

  return { element, consoleErrors };
}

async function testStoppedReviewHistoryDoesNotBreakEmptySchedule() {
  const { element, consoleErrors } = await runDashboard(async () => ({
    bookings: [],
    history_total: 1,
    history: [{
      booking_id: 90,
      booking_reference: "BAC-20260803-0003",
      booking_date: "2026-08-03",
      paid: true,
      pets: [{ pet_name: "Peter" }],
      payment_summary: {
        pets: [{
          payment_kind: "stopped_reviewed",
          pet_name: "Peter <script>",
          review_decision_label: "Partial charge",
          final_pet_charge: "100.00",
          customer_explanation: "Reviewed <safely>",
        }],
      },
    }],
  }));

  const schedule = element("appointmentsList").innerHTML;
  const history = element("groomingHistory").innerHTML;

  assert.match(schedule, /No upcoming schedule\./);
  assert.doesNotMatch(schedule, /Failed to load schedule/);
  assert.match(history, /Payment Review Completed/);
  assert.match(history, /Peter &lt;script&gt;/);
  assert.match(history, /Reviewed &lt;safely&gt;/);
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
}

(async () => {
  await testStoppedReviewHistoryDoesNotBreakEmptySchedule();
  await testApiFailureSettlesEveryDashboardPanel();
  console.log("Customer dashboard history regression tests passed.");
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
