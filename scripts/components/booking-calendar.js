import { getPhilippineHolidays } from "../services/holidays.js";

/**
 * Booking Calendar Component
 *
 * Purpose:
 * Handles Step 1 of the booking process:
 * - calendar display
 * - date selection
 * - time slot selection
 * - PH time validation
 * - holiday blocking
 * - 3-day booking window
 *
 * Important for backend developer:
 * Frontend validation is for UX only.
 * Backend must still validate:
 * - past date/time
 * - clinic hours
 * - holiday blocking
 * - actual slot availability / double booking
 */

// =========================
// CONFIGURATION
// =========================

// Clinic operating hours.
// 8 means 8:00 AM, 17 means 5:00 PM.
// Last selectable slot here is 4:00 PM - 5:00 PM.
const CLINIC_OPEN_HOUR = 8;
const CLINIC_CLOSE_HOUR = 17;

// 1-hour time blocks
const SLOT_DURATION_HOURS = 1;

// Booking window rule:
// User can only book within today + next 2 days = 3-day window total.
const MAX_BOOKING_DAYS_AHEAD = 3;

// =========================
// STATE
// =========================

const state = {
  currentMonth: null,
  selectedDateKey: null,
  selectedSlot: null,
  holidays: new Map(),
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
    if (part.type !== "literal") {
      result[part.type] = part.value;
    }
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
  return `${now.year}-${String(now.month).padStart(2, "0")}-${String(
    now.day,
  ).padStart(2, "0")}`;
}

function getCurrentMinutesInManila() {
  const now = getManilaNowParts();
  return now.hour * 60 + now.minute;
}

// =========================
// DATE HELPERS
// =========================

function toDateKey(year, monthIndex, day) {
  return `${year}-${String(monthIndex + 1).padStart(2, "0")}-${String(
    day,
  ).padStart(2, "0")}`;
}

function dateKeyToLocalDate(dateKey) {
  const [year, month, day] = dateKey.split("-").map(Number);
  return new Date(year, month - 1, day);
}

function formatLongDate(dateKey) {
  const date = dateKeyToLocalDate(dateKey);

  return new Intl.DateTimeFormat("en-PH", {
    weekday: "long",
    year: "numeric",
    month: "long",
    day: "numeric",
  }).format(date);
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

/**
 * 3-day booking window logic:
 * Allowed:
 * - today
 * - tomorrow
 * - day after tomorrow
 *
 * Blocked:
 * - anything beyond that
 */
function isBeyondBookingWindow(dateKey) {
  const todayParts = getManilaNowParts();
  const today = new Date(todayParts.year, todayParts.month - 1, todayParts.day);
  const target = dateKeyToLocalDate(dateKey);

  const diffMs = target - today;
  const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));

  return diffDays >= MAX_BOOKING_DAYS_AHEAD;
}

function isDateDisabled(dateKey) {
  return (
    isPastDate(dateKey) || isHoliday(dateKey) || isBeyondBookingWindow(dateKey)
  );
}

// =========================
// SLOT HELPERS
// =========================

function formatHour(hour24) {
  const period = hour24 >= 12 ? "PM" : "AM";
  const hour12 = hour24 % 12 === 0 ? 12 : hour24 % 12;
  return `${hour12}:00 ${period}`;
}

function buildSlotLabel(startHour, endHour) {
  return `${formatHour(startHour)} - ${formatHour(endHour)}`;
}

function getTimeSlots() {
  const slots = [];

  for (
    let startHour = CLINIC_OPEN_HOUR;
    startHour < CLINIC_CLOSE_HOUR;
    startHour += SLOT_DURATION_HOURS
  ) {
    const endHour = startHour + SLOT_DURATION_HOURS;

    slots.push({
      startHour,
      endHour,
      label: buildSlotLabel(startHour, endHour),
    });
  }

  return slots;
}

function isSlotDisabled(dateKey, slot) {
  if (!dateKey) return true;
  if (isDateDisabled(dateKey)) return true;

  // For today in PH time, block time slots that already started or passed
  if (isToday(dateKey)) {
    const currentMinutes = getCurrentMinutesInManila();
    const slotStartMinutes = slot.startHour * 60;

    if (currentMinutes >= slotStartMinutes) {
      return true;
    }
  }

  return false;
}

// =========================
// RENDER CALENDAR
// =========================

async function loadHolidaysForVisibleYear() {
  const year = state.currentMonth.getFullYear();
  state.holidays = await getPhilippineHolidays(year);

  // Clear invalid selected date if it became unavailable
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

  const baseClasses = "h-12 w-full rounded-xl text-sm font-semibold transition";

  const availableClasses =
    "bg-white text-slate-700 shadow-sm hover:bg-[#d8eafb]";

  const disabledClasses = "bg-slate-200 text-slate-400 cursor-not-allowed";

  const selectedClasses = "ring-2 ring-[#315b7e] bg-[#b9d7f1] text-[#1f3d58]";

  button.className = [
    baseClasses,
    disabled ? disabledClasses : availableClasses,
    selected ? selectedClasses : "",
  ].join(" ");

  if (holidayLabel) {
    button.title = holidayLabel;
  }

  if (disabled) {
    button.disabled = true;
  } else {
    button.addEventListener("click", () => {
      state.selectedDateKey = dateKey;
      state.selectedSlot = null;
      renderAll();
    });
  }

  return button;
}

function renderCalendarGrid() {
  const year = state.currentMonth.getFullYear();
  const monthIndex = state.currentMonth.getMonth();

  const firstDay = new Date(year, monthIndex, 1);
  const lastDay = new Date(year, monthIndex + 1, 0);

  const firstWeekday = firstDay.getDay(); // Sunday = 0
  const daysInMonth = lastDay.getDate();

  elements.calendarGrid.innerHTML = "";

  for (let i = 0; i < firstWeekday; i++) {
    elements.calendarGrid.appendChild(createEmptyCell());
  }

  for (let day = 1; day <= daysInMonth; day++) {
    const dateKey = toDateKey(year, monthIndex, day);
    const disabled = isDateDisabled(dateKey);
    const selected = state.selectedDateKey === dateKey;
    const holidayLabel = getHolidayLabel(dateKey);

    const button = createDateButton({
      day,
      dateKey,
      disabled,
      selected,
      holidayLabel,
    });

    elements.calendarGrid.appendChild(button);
  }
}

// =========================
// RENDER SLOTS
// =========================

function renderTimeSlots() {
  elements.timeSlots.innerHTML = "";

  const slots = getTimeSlots();

  slots.forEach((slot) => {
    const disabled = isSlotDisabled(state.selectedDateKey, slot);
    const selected =
      state.selectedSlot &&
      state.selectedSlot.startHour === slot.startHour &&
      state.selectedSlot.endHour === slot.endHour;

    const button = document.createElement("button");
    button.type = "button";
    button.textContent = slot.label;

    const baseClasses =
      "rounded-2xl border px-4 py-3 text-sm font-semibold transition";

    const availableClasses =
      "border-[#a8c8e6] bg-[#edf6fd] text-slate-700 hover:bg-[#dbeefe]";

    const disabledClasses =
      "border-slate-300 bg-slate-300 text-slate-500 cursor-not-allowed";

    const selectedClasses =
      "ring-2 ring-[#315b7e] border-[#315b7e] bg-[#315b7e] text-white";

    button.className = [
      baseClasses,
      disabled ? disabledClasses : availableClasses,
      selected ? selectedClasses : "",
    ].join(" ");
    button.setAttribute("aria-pressed", String(Boolean(selected)));

    if (disabled) {
      button.disabled = true;
    } else {
      button.addEventListener("click", () => {
        state.selectedSlot = slot;

        // Temporary frontend storage so next step can use selected schedule.
        // Backend must still revalidate everything on submit.
        sessionStorage.setItem(
          "bookingSchedule",
          JSON.stringify({
            date: state.selectedDateKey,
            time: state.selectedSlot.label,
            startHour: state.selectedSlot.startHour,
            endHour: state.selectedSlot.endHour,
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
    elements.selectedScheduleText.textContent =
      "Please select a date and time.";
    elements.nextStepBtn.disabled = true;
    elements.nextStepBtn.classList.add("opacity-50", "cursor-not-allowed");
    return;
  }

  elements.selectedScheduleText.textContent = `${formatLongDate(
    state.selectedDateKey,
  )} at ${state.selectedSlot.label}`;

  elements.nextStepBtn.disabled = false;
  elements.nextStepBtn.classList.remove("opacity-50", "cursor-not-allowed");
}

// =========================
// MONTH NAVIGATION
// =========================

function canGoToPreviousMonth() {
  const now = getManilaNowParts();
  const currentVisibleYear = state.currentMonth.getFullYear();
  const currentVisibleMonth = state.currentMonth.getMonth();

  return !(
    currentVisibleYear === now.year && currentVisibleMonth === now.month - 1
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
    renderAll();
  });

  elements.nextMonthBtn.addEventListener("click", async () => {
    state.currentMonth = new Date(
      state.currentMonth.getFullYear(),
      state.currentMonth.getMonth() + 1,
      1,
    );

    await loadHolidaysForVisibleYear();
    renderAll();
  });

  elements.nextStepBtn.addEventListener("click", () => {
    if (!state.selectedDateKey || !state.selectedSlot) return;

    // Replace this later with your real Step 2 page if needed
    window.location.href = "./booking-personal-details.html";
  });
}

// =========================
// MAIN RENDER
// =========================

function renderAll() {
  renderMonthHeader();
  renderCalendarGrid();
  renderTimeSlots();
  updateSelectedSchedule();
}

// =========================
// INIT
// =========================

export async function initBookingCalendar() {
  const now = getManilaNowParts();
  state.currentMonth = new Date(now.year, now.month - 1, 1);

  await loadHolidaysForVisibleYear();
  bindEvents();
  renderAll();
}
