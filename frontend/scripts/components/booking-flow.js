import { initBookingCalendar } from "./booking-calendar.js";
import { initBookingFormAccessGuard } from "../services/booking-form-access-guard.js";

/**
 * Booking Flow Controller
 *
 * Purpose:
 * Initializes the booking page and controls the overall flow.
 *
 * Note for backend developer:
 * Step 1 schedule selection is handled by booking-calendar.js.
 * This file only starts the booking page behavior.
 */

document.addEventListener("DOMContentLoaded", async () => {
  if (initBookingFormAccessGuard()) {
    return;
  }

  await initBookingCalendar();
});
