// Input editing only; each field retains its existing validation and limits.
window.InventoryMoneyInput = (() => {
  const numericText = value => /^[0-9.]*$/.test(value);

  function handle(event) {
    const input = event.target;
    if (!input.matches?.('input[data-money-input]') || input.readOnly || input.disabled) return;

    if (event.type === 'keydown') {
      if (!event.ctrlKey && !event.metaKey && !event.altKey
        && event.key.length === 1 && !numericText(event.key)) event.preventDefault();
    } else if (event.type === 'beforeinput') {
      if (event.data && !numericText(event.data)) event.preventDefault();
    } else if (event.type === 'paste') {
      // Reject contaminated pastes without replacing the user's selection or
      // changing native number-input min/max/step behavior.
      if (!numericText(event.clipboardData.getData('text'))) event.preventDefault();
    } else if (event.type === 'input') {
      // Covers text-based range prices, autofill and composition input before
      // Alpine reads the value. Precision is still checked by ProductForm.
      const value = input.value;
      const clean = value.replace(/[^0-9.]/g, '');
      if (clean !== value) {
        const caret = input.selectionStart;
        input.value = clean;
        if (caret !== null) {
          const position = value.slice(0, caret).replace(/[^0-9.]/g, '').length;
          input.setSelectionRange(position, position);
        }
      }
    }
  }

  const root = document.querySelector('main[aria-label="Inventory"]');
  for (const type of ['keydown', 'beforeinput', 'paste', 'input']) root?.addEventListener(type, handle, true);
  return { handle };
})();
