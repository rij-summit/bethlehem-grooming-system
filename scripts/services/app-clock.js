var AppClock = (() => {
  let loaded = false;
  let loadPromise = null;
  let testClock = null;

  function parseClockDate(value) {
    if (!value) return null;

    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? null : date;
  }

  async function load({ force = false } = {}) {
    if (loadPromise && !force) return loadPromise;
    if (loaded && !force) return testClock;

    loadPromise = (async () => {
      loaded = true;

      try {
        const data = await API.getSystemClock();
        const serverNow = parseClockDate(data?.now);
        testClock = data?.test_clock_active && serverNow
          ? { now: serverNow, timezone: data.timezone || "Asia/Manila" }
          : null;
      } catch {
        testClock = null;
      } finally {
        loadPromise = null;
      }

      return testClock;
    })();

    return loadPromise;
  }

  function now() {
    return testClock?.now ? new Date(testClock.now.getTime()) : new Date();
  }

  function manilaParts(date = now()) {
    const parts = new Intl.DateTimeFormat("en-CA", {
      timeZone: "Asia/Manila",
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
      hour12: false,
    }).formatToParts(date);

    const result = {};
    for (const part of parts) {
      if (part.type !== "literal") result[part.type] = part.value;
    }

    return {
      year: Number(result.year),
      month: Number(result.month),
      day: Number(result.day),
      hour: Number(result.hour),
      minute: Number(result.minute),
    };
  }

  function toDateKey(date) {
    return [
      date.getFullYear(),
      String(date.getMonth() + 1).padStart(2, "0"),
      String(date.getDate()).padStart(2, "0"),
    ].join("-");
  }

  function todayKey() {
    const parts = manilaParts();
    return `${parts.year}-${String(parts.month).padStart(2, "0")}-${String(parts.day).padStart(2, "0")}`;
  }

  function dateKeyWithOffset(offsetDays = 0) {
    const parts = manilaParts();
    const date = new Date(parts.year, parts.month - 1, parts.day);
    date.setDate(date.getDate() + offsetDays);
    return toDateKey(date);
  }

  function currentMinutes() {
    const parts = manilaParts();
    return parts.hour * 60 + parts.minute;
  }

  function isTestClockActive() {
    return Boolean(testClock);
  }

  return {
    load,
    now,
    todayKey,
    dateKeyWithOffset,
    currentMinutes,
    isTestClockActive,
  };
})();
