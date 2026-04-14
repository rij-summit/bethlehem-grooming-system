const BOOKING_FORM_LOCK_STORAGE_KEY = "bethlehem.bookingFormLock";
const BOOKING_FORM_LOCK_WINDOW_MS = 24 * 60 * 60 * 1000;

function readLockFromStorage() {
  const sessionLock = readJson(sessionStorage.getItem(BOOKING_FORM_LOCK_STORAGE_KEY));
  if (sessionLock) {
    return sessionLock;
  }

  const localLock = readJson(localStorage.getItem(BOOKING_FORM_LOCK_STORAGE_KEY));
  if (localLock) {
    try {
      sessionStorage.setItem(
        BOOKING_FORM_LOCK_STORAGE_KEY,
        JSON.stringify(localLock),
      );
    } catch (error) {
      console.error("Failed to mirror booking form lock to sessionStorage:", error);
    }

    return localLock;
  }

  return null;
}

function readJson(rawValue) {
  if (!rawValue) return null;

  try {
    const parsedValue = JSON.parse(rawValue);
    return parsedValue && typeof parsedValue === "object" ? parsedValue : null;
  } catch (error) {
    console.error("Failed to parse booking form lock:", error);
    return null;
  }
}

function isLockExpired(lock) {
  const expiresAt = Date.parse(lock?.expiresAt || "");
  if (!Number.isFinite(expiresAt)) {
    return true;
  }

  return Date.now() > expiresAt;
}

function shouldBypassGuard() {
  const params = new URLSearchParams(window.location.search);
  return (
    params.get("reschedule") === "1" ||
    params.get("allowBooking") === "1"
  );
}

export function clearBookingFormLock() {
  try {
    sessionStorage.removeItem(BOOKING_FORM_LOCK_STORAGE_KEY);
    localStorage.removeItem(BOOKING_FORM_LOCK_STORAGE_KEY);
  } catch (error) {
    console.error("Failed to clear booking form lock:", error);
  }
}

export function persistBookingFormLock(booking = null) {
  const now = Date.now();
  const lock = {
    reference: booking?.reference || "",
    lockedAt: new Date(now).toISOString(),
    expiresAt: new Date(now + BOOKING_FORM_LOCK_WINDOW_MS).toISOString(),
  };

  const serializedLock = JSON.stringify(lock);

  try {
    sessionStorage.setItem(BOOKING_FORM_LOCK_STORAGE_KEY, serializedLock);
    localStorage.setItem(BOOKING_FORM_LOCK_STORAGE_KEY, serializedLock);
  } catch (error) {
    console.error("Failed to persist booking form lock:", error);
  }
}

export function enforceBookingFormAccessGuard(options = {}) {
  const dashboardPath = options.dashboardPath || "./dashboard.html";

  if (shouldBypassGuard()) {
    clearBookingFormLock();
    return false;
  }

  const lock = readLockFromStorage();
  if (!lock) {
    return false;
  }

  if (isLockExpired(lock)) {
    clearBookingFormLock();
    return false;
  }

  window.location.replace(dashboardPath);
  return true;
}

export function initBookingFormAccessGuard(options = {}) {
  const dashboardPath = options.dashboardPath || "./dashboard.html";

  const blockedNow = enforceBookingFormAccessGuard({ dashboardPath });

  window.addEventListener("pageshow", () => {
    enforceBookingFormAccessGuard({ dashboardPath });
  });

  return blockedNow;
}
