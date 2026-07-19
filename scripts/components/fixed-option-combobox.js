export function createFixedOptionCombobox({
  root,
  input,
  listbox,
  toggleButton,
  options,
  placeholder,
}) {
  if (!root || !input || !listbox || !toggleButton || !Array.isArray(options)) {
    throw new Error("Fixed option combobox configuration is incomplete.");
  }

  let isOpen = false;

  function buildOption(option) {
    const button = document.createElement("button");
    button.type = "button";
    button.className =
      "fixed-option-combobox-option block w-full px-4 py-2.5 text-left text-sm text-slate-700 transition hover:bg-[#edf5fc] focus:bg-[#edf5fc] focus:outline-none";
    button.setAttribute("role", "option");
    button.setAttribute("aria-selected", String(input.value === option.value));
    button.dataset.optionValue = option.value;
    button.textContent = option.label;
    return button;
  }

  function renderOptions() {
    listbox.innerHTML = "";
    options.forEach((option) => listbox.appendChild(buildOption(option)));
  }

  function setOpen(nextOpen) {
    isOpen = Boolean(nextOpen);
    listbox.classList.toggle("hidden", !isOpen);
    input.setAttribute("aria-expanded", String(isOpen));
    toggleButton.setAttribute("aria-expanded", String(isOpen));

    if (isOpen) {
      renderOptions();
    }
  }

  function selectValue(value) {
    if (!options.some((option) => option.value === value)) {
      return;
    }

    input.value = value;
    input.setAttribute("aria-invalid", "false");
    setOpen(false);
    input.focus();
    input.dispatchEvent(new Event("change", { bubbles: true }));
  }

  function reset() {
    input.value = "";
    input.placeholder = placeholder;
    input.setAttribute("aria-invalid", "false");
    setOpen(false);
  }

  function focusOption(offset) {
    const optionElements = [...listbox.querySelectorAll(".fixed-option-combobox-option")];

    if (optionElements.length === 0) {
      return;
    }

    const activeIndex = optionElements.indexOf(document.activeElement);
    const nextIndex = activeIndex < 0
      ? (offset > 0 ? 0 : optionElements.length - 1)
      : (activeIndex + offset + optionElements.length) % optionElements.length;

    optionElements[nextIndex].focus();
  }

  root.addEventListener("click", (event) => {
    const option = event.target.closest("[data-option-value]");

    if (option) {
      selectValue(option.dataset.optionValue);
    }
  });

  input.addEventListener("click", () => setOpen(true));
  input.addEventListener("keydown", (event) => {
    if (event.key === "Enter" || event.key === " ") {
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
  reset();

  return { reset };
}
