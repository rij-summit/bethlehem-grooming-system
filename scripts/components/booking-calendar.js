import { getPhilippineHolidays } from "../services/holidays.js";

/**
 * Booking Calendar Component
 *
 * Handles Step 1 of the booking process:
 * - Calendar display and date selection
 * - Time slot selection fetched from GET /api/timeslots?date=
 * - PH time validation and holiday blocking
 * - 3-day booking window
 *
 * Stores selected schedule in sessionStorage as:
 * { date, time, startHour, endHour, window_id }
 * window_id is required by the booking submission payload.
 */

const MAX_BOOKING_DAYS_AHEAD = 3;

// =========================
// STATE
// =========================

const state = {
  currentMonth: null,
  selectedDateKey: null,
  selectedSlot: null,   // { window_id, window_label, start_time, end_time, is_full }
  holidays: new Map(),
  timeslots: [],        // API response for the selected date
  dayFull: false,       // true when the selected date has hit 20-booking capacity
  clinicStatus: {
    stoppedToday: false,
    blockedDates: [],   // [{ id, start_date, end_date, reason }]
  },
};

// =========================
// ELEMENTS
// =========================

const elements = {
  monthLabel: document.getElementById("monthLabel"),
  prevMonthBtn: document.getElementById("prevMonthBtn"),
  nextMonthBtn: document.getElementById("nextMonthBtn"),
  calendarGrid: document.getElementById("calendarGrid"),
  timeSlots: document.getElementById("timeSlots"),
  selectedScheduleText: document.getElementById("selectedScheduleText"),
  nextStepBtn: document.getElementById("nextStepBtn"),
  clinicNotice: document.getElementById("clinicNotice"),
};

// =========================
// TIMEZONE HELPERS
// =========================

function getManilaNowParts() {
  const parts = new Intl.DateTimeFormat("en-CA", {
    timeZone: "Asia/Manila",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).formatToParts(new Date());

  const result = {};
  for (const part of parts) {
    if (part.type !== "literal") result[part.type] = part.value;
  }

  return {
    year: Number(result.year),
    month: Number(result.month),
    day: Number(result.day),
    hour: Number(result.hour),
    minute: Number(result.minute),
  };
}

function getTodayKeyInManila() {
  const now = getManilaNowParts();
  return `${now.year}-${String(now.month).padStart(2, "0")}-${String(now.day).padStart(2, "0")}`;
}

function getCurrentMinutesInManila() {
  const now = getManilaNowParts();
  return now.hour * 60 + now.minute;
}

// =========================
// DATE HELPERS
// =========================

function toDateKey(year, monthIndex, day) {
  return `${year}-${String(monthIndex + 1).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
}

function dateKeyToLocalDate(dateKey) {
  const [year, month, day] = dateKey.split("-").map(Number);
  return new Date(year, month - 1, day);
}

function formatLongDate(dateKey) {
  return new Intl.DateTimeFormat("en-PH", {
    weekday: "long",
    year: "numeric",
    month: "long",
    day: "numeric",
  }).format(dateKeyToLocalDate(dateKey));
}

function isPastDate(dateKey) {
  return dateKey < getTodayKeyInManila();
}

function isToday(dateKey) {
  return dateKey === getTodayKeyInManila();
}

function isHoliday(dateKey) {
  return state.holidays.has(dateKey);
}

function getHolidayLabel(dateKey) {
  const holiday = state.holidays.get(dateKey);
  if (!holiday) return "";
  return holiday.localName || holiday.name || "Holiday";
}

function isBeyondBookingWindow(dateKey) {
  const todayParts = getManilaNowParts();
  const today = new Date(todayParts.year, todayParts.month - 1, todayParts.day);
  const target = dateKeyToLocalDate(dateKey);
  const diffDays = Math.floor((target - today) / (1000 * 60 * 60 * 24));
  return diffDays >= MAX_BOOKING_DAYS_AHEAD;
}

// =========================
// CLINIC CLOSURE HELPERS
// =========================

/**
 * Returns the clinic block object if this date is blocked by the admin,
 * or null if the date is open.
 */
function getClinicBlock(dateKey) {
  const today = getTodayKeyInManila();

  if (state.clinicStatus.stoppedToday && dateKey === today) {
    return { type: "stop_today", start: today, end: today, reason: null };
  }

  for (const block of state.clinicStatus.blockedDates) {
    if (dateKey >= block.start_date && dateKey <= block.end_date) {
      return { type: "blocked_date", start: block.start_date, end: block.end_date, reason: block.reason };
    }
  }

  return null;
}

/**
 * Returns the customer-facing message for a clinic-blocked date.
 */
function getClinicBlockMessage(dateKey) {
  const block = getClinicBlock(dateKey);
  if (!block) return null;

  if (block.type === "stop_today") {
    return "We're taking a short break — check back tomorrow!";
  }

  // If the admin wrote a reason, show it
  if (block.reason) return block.reason;

  if (block.start === block.end) {
    return "We're closed on this date.";
  }

  const fmt = (dk) =>
    new Intl.DateTimeFormat("en-PH", { month: "short", day: "numeric" }).format(
      dateKeyToLocalDate(dk),
    );
  return `We're on a short break ${fmt(block.start)}–${fmt(block.end)}.`;
}

/**
 * Updates the notice banner above the calendar when the clinic is stopped today.
 */
function updateClinicNotice() {
  if (!elements.clinicNotice) return;

  if (state.clinicStatus.stoppedToday) {
    elements.clinicNotice.textContent =
      "The clinic is not accepting bookings for today. Please select a different date.";
    elements.clinicNotice.classList.remove("hidden");
  } else {
    elements.clinicNotice.classList.add("hidden");
  }
}

function isDateDisabled(dateKey) {
  return (
    isPastDate(dateKey) ||
    isHoliday(dateKey) ||
    isBeyondBookingWindow(dateKey) ||
    getClinicBlock(dateKey) !== null
  );
}

// =========================
// SLOT HELPERS
// =========================

/**
 * Parse "HH:MM:SS" time string and return total minutes since midnight.
 */
function timeStringToMinutes(timeStr) {
  const [h, m] = timeStr.split(":").map(Number);
  return h * 60 + m;
}

/**
 * Check if a slot from the API should be disabled for the selected date.
 * For today, disable any slot whose start time has already passed in Manila time.
 */
function isSlotDisabled(slot) {
  if (slot.is_full) return true;

  if (isToday(state.selectedDateKey)) {
    const currentMinutes = getCurrentMinutesInManila();
    const slotStartMinutes = timeStringToMinutes(slot.start_time);
    if (currentMinutes >= slotStartMinutes) return true;
  }

  return false;
}

// =========================
// FETCH CLINIC STATUS
// =========================

async function loadClinicStatus() {
  try {
    const data = await API.getClinicStatus();
    state.clinicStatus = {
      stoppedToday: Boolean(data.stopped_today),
      blockedDates: Array.isArray(data.blocked_dates) ? data.blocked_dates : [],
    };
  } catch {
    // Non-fatal — fall back to no restrictions
    state.clinicStatus = { stoppedToday: false, blockedDates: [] };
  }
}

// =========================
// FETCH TIMESLOTS FROM API
// =========================

async function fetchTimeslots(dateKey) {
  elements.timeSlots.innerHTML =
    `<p class="col-span-full text-sm text-slate-400">Loading available times...</p>`;

  try {
    const data = await API.getTimeslots(dateKey);
    state.timeslots = data.windows || [];
    state.dayFull   = data.day_full === true;
  } catch {
    state.timeslots = [];
    state.dayFull   = false;
    elements.timeSlots.innerHTML =
      `<p class="col-span-full text-sm text-red-500">Could not load time slots. Please try again.</p>`;
    return false;
  }

  return true;
}

// =========================
// RENDER CALENDAR
// =========================

async function loadHolidaysForVisibleYear() {
  const year = state.currentMonth.getFullYear();
  state.holidays = await getPhilippineHolidays(year);

  if (state.selectedDateKey && isDateDisabled(state.selectedDateKey)) {
    state.selectedDateKey = null;
    state.selectedSlot = null;
  }
}

function renderMonthHeader() {
  elements.monthLabel.textContent = new Intl.DateTimeFormat("en-PH", {
    month: "long",
    year: "numeric",
  }).format(state.currentMonth);
}

function createEmptyCell() {
  const emptyCell = document.createElement("div");
  emptyCell.className = "h-12";
  return emptyCell;
}

function createDateButton({ day, dateKey, disabled, selected, holidayLabel }) {
  const button = document.createElement("button");
  button.type = "button";
  button.textContent = day;

  const clinicBlock = getClinicBlock(dateKey);

  let disabledClass = "bg-slate-200 text-slate-400 cursor-not-allowed";
  if (disabled && clinicBlock) {
    // Amber tint for clinic closures so customers can tell them apart from past/full dates
    disabledClass = "bg-[#fef9c3] text-[#854d0e] cursor-not-allowed";
  }

  button.className = [
    "h-12 w-full rounded-xl text-sm font-semibold transition",
    disabled
      ? disabledClass
      : "bg-white text-slate-700 shadow-sm hover:bg-[#d8eafb]",
    selected ? "ring-2 ring-[#315b7e] bg-[#b9d7f1] text-[#1f3d58]" : "",
  ].join(" ");

  // Clinic closure message takes priority over the holiday label
  const tooltip = clinicBlock
    ? getClinicBlockMessage(dateKey)
    : holidayLabel;
  if (tooltip) button.title = tooltip;

  if (disabled) {
    button.disabled = true;
  } else {
    button.addEventListener("click", async () => {
      state.selectedDateKey = dateKey;
      state.selectedSlot    = null;
      state.dayFull         = false;
      renderCalendarGrid();
      updateSelectedSchedule();
      const ok = await fetchTimeslots(dateKey);
      if (ok) renderTimeSlots();
    });
  }

  return button;
}

function renderCalendarGrid() {
  const year = state.currentMonth.getFullYear();
  const monthIndex = state.currentMonth.getMonth();
  const firstWeekday = new Date(year, monthIndex, 1).getDay();
  const daysInMonth = new Date(year, monthIndex + 1, 0).getDate();

  elements.calendarGrid.innerHTML = "";

  for (let i = 0; i < firstWeekday; i++) {
    elements.calendarGrid.appendChild(createEmptyCell());
  }

  for (let day = 1; day <= daysInMonth; day++) {
    const dateKey = toDateKey(year, monthIndex, day);
    elements.calendarGrid.appendChild(
      createDateButton({
        day,
        dateKey,
        disabled: isDateDisabled(dateKey),
        selected: state.selectedDateKey === dateKey,
        holidayLabel: getHolidayLabel(dateKey),
      }),
    );
  }
}

// =========================
// RENDER SLOTS (from API)
// =========================

function renderTimeSlots() {
  elements.timeSlots.innerHTML = "";

  if (state.dayFull) {
    elements.timeSlots.innerHTML = `
      <div class="col-span-full rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-center">
        <p class="font-semibold text-red-600">This date is fully booked (20/20).</p>
        <p class="mt-1 text-sm text-red-500">Please choose a different date or time slot.</p>
      </div>`;
    updateSelectedSchedule();
    return;
  }

  if (state.timeslots.length === 0) {
    elements.timeSlots.innerHTML =
      `<p class="col-span-full text-sm text-slate-400">No time slots available for this date.</p>`;
    return;
  }

  state.timeslots.forEach((slot) => {
    const disabled = isSlotDisabled(slot);
    const selected = state.selectedSlot?.window_id === slot.window_id;

    const button = document.createElement("button");
    button.type = "button";

    // Show label + remaining slots
    const remainingText = slot.is_full
      ? "Full"
      : `${slot.remaining} slot${slot.remaining !== 1 ? "s" : ""} left`;

    button.innerHTML = `
      <span class="block font-semibold">${slot.window_label}</span>
      <span class="block text-xs mt-0.5 ${slot.is_full ? "text-red-400" : "text-slate-400"}">${remainingText}</span>
      ${slot.recommended ? `<span class="block text-xs mt-0.5 text-emerald-500 font-semibold">Recommended</span>` : ""}
    `;

    button.className = [
      "rounded-2xl border px-4 py-3 text-sm transition text-left",
      disabled
        ? "border-slate-300 bg-slate-200 text-slate-400 cursor-not-allowed"
        : "border-[#a8c8e6] bg-[#edf6fd] text-slate-700 hover:bg-[#dbeefe]",
      selected ? "ring-2 ring-[#315b7e] border-[#315b7e] bg-[#315b7e] text-white" : "",
    ].join(" ");

    button.setAttribute("aria-pressed", String(selected));
    if (disabled) {
      button.disabled = true;
    } else {
      button.addEventListener("click", () => {
        state.selectedSlot = slot;

        // Store schedule including window_id for API submission
        sessionStorage.setItem(
          "bookingSchedule",
          JSON.stringify({
            date: state.selectedDateKey,
            time: slot.window_label,
            window_id: slot.window_id,
            start_time: slot.start_time,
            end_time: slot.end_time,
          }),
        );

        renderTimeSlots();
        updateSelectedSchedule();
      });
    }

    elements.timeSlots.appendChild(button);
  });
}

function updateSelectedSchedule() {
  if (!state.selectedDateKey || !state.selectedSlot) {
    elements.selectedScheduleText.textContent = "Please select a date and time.";
    elements.nextStepBtn.disabled = true;
    elements.nextStepBtn.classList.add("opacity-50", "cursor-not-allowed");
    return;
  }

  elements.selectedScheduleText.textContent =
    `${formatLongDate(state.selectedDateKey)} at ${state.selectedSlot.window_label}`;

  elements.nextStepBtn.disabled = false;
  elements.nextStepBtn.classList.remove("opacity-50", "cursor-not-allowed");
}

// =========================
// MONTH NAVIGATION
// =========================

function canGoToPreviousMonth() {
  const now = getManilaNowParts();
  return !(
    state.currentMonth.getFullYear() === now.year &&
    state.currentMonth.getMonth() === now.month - 1
  );
}

function bindEvents() {
  elements.prevMonthBtn.addEventListener("click", async () => {
    if (!canGoToPreviousMonth()) return;
    state.currentMonth = new Date(
      state.currentMonth.getFullYear(),
      state.currentMonth.getMonth() - 1,
      1,
    );
    await loadHolidaysForVisibleYear();
    renderCalendarGrid();
    renderMonthHeader();
  });

  elements.nextMonthBtn.addEventListener("click", async () => {
    state.currentMonth = new Date(
      state.currentMonth.getFullYear(),
      state.currentMonth.getMonth() + 1,
      1,
    );
    await loadHolidaysForVisibleYear();
    renderCalendarGrid();
    renderMonthHeader();
  });

  elements.nextStepBtn.addEventListener("click", () => {
    if (!state.selectedDateKey || !state.selectedSlot) return;
    window.location.href = "./booking-pet-details.html";
  });
}

// =========================
// INIT
// =========================

export async function initBookingCalendar() {
  const now = getManilaNowParts();
  state.currentMonth = new Date(now.year, now.month - 1, 1);

  // Load both holidays and clinic closures in parallel
  await Promise.all([loadHolidaysForVisibleYear(), loadClinicStatus()]);

  // Clear any previously selected date if it is now clinic-blocked
  if (state.selectedDateKey && isDateDisabled(state.selectedDateKey)) {
    state.selectedDateKey = null;
    state.selectedSlot    = null;
  }

  bindEvents();
  renderMonthHeader();
  renderCalendarGrid();
  updateClinicNotice();
  updateSelectedSchedule();
}
