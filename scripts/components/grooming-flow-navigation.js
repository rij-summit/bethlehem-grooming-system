export function isGroomingSinglePage() {
  return document.body?.dataset.groomingFlow === "single-page";
}

export function goToGroomingStep(step) {
  document.dispatchEvent(new CustomEvent("grooming:step", { detail: { step } }));
}
