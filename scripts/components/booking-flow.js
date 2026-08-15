import { initBookingCalendar } from "./booking-calendar.js";
import { initBookingFormAccessGuard } from "../services/booking-form-access-guard.js?v=20260807-ongoing-access";

/**
 * Booking Flow Controller
 *
 * Initializes the booking page and controls the overall flow.
 * Step 1 schedule selection is handled by booking-calendar.js.
 *
 * The access guard runs first. If the customer has an ongoing Grooming or
 * Clinic registration, they are redirected before the calendar loads.
 */

document.addEventListener("DOMContentLoaded", async () => {
  const blocked = await initBookingFormAccessGuard({
    dashboardPath: "./dashboard.html",
  });

  if (blocked) return;

  await initBookingCalendar();
});
