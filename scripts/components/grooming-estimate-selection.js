import { escapeHtml } from "../services/booking-draft-service.js";
import { estimatePetGrooming, getGroomingPreferences } from "../services/grooming-service.js?v=grooming-pricing-20261006";

export function renderEstimateSelection(pet, selection, staff = false) {
  const choices = getGroomingPreferences(selection.servicePackage);
  const estimate = estimatePetGrooming(selection, pet);
  const chip = (option, role, checked, radio) => `<label class="grooming-estimate-chip">
    <input type="${radio ? "radio" : "checkbox"}" ${radio ? `name="grooming_preference_${escapeHtml(pet.id)}" required` : ""}
      data-role="${role}" data-pet-id="${escapeHtml(pet.id)}" value="${escapeHtml(option.value)}" ${checked ? "checked" : ""}>
    <span>${escapeHtml(option.label)}</span>
  </label>`;
  return `<div class="grooming-estimate-selection">
    ${choices.length ? `<fieldset><legend>Grooming preference</legend><div class="grooming-estimate-chips">
      ${choices.map((option) => chip(option, "grooming-preference", selection.groomingPreference === option.value, true)).join("")}
    </div>${selection.groomingPreference === "custom_hairstyle" ? '<p class="grooming-estimate-hint">Describe the requested style in the notes below.</p>' : ""}</fieldset>` : ""}
    ${staff ? `<fieldset><legend>Estimate factors <span>(optional)</span></legend><div class="grooming-estimate-chips">
      ${globalThis.GroomingEstimates.factors().map((option) => chip(option, "estimate-factor", selection.estimateFactors?.includes(option.value), false)).join("")}
    </div></fieldset>` : ""}
    <p class="grooming-estimate-time">Estimated grooming time <strong>${escapeHtml(estimate?.formatted || (choices.length ? "Select a grooming preference" : "Select a service"))}</strong></p>
  </div>`;
}

export function renderEstimateReview(estimate) {
  if (!estimate) return "";
  return `<div class="grooming-estimate-selection">
    ${estimate.preferenceLabel ? `<p>Grooming preference: <strong>${escapeHtml(estimate.preferenceLabel)}</strong></p>` : ""}
    <p class="grooming-estimate-time">Estimated grooming time <strong>${escapeHtml(estimate.formatted)}</strong></p>
  </div>`;
}

export function updateEstimateSelection(selection, target) {
  if (target.dataset.role === "grooming-preference") selection.groomingPreference = target.value;
  if (target.dataset.role === "estimate-factor") {
    const factors = new Set(selection.estimateFactors || []);
    target.checked ? factors.add(target.value) : factors.delete(target.value);
    selection.estimateFactors = [...factors];
  }
}

export function renderSelectionsPreservingFocus(container, markup, estimates) {
  const active = document.activeElement;
  const focus = container.contains(active) ? { role: active.dataset.role, petId: active.dataset.petId, value: active.value } : null;
  container.innerHTML = markup;
  if (focus?.role) {
    [...container.querySelectorAll("input[data-role]")].find((input) => input.dataset.role === focus.role && input.dataset.petId === focus.petId && input.value === focus.value)?.focus({ preventScroll: true });
  }
  let announcement = container.nextElementSibling;
  if (!announcement?.hasAttribute("data-estimate-announcement")) {
    announcement = document.createElement("p");
    announcement.className = "sr-only";
    announcement.setAttribute("data-estimate-announcement", "");
    announcement.setAttribute("aria-live", "polite");
    announcement.setAttribute("aria-atomic", "true");
    container.after(announcement);
  }
  announcement.textContent = estimates;
}
