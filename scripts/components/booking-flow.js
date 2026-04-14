import { initBookingCalendar } from "./booking-calendar.js";
import { initBookingFormAccessGuard } from "../services/booking-form-access-guard.js";

/**
 * Booking Flow Controller
 *
 * Initializes the booking page and controls the overall flow.
 * Step 1 schedule selection is handled by booking-calendar.js.
 *
 * The access guard runs first — if the user already has an active booking
 * within the last 24 hours, they are redirected back to the dashboard
 * before the calendar even loads.
 */

document.addEventListener("DOMContentLoaded", async () => {
  const blocked = initBookingFormAccessGuard({
    dashboardPath: "./dashboard.html",
  });

  if (blocked) return;

  await initBookingCalendar();
});
