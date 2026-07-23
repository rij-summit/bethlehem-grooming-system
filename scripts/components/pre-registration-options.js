import {
  clearClinicVisitDraft,
  requireCustomerSession,
} from "../services/clinic-visit-service.js";

if (requireCustomerSession("./sign-in.html")) {
  document.getElementById("clinicVisitOption")?.addEventListener("click", () => {
    clearClinicVisitDraft();
  });
}
