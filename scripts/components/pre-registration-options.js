import {
  clearClinicVisitDraft,
  requireCustomerSession,
} from "../services/clinic-visit-service.js";
import {
  getPreRegistrationAccess,
  setPreRegistrationLinkAccess,
} from "../services/booking-form-access-guard.js?v=20260807-ongoing-access";

if (requireCustomerSession("./sign-in.html")) {
  const groomingOption = document.getElementById("groomingOption");
  const clinicVisitOption = document.getElementById("clinicVisitOption");
  const accessMessage = document.getElementById("preRegistrationAccessMessage");

  clinicVisitOption?.addEventListener("click", () => {
    clearClinicVisitDraft();
  });

  try {
    const access = await getPreRegistrationAccess();
    setPreRegistrationLinkAccess(groomingOption, access.allowed, access.message);
    setPreRegistrationLinkAccess(clinicVisitOption, access.allowed, access.message);

    if (!access.allowed && accessMessage) {
      accessMessage.textContent = access.message;
      accessMessage.classList.remove("hidden");
    }
  } catch (error) {
    const unavailableMessage =
      "We could not verify pre-registration availability. Please refresh and try again.";
    setPreRegistrationLinkAccess(groomingOption, false, unavailableMessage);
    setPreRegistrationLinkAccess(clinicVisitOption, false, unavailableMessage);
    if (accessMessage) {
      accessMessage.textContent = unavailableMessage;
      accessMessage.classList.remove("hidden");
    }
    console.error("Failed to check pre-registration access:", error);
  }
}
