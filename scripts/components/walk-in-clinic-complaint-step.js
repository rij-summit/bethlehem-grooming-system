import { renderWalkInClinicConsentStep } from "./walk-in-clinic-consent-step.js";

const WALK_IN_CLINIC_COMPLAINT_KEY = "walkInClinicComplaint";

const state = {
  pet: null,
};

let elements = {};

function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function getMarkup(pet) {
  const petName = escapeHtml(pet?.petName || "");
  const petType = escapeHtml(pet?.petType || "");
  const petBreed = escapeHtml(pet?.breed || "Not specified");

  return `
    <main class="mx-auto max-w-6xl px-4 py-8 md:px-6 lg:px-8">
      <section class="mb-6">
        <button
          id="clinicComplaintBackTopBtn"
          type="button"
          class="inline-flex items-center gap-2 text-sm font-medium text-slate-500 hover:text-slate-700"
        >
          &larr; Back to Pet Information
        </button>

        <div class="mt-4">
          <h1 class="text-3xl font-bold text-[#2f4b66]">Chief Complaint</h1>
        </div>

        <div class="mt-6">
          <div class="mb-2 flex items-center justify-between text-xs font-medium text-slate-500">
            <span>Step 3 of 4</span>
            <span>75% Complete</span>
          </div>
          <div class="h-2 w-full rounded-full bg-slate-200">
            <div class="h-2 rounded-full bg-[#315b7e]" style="width: 75%"></div>
          </div>
        </div>
      </section>

      <section class="rounded-3xl bg-[#cfe3f6] p-6 shadow-lg md:p-8">
        <div class="mx-auto max-w-5xl">
          <div class="mb-6 rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Patient</p>
            <p class="mt-2 text-sm font-semibold text-[#2f4b66]">${petName}</p>
            <p class="mt-1 text-sm text-slate-500">${petType}${petBreed !== "Not specified" ? " · " + petBreed : ""}</p>
          </div>

          <section class="rounded-3xl bg-white/90 p-5 shadow-sm md:p-6">
            <h2 class="mb-4 text-2xl font-bold text-[#2f4b66]">Reason for Visit</h2>

            <form id="clinicComplaintForm" class="space-y-5" novalidate>
              <div>
                <label for="chiefComplaint" class="mb-2 block text-sm font-medium text-slate-700">
                  Chief Complaint <span class="text-red-500">*</span>
                </label>
                <textarea
                  id="chiefComplaint"
                  name="chief_complaint"
                  rows="5"
                  maxlength="1000"
                  placeholder="Describe the pet's symptoms or the reason for this clinic visit (e.g. vomiting for 2 days, limping on right hind leg, routine check-up)."
                  class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none transition focus:border-[#315b7e] focus:ring-2 focus:ring-[#315b7e]/20"
                  required
                ></textarea>
                <p id="chiefComplaintError" class="mt-2 hidden text-sm text-red-600"></p>
              </div>

              <div
                id="clinicComplaintMessage"
                class="hidden rounded-2xl border px-4 py-3 text-sm"
                role="status"
                aria-live="polite"
              ></div>

              <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <button
                  id="clinicComplaintBackBtn"
                  type="button"
                  class="inline-flex items-center justify-center rounded-2xl border border-[#315b7e] px-5 py-3 text-sm font-semibold text-[#315b7e] transition hover:bg-[#edf5fc]"
                >
                  Back
                </button>

                <button
                  id="clinicComplaintNextBtn"
                  type="submit"
                  class="inline-flex items-center justify-center gap-2 rounded-2xl bg-[#315b7e] px-6 py-3 text-sm font-semibold text-white transition hover:bg-[#274864]"
                >
                  Next Step
                </button>
              </div>
            </form>
          </section>
        </div>
      </section>
    </main>
  `;
}

function refreshElements() {
  elements = {
    form: document.getElementById("clinicComplaintForm"),
    chiefComplaint: document.getElementById("chiefComplaint"),
    chiefComplaintError: document.getElementById("chiefComplaintError"),
    message: document.getElementById("clinicComplaintMessage"),
    nextBtn: document.getElementById("clinicComplaintNextBtn"),
    backButtons: [
      document.getElementById("clinicComplaintBackTopBtn"),
      document.getElementById("clinicComplaintBackBtn"),
    ].filter(Boolean),
  };
}

function validate(complaint) {
  if (!complaint.trim()) {
    return "Chief complaint is required.";
  }
  if (complaint.trim().length < 5) {
    return "Please provide more detail about the reason for the visit.";
  }
  return "";
}

function showFieldError(message) {
  if (message) {
    elements.chiefComplaintError.textContent = message;
    elements.chiefComplaintError.className = "mt-2 text-sm text-red-600";
  } else {
    elements.chiefComplaintError.textContent = "";
    elements.chiefComplaintError.className = "mt-2 hidden text-sm text-red-600";
  }
}

function handleBack() {
  window.location.href = "./walk-in-pet-details.html";
}

function handleSubmit(event) {
  event.preventDefault();

  const complaint = elements.chiefComplaint.value;
  const error = validate(complaint);

  if (error) {
    showFieldError(error);
    return;
  }

  showFieldError("");

  sessionStorage.setItem(
    WALK_IN_CLINIC_COMPLAINT_KEY,
    JSON.stringify({ chiefComplaint: complaint.trim(), pet: state.pet }),
  );

  window.history.pushState(null, "", "./walk-in-clinic-consent.html");
  renderWalkInClinicConsentStep({
    pet: state.pet,
    chiefComplaint: complaint.trim(),
    onBack: () => {
      window.history.pushState(null, "", "./walk-in-clinic-complaint.html");
      renderWalkInClinicComplaintStep({ pet: state.pet });
    },
  });
}

function bindEvents() {
  elements.form.addEventListener("submit", handleSubmit);
  elements.chiefComplaint.addEventListener("input", () => {
    const error = validate(elements.chiefComplaint.value);
    showFieldError(error);
  });
  elements.backButtons.forEach((btn) => btn.addEventListener("click", handleBack));
}

function restoreDraft() {
  try {
    const saved = JSON.parse(sessionStorage.getItem(WALK_IN_CLINIC_COMPLAINT_KEY) || "{}");
    if (saved.chiefComplaint) {
      elements.chiefComplaint.value = saved.chiefComplaint;
    }
  } catch {
    // ignore
  }
}

export function renderWalkInClinicComplaintStep({ pet = null } = {}) {
  state.pet = pet;

  document.title = "Walk-in Clinic — Chief Complaint";
  document.body.className = "min-h-screen bg-[#f6fafd] text-slate-800";
  document.body.innerHTML = getMarkup(pet);

  refreshElements();
  restoreDraft();
  bindEvents();

  if (window.lucide) {
    window.lucide.createIcons();
  }
}

document.addEventListener("DOMContentLoaded", () => {
  if (!document.getElementById("clinicComplaintForm")) {
    return;
  }

  const token = API.getAdminToken?.();
  const role = API.getUserRole?.();
  if (!token || (role !== "admin" && role !== "staff")) {
    window.location.href = "../client/sign-in.html";
    return;
  }

  refreshElements();
  restoreDraft();
  bindEvents();

  if (window.lucide) {
    window.lucide.createIcons();
  }
});
