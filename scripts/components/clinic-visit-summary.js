import { getClinicVisitSummaryMarkup } from "./clinic-visit-summary-card.js";
import {
  CLINIC_VISIT_CONFIRMATION_KEY,
  CLINIC_VISIT_DRAFT_KEY,
  CLINIC_VISIT_PETS_KEY,
  formatClinicVisitDate,
  readClinicVisitDraft,
  requireCustomerSession,
  validateClinicVisitReason,
} from "../services/clinic-visit-service.js";

const elements = {
  details: document.getElementById("clinicVisitSummaryDetails"),
  status: document.getElementById("clinicVisitSubmitStatus"),
  submit: document.getElementById("submitClinicVisitBtn"),
};

function showError(message) {
  elements.status.textContent = message;
  elements.status.className =
    "mb-6 rounded-2xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700";
}

async function submitClinicVisit(draft) {
  elements.submit.disabled = true;
  elements.submit.textContent = "Submitting...";

  try {
    const response = await API.submitClinicPreRegistration({
      appointment_date: draft.appointmentDate,
      window_id: Number(draft.windowId),
      pet_id: Number(draft.pet.id),
      chief_complaint: draft.chiefComplaint,
    });

    sessionStorage.setItem(
      CLINIC_VISIT_CONFIRMATION_KEY,
      JSON.stringify(response.appointment),
    );
    sessionStorage.removeItem(CLINIC_VISIT_DRAFT_KEY);
    sessionStorage.removeItem(CLINIC_VISIT_PETS_KEY);
    window.location.href = "./clinic-visit-confirmed.html";
  } catch (error) {
    showError(error.message || "The clinic visit could not be submitted. Please try again.");
    elements.submit.disabled = false;
    elements.submit.textContent = "Submit Pre-registration";
  }
}

function init() {
  if (!requireCustomerSession("./sign-in.html")) {
    return;
  }

  const draft = readClinicVisitDraft();

  if (
    !draft.appointmentDate ||
    !draft.windowId ||
    !draft.windowLabel ||
    !draft.pet?.id
  ) {
    window.location.replace("./clinic-visit-date.html");
    return;
  }

  if (validateClinicVisitReason(draft.chiefComplaint)) {
    window.location.replace("./clinic-visit-reason.html");
    return;
  }

  elements.details.innerHTML = getClinicVisitSummaryMarkup({
    visitType: "Clinic Visit Pre-registration",
    dateLabel: formatClinicVisitDate(draft.appointmentDate),
    timeLabel: draft.windowLabel,
    pet: draft.pet,
    reason: draft.chiefComplaint,
  });
  elements.submit.addEventListener("click", () => submitClinicVisit(draft));
}

init();
