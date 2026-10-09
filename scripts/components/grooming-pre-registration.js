import { initBookingCalendar, refreshBookingCalendar } from "./booking-calendar.js?v=grooming-workload-20261009";
import { initBookingPetStep, refreshBookingPetStep } from "./booking-pet-step.js";
import { initBookingServicesStep, refreshBookingServicesStep } from "./booking-services-step.js?v=grooming-pricing-20261006";
import { initBookingReviewStep, refreshBookingReviewStep, refreshBookingWorkloadForecast } from "./booking-review-step.js?v=grooming-workload-20261009";
import { initBookingConsentStep } from "./booking-consent-step.js?v=grooming-workload-20261009";
import { initBookingConfirmedStep } from "./booking-confirmed-step.js";
import { initBookingFormAccessGuard } from "../services/booking-form-access-guard.js?v=customer-cache-20261005-v1";

const steps = ["schedule", "pets", "services", "review", "consent", "confirmed"];
const initialized = new Set();
let currentStep = null;

function stored(key) {
  try {
    return JSON.parse(sessionStorage.getItem(key) || "null");
  } catch {
    return null;
  }
}

function availableStep(requested) {
  const schedule = stored("bookingSchedule");
  const pets = stored("bookingPets");
  const services = stored("bookingStep3");
  const review = stored("bookingStep4Review");
  if (requested === "confirmed" && stored("bookingConfirmation")) return requested;
  if (requested === "schedule" || !schedule?.date || !schedule?.window_id) return "schedule";
  if (requested === "pets" || !Array.isArray(pets) || !pets.length) return "pets";
  if (requested === "services" || !services?.petSelections?.length) return "services";
  if (requested === "review" || !review?.pets?.length) return "review";
  return requested === "consent" ? "consent" : "schedule";
}

function stepUrl(step) {
  const url = new URL(window.location.href);
  if (step === "schedule") url.searchParams.delete("step");
  else url.searchParams.set("step", step);
  return url;
}

async function enterStep(step) {
  if (step === "schedule") {
    if (initialized.has(step)) await refreshBookingCalendar();
    else await initBookingCalendar({ onNext: () => navigate("pets") });
  } else if (step === "pets") {
    if (initialized.has(step)) await refreshBookingPetStep();
    else await initBookingPetStep();
  } else if (step === "services") {
    if (initialized.has(step)) refreshBookingServicesStep();
    else initBookingServicesStep();
  } else if (step === "review") {
    if (initialized.has(step)) refreshBookingReviewStep();
    else initBookingReviewStep();
  } else if (step === "consent") {
    if (!initialized.has(step)) initBookingConsentStep();
  } else if (step === "confirmed") {
    if (!initialized.has(step)) initBookingConfirmedStep();
  }
  initialized.add(step);
}

async function navigate(requested, historyAction = "push") {
  if (!steps.includes(requested)) requested = "schedule";
  if (currentStep === "confirmed" && requested !== "confirmed") requested = "confirmed";
  const step = availableStep(requested);
  if (step === currentStep) {
    if (historyAction === "replace") window.history.replaceState({ step }, "", stepUrl(step));
    return;
  }

  for (const section of document.querySelectorAll("[data-grooming-step]")) {
    const visible = section.dataset.groomingStep === step;
    section.hidden = !visible;
    section.classList.toggle("hidden", !visible);
  }
  currentStep = step;
  document.body.classList.toggle("bg-[#f6fafd]", step === "schedule" || step === "pets");
  document.body.classList.toggle("bg-slate-50", step !== "schedule" && step !== "pets");

  if (historyAction === "push") window.history.pushState({ step }, "", stepUrl(step));
  if (historyAction === "replace") window.history.replaceState({ step }, "", stepUrl(step));

  window.scrollTo(0, 0);
  await enterStep(step);
}

document.addEventListener("DOMContentLoaded", async () => {
  const requested = new URLSearchParams(window.location.search).get("step") || "schedule";
  if (requested !== "confirmed" || !stored("bookingConfirmation")) {
    const blocked = await initBookingFormAccessGuard({ dashboardPath: "./dashboard.html" });
    if (blocked) return;
  }

  document.addEventListener("grooming:step", (event) => navigate(event.detail.step));
  document.addEventListener("click", (event) => {
    const link = event.target.closest("a[data-grooming-target]");
    if (!link) return;
    event.preventDefault();
    navigate(link.dataset.groomingTarget);
  });
  window.addEventListener("popstate", () => {
    navigate(new URLSearchParams(window.location.search).get("step") || "schedule", "replace");
  });
  await navigate(requested, "replace");
  let refreshingForecast = false;
  window.setInterval(async () => {
    if (document.hidden || refreshingForecast) return;
    refreshingForecast = true;
    try {
      if (currentStep === "schedule") await refreshBookingCalendar();
      else if (currentStep === "review") await refreshBookingWorkloadForecast();
    } finally { refreshingForecast = false; }
  }, 30000);
});
