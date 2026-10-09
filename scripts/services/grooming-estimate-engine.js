(function (root) {
  if (root.GroomingEstimates) return;
  let rules = null;

  function configure(value) { rules = value; }
  function preferences(packageId) { return Object.entries(rules?.preferences?.[packageId] || {}).map(([value, label]) => ({ value, label })); }
  function packageForServices(slugs) { return slugs.find((slug) => Object.hasOwn(rules?.packages || {}, slug)) || null; }
  function factors() { return Object.entries(rules?.factors || {}).map(([value, label]) => ({ value, label })); }
  function formatDuration(minutes) {
    const value = Math.round(Number(minutes));
    if (!Number.isFinite(value) || value < 0) return "—";
    const hours = Math.floor(value / 60), rest = value % 60;
    return hours ? `${hours} ${hours === 1 ? "hr" : "hrs"}${rest ? ` ${rest} min` : ""}` : `${value} min`;
  }
  function formatRange(min, max) {
    min = Math.round(Number(min)); max = Math.round(Number(max));
    if (!Number.isFinite(min) || !Number.isFinite(max)) return "—";
    if (min === max) return formatDuration(min);
    if (min > 0 && min % 60 === 0 && max % 60 === 0) return `${min / 60}–${max / 60} hrs`;
    return `${formatDuration(min)}–${formatDuration(max)}`;
  }
  function calculate(selection, size, { allowMissingPreference = false } = {}) {
    if (!rules) return null;
    const packageId = selection.servicePackage || "";
    const preference = selection.groomingPreference || null;
    const choices = preferences(packageId);
    if (choices.length && !choices.some((choice) => choice.value === preference) && !allowMissingPreference) return null;
    const metadata = rules.packages[packageId] || {};
    const tables = metadata.cuts ? (preference && metadata.cuts[preference] ? [metadata.cuts[preference]] : Object.values(metadata.cuts)) : metadata.sizes ? [metadata.sizes] : [];
    const ranges = tables.flatMap((table) => size ? (table[size] ? [table[size]] : []) : Object.values(table));
    if (tables.length && !ranges.length) return null;
    let min = ranges.length ? Math.min(...ranges.map((range) => range[0])) : 0;
    let max = ranges.length ? Math.max(...ranges.map((range) => range[1])) : 0;
    for (const slug of new Set(selection.alaCarteServices || [])) {
      if (metadata.included?.includes(slug)) continue;
      const range = rules.ala_carte[slug];
      if (!range) return null;
      min += range[0]; max += range[1];
    }
    const assessment = [...new Set(selection.estimateFactors || [])].filter((factor) => Object.hasOwn(rules.factors, factor));
    if (assessment.length) { min = Math.max(min, rules.extended[0]); max = Math.max(max, rules.extended[1]); }
    return min ? { minMinutes: min, maxMinutes: max, formatted: formatRange(min, max), preference: choices.length ? preference : null,
      preferenceLabel: choices.find((choice) => choice.value === preference)?.label || null, factors: assessment } : null;
  }
  function formatTimeWindow(min, max) {
    if (!Number.isFinite(min) || !Number.isFinite(max)) return null;
    const time = (value) => new Date(value).toLocaleTimeString("en-PH", { timeZone: "Asia/Manila", hour: "numeric", minute: "2-digit", hour12: true });
    const left = time(min), right = time(max);
    if (left === right) return left;
    const samePeriod = left.slice(-2) === right.slice(-2);
    return `${samePeriod ? left.slice(0, -3) : left}–${right}`;
  }
  function readyWindow(estimate, startedAt, now = Date.now()) {
    const start = new Date(startedAt).getTime();
    if (!estimate?.minMinutes || !estimate?.maxMinutes || !Number.isFinite(start)) return null;
    const min = start + estimate.minMinutes * 60000, max = start + estimate.maxMinutes * 60000;
    return { min, max, overdue: now > max, label: now > max ? "Taking longer than estimated" : formatTimeWindow(Math.max(now, min), max) };
  }
  function queueETAs(waiting, active, capacity, now = Date.now()) {
    const count = Math.max(0, Math.floor(Number(capacity) || 0));
    const minSlots = Array(count).fill(now), maxSlots = Array(count).fill(now);
    const earliest = (slots) => slots.reduce((best, value, i) => value < slots[best] ? i : best, 0);
    const result = {};
    for (const pet of active) {
      const window = readyWindow(pet.estimate, pet.startedAt, now);
      result[pet.key] = window ? { estDoneMin: window.min, estDoneMax: window.max, overdue: window.overdue } : { unavailable: true };
      if (count) {
        minSlots[earliest(minSlots)] = window && !window.overdue ? Math.max(now, window.min) : Infinity;
        maxSlots[earliest(maxSlots)] = window && !window.overdue ? Math.max(now, window.max) : Infinity;
      }
    }
    for (const pet of waiting) {
      if (!count || !pet.estimate?.minMinutes || !pet.estimate?.maxMinutes) {
        result[pet.key] = { unavailable: true };
        minSlots.fill(Infinity); maxSlots.fill(Infinity);
        continue;
      }
      const i = earliest(minSlots), j = earliest(maxSlots);
      const min = minSlots[i] + pet.estimate.minMinutes * 60000, max = maxSlots[j] + pet.estimate.maxMinutes * 60000;
      minSlots[i] = min; maxSlots[j] = max;
      result[pet.key] = Number.isFinite(max) ? { estDoneMin: min, estDoneMax: max, overdue: false } : { unavailable: true };
    }
    return result;
  }
  root.GroomingEstimates = { configure, preferences, factors, packageForServices, calculate, formatDuration, formatRange, formatTimeWindow, readyWindow, queueETAs };
})(typeof window === "undefined" ? globalThis : window);
