export function createFixedOptionCombobox({
  root,
  input,
  listbox,
  toggleButton,
  options,
  placeholder,
  displaySelectedLabel = false,
}) {
  if (!root || !input || !listbox || !toggleButton || !Array.isArray(options)) {
    throw new Error("Fixed option combobox configuration is incomplete.");
  }

  let currentOptions = [...options];
  let selectedValue = "";
  let isOpen = false;
  let isDisabled = input.disabled || toggleButton.disabled;

  function buildOption(option) {
    const button = document.createElement("button");
    button.type = "button";
    button.className =
      "fixed-option-combobox-option block w-full px-4 py-2.5 text-left text-sm text-slate-700 transition hover:bg-[#edf5fc] focus:bg-[#edf5fc] focus:outline-none";
    button.setAttribute("role", "option");
    button.setAttribute("aria-selected", String(selectedValue === option.value));
    button.dataset.optionValue = option.value;
    button.textContent = option.label;
    return button;
  }

  function renderOptions() {
    listbox.innerHTML = "";
    currentOptions.forEach((option) => listbox.appendChild(buildOption(option)));
  }

  function setOpen(nextOpen) {
    if (isDisabled || currentOptions.length === 0) {
      nextOpen = false;
    }

    isOpen = Boolean(nextOpen);
    listbox.classList.toggle("hidden", !isOpen);
    input.setAttribute("aria-expanded", String(isOpen));
    toggleButton.setAttribute("aria-expanded", String(isOpen));

    if (isOpen) {
      renderOptions();
    }
  }

  function selectValue(value) {
    if (isDisabled || !currentOptions.some((option) => option.value === value)) {
      return;
    }

    setValue(value);
    input.focus();
    input.dispatchEvent(new Event("change", { bubbles: true }));
  }

  function setValue(value) {
    if (value === "") {
      selectedValue = "";
      input.value = "";
      delete input.dataset.optionValue;
      input.placeholder = placeholder;
      input.setAttribute("aria-invalid", "false");
      setOpen(false);
      return true;
    }

    const selectedOption = currentOptions.find((option) => option.value === value);

    if (!selectedOption) {
      return false;
    }

    selectedValue = selectedOption.value;
    input.value = displaySelectedLabel ? selectedOption.label : selectedOption.value;
    input.dataset.optionValue = selectedOption.value;
    input.setAttribute("aria-invalid", "false");
    setOpen(false);
    return true;
  }

  function reset() {
    setValue("");
  }

  function setOptions(nextOptions, { preserveValue = true } = {}) {
    if (!Array.isArray(nextOptions)) {
      throw new Error("Fixed option combobox options must be an array.");
    }

    const previousValue = selectedValue;
    currentOptions = [...nextOptions];

    if (!preserveValue || !currentOptions.some(({ value }) => value === previousValue)) {
      setValue("");
    }

    if (isOpen) {
      renderOptions();
    }
  }

  function setDisabled(nextDisabled) {
    isDisabled = Boolean(nextDisabled);
    input.disabled = isDisabled;
    toggleButton.disabled = isDisabled;
    input.setAttribute("aria-disabled", String(isDisabled));
    toggleButton.setAttribute("aria-disabled", String(isDisabled));

    if (isDisabled) {
      setOpen(false);
    }
  }

  function getValue() {
    return selectedValue;
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
  setDisabled(isDisabled);
  reset();

  return { getValue, reset, setDisabled, setOptions, setValue };
}
