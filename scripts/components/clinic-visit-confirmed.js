import {
  CLINIC_VISIT_CONFIRMATION_KEY,
  formatClinicVisitDate,
  requireCustomerSession,
} from "../services/clinic-visit-service.js";

function readConfirmation() {
  try {
    const value = sessionStorage.getItem(CLINIC_VISIT_CONFIRMATION_KEY);
    return value ? JSON.parse(value) : null;
  } catch {
    return null;
  }
}

function setText(id, value) {
  const element = document.getElementById(id);
  if (element) element.textContent = value || "Not specified";
}

if (requireCustomerSession("./sign-in.html")) {
  const appointment = readConfirmation();

  if (!appointment?.appointment_reference) {
    window.location.replace("./dashboard.html");
  } else {
    setText("confirmedReference", appointment.appointment_reference);
    setText("confirmedDate", formatClinicVisitDate(appointment.appointment_date));
    setText("confirmedTime", appointment.time_window?.window_label);
    setText("confirmedPet", appointment.pet?.name);
    setText(
      "confirmedPetDetails",
      [appointment.pet?.species, appointment.pet?.breed].filter(Boolean).join(" · "),
    );
    setText("confirmedReason", appointment.chief_complaint);
  }
}
