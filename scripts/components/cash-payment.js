// Shared cash rules for Grooming and retail checkout. Keep maximumFor in sync
// with App\Support\PaymentAmountLimit and the storage ceiling with PaymentLimitExceededException.
window.CashPayment = (() => {
  function maximumFor(totalDue) {
    const total = Number(totalDue);
    return Number.isFinite(total) && total > 0 ? Math.floor(total / 1000) * 1000 + 2000 : 0;
  }

  function error(value, totalDue) {
    const raw = String(value ?? "").trim();
    if (!raw) return "Amount paid is required.";
    const paid = Number(raw);
    if (!Number.isFinite(paid)) return "The amount paid field must be a number.";
    if (!/^-?(?:\d+(?:\.\d{1,2})?|\.\d{1,2})$/.test(raw))
      return "The amount paid field must have between 0 and 2 decimal places.";
    if (paid < 0) return "The amount paid field must be at least 0.";
    if (paid > 999999) return "The amount paid exceeds the maximum allowed amount of ₱999,999 (6 digits).";
    if (paid > maximumFor(totalDue))
      return `Amount paid cannot exceed ₱${maximumFor(totalDue).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}.`;
    return Math.round(paid * 100) < Math.round(Number(totalDue) * 100)
      ? "Cash received is less than the amount due." : "";
  }

  function change(value, totalDue) {
    if (error(value, totalDue) || !Number.isFinite(Number(totalDue)) || Number(totalDue) <= 0) return 0;
    return (Math.round(Number(value) * 100) - Math.round(Number(totalDue) * 100)) / 100;
  }

  function capInput(value, totalDue) {
    const raw = String(value ?? "");
    const amount = Number(raw);
    const maximum = maximumFor(totalDue);
    // Match Grooming's live cap without treating malformed input as a payment.
    return /^\d+(?:\.\d{1,2})?$/.test(raw) && Number.isFinite(amount) && maximum > 0 && amount > maximum
      ? String(maximum) : value;
  }

  return { maximumFor, error, change, capInput };
})();
