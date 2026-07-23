import {
  readClinicVisitDraft,
  requireCustomerSession,
  updateClinicVisitDraft,
  validateClinicVisitReason,
} from "../services/clinic-visit-service.js";

const elements = {
  form: document.getElementById("clinicVisitReasonForm"),
  reason: document.getElementById("clinicVisitReason"),
  error: document.getElementById("clinicVisitReasonError"),
  count: document.getElementById("clinicVisitReasonCount"),
  petName: document.getElementById("reasonPetName"),
  petDetails: document.getElementById("reasonPetDetails"),
};

function showError(message) {
  elements.error.textContent = message;
  elements.error.classList.toggle("hidden", !message);
  elements.reason.setAttribute("aria-invalid", String(Boolean(message)));
  elements.reason.classList.toggle("border-red-400", Boolean(message));
}

function syncReasonState({ validate = false } = {}) {
  elements.count.textContent = `${elements.reason.value.length} / 1000`;
  showError(validate ? validateClinicVisitReason(elements.reason.value) : "");
}

function init() {
  if (!requireCustomerSession("./sign-in.html")) {
    return;
  }

  const draft = readClinicVisitDraft();

  if (!draft.appointmentDate || !draft.windowId || !draft.windowLabel) {
    window.location.replace("./clinic-visit-date.html");
    return;
  }

  if (!draft.pet?.id) {
    window.location.replace("./booking-pet-details.html?flow=clinic");
    return;
  }

  elements.petName.textContent = draft.pet.petName;
  elements.petDetails.textContent = [draft.pet.petType, draft.pet.breed]
    .filter(Boolean)
    .join(" · ");
  elements.reason.value = draft.chiefComplaint || "";
  syncReasonState();

  elements.reason.addEventListener("input", () => {
    syncReasonState({ validate: elements.reason.value.length > 0 });
  });

  elements.form.addEventListener("submit", (event) => {
    event.preventDefault();
    const error = validateClinicVisitReason(elements.reason.value);
    showError(error);

    if (error) {
      elements.reason.focus();
      return;
    }

    updateClinicVisitDraft({
      chiefComplaint: elements.reason.value.trim(),
    });
    window.location.href = "./clinic-visit-summary.html";
  });
}

init();
