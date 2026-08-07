import { initBookingCalendar } from "./booking-calendar.js";
import {
  CLINIC_VISIT_DRAFT_KEY,
  requireCustomerSession,
  updateClinicVisitDraft,
} from "../services/clinic-visit-service.js";
import { initBookingFormAccessGuard } from "../services/booking-form-access-guard.js?v=20260807-ongoing-access";

document.addEventListener("DOMContentLoaded", async () => {
  if (!requireCustomerSession("./sign-in.html")) {
    return;
  }

  const blocked = await initBookingFormAccessGuard({
    dashboardPath: "./dashboard.html",
  });
  if (blocked) return;

  await initBookingCalendar({
    service: "clinic",
    dateOnly: false,
    storageKey: CLINIC_VISIT_DRAFT_KEY,
    dateField: "appointmentDate",
    nextPath: "./booking-pet-details.html?flow=clinic",
    fetchTimeslots: (date) => API.getClinicTimeslots(date),
    onDateSelected: (appointmentDate) => {
      updateClinicVisitDraft({
        appointmentDate,
        windowId: null,
        windowLabel: "",
        startTime: "",
        endTime: "",
        scheduleText: "",
      });
    },
    saveSelection: (selection) => {
      updateClinicVisitDraft({
        appointmentDate: selection.date,
        windowId: Number(selection.window_id),
        windowLabel: selection.time,
        startTime: selection.start_time,
        endTime: selection.end_time,
        scheduleText: selection.scheduleText,
      });
    },
  });
});
