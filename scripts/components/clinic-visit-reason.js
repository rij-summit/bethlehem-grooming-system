import { mountClinicVisitReasonFields } from "./clinic-visit-reason-fields.js";
import {
  readClinicVisitDraft,
  requireCustomerSession,
  updateClinicVisitDraft,
} from "../services/clinic-visit-service.js";

function init() {
  if (!requireCustomerSession("./sign-in.html")) return;

  const draft = readClinicVisitDraft();
  if (!draft.appointmentDate || !draft.windowId || !draft.windowLabel) {
    window.location.replace("./clinic-visit-date.html");
    return;
  }
  if (!draft.pet?.id) {
    window.location.replace("./booking-pet-details.html?flow=clinic");
    return;
  }

  const fields = mountClinicVisitReasonFields(
    document.getElementById("clinicVisitReasonFields"),
    draft.pet.petName,
    draft.commonConcerns,
    draft.chiefComplaint,
  );
  document.getElementById("clinicVisitReasonForm").addEventListener("submit", (event) => {
    event.preventDefault();
    if (!fields.validate()) return;
    updateClinicVisitDraft({
      commonConcerns: fields.concerns(),
      chiefComplaint: fields.details.value.trim(),
    });
    window.location.href = "./clinic-visit-summary.html";
  });
}

init();
