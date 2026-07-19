import {
  MIXED_BREED,
  OTHER_BREED,
  UNKNOWN_BREED,
  breedCoatCatalogueReady,
  getMatchingBreeds,
  hasLetter,
} from "./breed-coat-catalogue.js";

export function createBreedCombobox({
  root,
  input,
  listbox,
  toggleButton,
  errorElement,
  getPetType = () => "",
}) {
  if (!root || !input || !listbox || !toggleButton) {
    throw new Error("Breed combobox elements are incomplete.");
  }

  let isCustomEntry = false;
  let isOpen = false;
  let shouldShowValidation = false;

  function getValidationMessage() {
    if (isCustomEntry && !hasLetter(input.value.trim())) {
      return "Please enter a breed with at least one letter.";
    }

    return "";
  }

  function showValidation(message = getValidationMessage()) {
    shouldShowValidation = true;
    const hasError = Boolean(message);

    input.setAttribute("aria-invalid", String(hasError));
    input.classList.toggle("border-red-400", hasError);
    input.classList.toggle("focus:border-red-500", hasError);
    input.classList.toggle("focus:ring-red-500/20", hasError);

    if (errorElement) {
      errorElement.textContent = message;
      errorElement.classList.toggle("hidden", !hasError);
    }

    return !hasError;
  }

  function clearValidation() {
    shouldShowValidation = false;
    input.setAttribute("aria-invalid", "false");
    input.classList.remove(
      "border-red-400",
      "focus:border-red-500",
      "focus:ring-red-500/20",
    );

    if (errorElement) {
      errorElement.textContent = "";
      errorElement.classList.add("hidden");
    }
  }

  function notifyValueChange() {
    input.dispatchEvent(new Event("change", { bubbles: true }));
  }

  function buildOption(value, label = value) {
    const button = document.createElement("button");
    button.type = "button";
    button.className =
      "breed-combobox-option block w-full px-4 py-2.5 text-left text-sm text-slate-700 transition hover:bg-[#edf5fc] focus:bg-[#edf5fc] focus:outline-none";
    button.setAttribute("role", "option");
    button.dataset.breedValue = value;
    button.textContent = label;
    return button;
  }

  function addSectionLabel(text) {
    const label = document.createElement("p");
    label.className =
      "px-4 pb-1 pt-3 text-xs font-semibold uppercase tracking-wide text-slate-400";
    label.textContent = text;
    listbox.appendChild(label);
  }

  function renderOptions() {
    listbox.innerHTML = "";

    if (!isCustomEntry) {
      listbox.appendChild(buildOption(MIXED_BREED));
      listbox.appendChild(buildOption(UNKNOWN_BREED));
      listbox.appendChild(buildOption(OTHER_BREED));
      return;
    }

    const query = input.value.trim();

    if (hasLetter(query)) {
      const matches = getMatchingBreeds(query, getPetType(), 4);
      addSectionLabel("Breed suggestions");

      if (matches.length > 0) {
        matches.forEach((breed) => listbox.appendChild(buildOption(breed)));
      } else {
        const emptyState = document.createElement("p");
        emptyState.className = "px-4 py-2.5 text-sm text-slate-400";
        emptyState.textContent = "No matching breeds";
        listbox.appendChild(emptyState);
      }
    }

    addSectionLabel("Other options");
    listbox.appendChild(buildOption(MIXED_BREED));
    listbox.appendChild(buildOption(UNKNOWN_BREED));
  }

  function setOpen(nextOpen) {
    isOpen = nextOpen;
    listbox.classList.toggle("hidden", !isOpen);
    input.setAttribute("aria-expanded", String(isOpen));
    toggleButton.setAttribute("aria-expanded", String(isOpen));

    if (isOpen) {
      renderOptions();
    }
  }

  function selectValue(value) {
    if (value === OTHER_BREED) {
      isCustomEntry = true;
      input.readOnly = false;
      input.value = "";
      input.placeholder = "Type a breed";
      clearValidation();
      setOpen(true);
      input.focus();
      notifyValueChange();
      return;
    }

    input.value = value;

    if (value === MIXED_BREED || value === UNKNOWN_BREED) {
      isCustomEntry = false;
      input.readOnly = true;
      input.placeholder = "Select breed";
    }

    clearValidation();
    setOpen(false);
    input.focus();
    notifyValueChange();
  }

  function reset() {
    isCustomEntry = false;
    input.value = "";
    input.readOnly = true;
    input.placeholder = "Select breed";
    clearValidation();
    setOpen(false);
  }

  function focusOption(offset) {
    const options = [...listbox.querySelectorAll(".breed-combobox-option")];

    if (options.length === 0) {
      return;
    }

    const activeIndex = options.indexOf(document.activeElement);
    const nextIndex = activeIndex < 0
      ? (offset > 0 ? 0 : options.length - 1)
      : (activeIndex + offset + options.length) % options.length;

    options[nextIndex].focus();
  }

  root.addEventListener("click", (event) => {
    const option = event.target.closest("[data-breed-value]");

    if (option) {
      selectValue(option.dataset.breedValue);
    }
  });

  input.addEventListener("click", () => setOpen(true));
  input.addEventListener("input", () => {
    if (!isCustomEntry) {
      return;
    }

    setOpen(true);

    if (shouldShowValidation) {
      showValidation();
    }
  });

  input.addEventListener("keydown", (event) => {
    if (input.readOnly && (event.key === "Enter" || event.key === " ")) {
      event.preventDefault();
      setOpen(true);
      focusOption(1);
      return;
    }

    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
      event.preventDefault();
      setOpen(true);
      focusOption(event.key === "ArrowDown" ? 1 : -1);
      return;
    }

    if (event.key === "Escape") {
      setOpen(false);
    }
  });

  listbox.addEventListener("keydown", (event) => {
    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
      event.preventDefault();
      focusOption(event.key === "ArrowDown" ? 1 : -1);
      return;
    }

    if (event.key === "Escape") {
      setOpen(false);
      input.focus();
    }
  });

  toggleButton.addEventListener("click", () => {
    setOpen(!isOpen);
    input.focus();
  });

  document.addEventListener("pointerdown", (event) => {
    if (!root.contains(event.target)) {
      setOpen(false);
    }
  });

  input.form?.addEventListener("reset", () => window.setTimeout(reset));
  breedCoatCatalogueReady.then(() => {
    if (isOpen) {
      renderOptions();
    }
  });
  reset();

  return {
    getValidationMessage,
    reset,
    showValidation,
  };
}
