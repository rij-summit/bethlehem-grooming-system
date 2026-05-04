// api.js — Central HTTP layer for all backend communication.
// All API calls live here. Auth scripts and components call these functions.
// Loaded as a plain <script> tag before any auth script that needs it.

// var (not const) so this is accessible as a global from ES module scripts
var API = (() => {
  const BASE_URL = "http://127.0.0.1:8000/api";

  // ── Token keys ────────────────────────────────────────────────────────────
  const CUSTOMER_TOKEN_KEY = "customer_token";
  const ADMIN_TOKEN_KEY    = "admin_token";
  const USER_ROLE_KEY      = "user_role";
  const AUTH_LOGOUT_EVENT_KEY = "bethlehem.auth.logout";

  // ── Booking session keys to wipe on customer logout / login ──────────────
  const BOOKING_SESSION_KEYS = [
    "bookingStep2",
    "bookingStep3",
    "bookingStep4Review",
    "bookingReview",
    "bookingConsentStep",
    "bookingConfirmation",
    "bethlehem.bookingFormLock",
    "bookingPets",
  ];
  const BOOKING_LOCAL_KEYS = [
    "bethlehem.bookingFormLock",
    "shownPickupNotifs",
    "clientPets",
  ];

  function clearBookingDraft() {
    BOOKING_SESSION_KEYS.forEach((k) => sessionStorage.removeItem(k));
    BOOKING_LOCAL_KEYS.forEach((k) => localStorage.removeItem(k));
  }

  // ── Token helpers ─────────────────────────────────────────────────────────

  function getCustomerToken() {
    return localStorage.getItem(CUSTOMER_TOKEN_KEY) || sessionStorage.getItem(CUSTOMER_TOKEN_KEY);
  }

  function setCustomerToken(token, remember = true) {
    if (remember) {
      localStorage.setItem(CUSTOMER_TOKEN_KEY, token);
    } else {
      sessionStorage.setItem(CUSTOMER_TOKEN_KEY, token);
    }
  }

  function clearCustomerToken() {
    localStorage.removeItem(CUSTOMER_TOKEN_KEY);
    sessionStorage.removeItem(CUSTOMER_TOKEN_KEY);
  }

  function getUserRole() {
    return localStorage.getItem(USER_ROLE_KEY) || sessionStorage.getItem(USER_ROLE_KEY);
  }

  function setUserRole(role, remember = true) {
    if (remember) {
      localStorage.setItem(USER_ROLE_KEY, role);
    } else {
      sessionStorage.setItem(USER_ROLE_KEY, role);
    }
  }

  function clearUserRole() {
    localStorage.removeItem(USER_ROLE_KEY);
    sessionStorage.removeItem(USER_ROLE_KEY);
  }

  function getAdminToken() {
    return localStorage.getItem(ADMIN_TOKEN_KEY);
  }

  function setAdminToken(token) {
    localStorage.setItem(ADMIN_TOKEN_KEY, token);
  }

  function clearAdminToken() {
    localStorage.removeItem(ADMIN_TOKEN_KEY);
  }

  function isAdminPage() {
    return window.location.pathname.replace(/\\/g, "/").includes("/pages/admin/");
  }

  function isSignInPage() {
    return window.location.pathname.replace(/\\/g, "/").includes("/pages/client/sign-in.html");
  }

  function signInPath() {
    const depth = window.location.pathname.split("/").filter(Boolean).length;
    return depth >= 2 ? "../client/sign-in.html" : "./pages/client/sign-in.html";
  }

  function redirectToSignIn() {
    if (!isSignInPage()) {
      window.location.href = signInPath();
    }
  }

  function notifyLogout(role) {
    try {
      localStorage.setItem(
        AUTH_LOGOUT_EVENT_KEY,
        JSON.stringify({ role, at: Date.now() })
      );
    } catch {
      // Non-fatal: removing the token still syncs logout in supported browsers.
    }
  }

  function handleCrossTabLogout(role) {
    if (role !== "admin" && role !== "staff") return;

    clearAdminToken();
    if (getUserRole() === "admin" || getUserRole() === "staff" || isAdminPage()) {
      clearUserRole();
    }

    if (isAdminPage()) {
      redirectToSignIn();
    }
  }

  if (typeof window !== "undefined") {
    window.addEventListener("storage", (event) => {
      if (event.storageArea !== localStorage) return;

      if (event.key === ADMIN_TOKEN_KEY && event.oldValue && !event.newValue) {
        handleCrossTabLogout("admin");
        return;
      }

      if (event.key !== AUTH_LOGOUT_EVENT_KEY || !event.newValue) return;

      try {
        const data = JSON.parse(event.newValue);
        handleCrossTabLogout(data?.role);
      } catch {
        // Ignore malformed auth sync events.
      }
    });
  }

  // ── Core request function ─────────────────────────────────────────────────
  // Callers get back the parsed JSON on success.
  // On failure, a structured Error is thrown with:
  //   error.status  — HTTP status code (e.g. 401, 422, 500)
  //   error.message — backend message or generic fallback
  //   error.errors  — Laravel validation errors object (422 only), or null

  async function request(method, endpoint, body = null, token = null) {
    const headers = {
      "Content-Type": "application/json",
      Accept: "application/json",
    };

    if (token) {
      headers["Authorization"] = `Bearer ${token}`;
    }

    const options = { method, headers };

    if (body !== null) {
      options.body = JSON.stringify(body);
    }

    let response;

    try {
      response = await fetch(`${BASE_URL}${endpoint}`, options);
    } catch {
      // Network failure (server down, no internet, CORS preflight killed)
      const networkError = new Error(
        "Unable to reach the server. Please check your connection."
      );
      networkError.status = 0;
      networkError.errors = null;
      throw networkError;
    }

    const data = await response.json().catch(() => ({}));

    if (response.status === 401) {
      if (token) {
        // Only redirect when an authenticated request loses its session.
        // Public endpoints (sign-in, register) return 401 on bad credentials
        // and must fall through so the caller can surface the error message.
        if (token === getAdminToken()) {
          clearAdminToken();
        } else if (token === getCustomerToken()) {
          clearCustomerToken();
          clearBookingDraft();
        }
        clearUserRole();
        const depth = window.location.pathname.split("/").filter(Boolean).length;
        window.location.href = depth >= 2
          ? "../client/sign-in.html"
          : "./pages/client/sign-in.html";
        throw new Error("Your session has expired. Please sign in again.");
      }
    }

    if (response.status === 429) {
      const error = new Error(data.message || "Too many attempts. Please wait before trying again.");
      error.status = 429;
      error.retryAfter = data.retry_after ?? null;
      error.errors = null;
      throw error;
    }

    if (!response.ok) {
      const error = new Error(data.message || "Something went wrong.");
      error.status = response.status;
      error.errors = data.errors || null;
      throw error;
    }

    return data;
  }

  // ── Auth API calls ────────────────────────────────────────────────────────

  async function register(payload) {
    // POST /api/register
    // payload: { first_name, last_name, username?, email, phone, password, password_confirmation }
    // Saves the returned token so the caller can redirect straight to the dashboard.
    const data = await request("POST", "/register", payload);
    if (data?.token) {
      clearBookingDraft();
      setCustomerToken(data.token, true);
      setUserRole(data.user?.role ?? "customer", true);
    }
    return data;
  }

  async function signIn(identifier, password, remember = true) {
    // POST /api/sign-in
    // Accepts email, phone (09... or +639...), or username. Saves to the
    // correct token key based on role. remember=false uses sessionStorage so
    // the session dies when the tab closes.
    const normalized = normalizePhoneLikeIdentifier(identifier);
    const resolvedIdentifier = normalized || identifier;
    const data = await request("POST", "/sign-in", { identifier: resolvedIdentifier, password });
    const role = data?.user?.role;
    if (role === "admin" || role === "staff") {
      setAdminToken(data.token);
      setUserRole(role, true); // admin session always persists
    } else {
      clearBookingDraft();
      setCustomerToken(data.token, remember);
      setUserRole(role, remember);
    }
    return data;
  }

  async function logout(role = "customer") {
    // POST /api/logout  (protected — sends the correct token in the header)
    // Token is always cleared locally even if the API call fails.
    const token =
      role === "admin" ? getAdminToken() : getCustomerToken();

    try {
      await request("POST", "/logout", null, token);
    } finally {
      clearUserRole();
      if (role === "admin") {
        clearAdminToken();
        notifyLogout(role);
      } else {
        clearCustomerToken();
        clearBookingDraft();
      }
    }
  }

  async function getMe(role = "customer") {
    // GET /api/me  (protected)
    const token =
      role === "admin" ? getAdminToken() : getCustomerToken();
    return request("GET", "/me", null, token);
  }

  async function getTimeslots(date) {
    // GET /api/timeslots?date=YYYY-MM-DD  (public — no token needed)
    return request("GET", `/timeslots?date=${date}`);
  }

  async function getUserPets({ archived = 0 } = {}) {
    // GET /api/pets?archived=0|1  (protected)
    // archived=0 → active pets (booking form default)
    // archived=1 → archived pets (My Pets page toggle)
    return request("GET", `/pets?archived=${archived}`, null, getCustomerToken());
  }

  async function addPet(payload) {
    // POST /api/pets  (protected)
    return request("POST", "/pets", payload, getCustomerToken());
  }

  async function updatePet(petId, payload) {
    // PUT /api/pets/{id}  (protected)
    return request("PUT", `/pets/${petId}`, payload, getCustomerToken());
  }

  async function archivePet(petId) {
    // POST /api/pets/{id}/archive  (protected)
    return request("POST", `/pets/${petId}/archive`, null, getCustomerToken());
  }

  async function unarchivePet(petId) {
    // POST /api/pets/{id}/unarchive  (protected)
    return request("POST", `/pets/${petId}/unarchive`, null, getCustomerToken());
  }

  async function storeBooking(payload) {
    // POST /api/booking/store  (protected)
    return request("POST", "/booking/store", payload, getCustomerToken());
  }

  async function getBookingHistory() {
    // GET /api/booking/history  (protected)
    return request("GET", "/booking/history", null, getCustomerToken());
  }

  async function cancelBooking(bookingId, reason = null) {
    // POST /api/booking/cancel  (protected)
    return request("POST", "/booking/cancel", { booking_id: bookingId, reason }, getCustomerToken());
  }

  async function rescheduleBooking(bookingId, newDate, newWindowId) {
    // POST /api/booking/reschedule  (protected)
    return request("POST", "/booking/reschedule", {
      booking_id:    bookingId,
      new_date:      newDate,
      new_window_id: newWindowId,
    }, getCustomerToken());
  }

  // ── Admin ─────────────────────────────────────────────────────────────────

  async function getAdminBookings(date = null, { includeFuture = false } = {}) {
    // GET /api/admin/bookings?date=YYYY-MM-DD&include_future=1
    const params = new URLSearchParams();
    if (date) params.set("date", date);
    if (includeFuture) params.set("include_future", "1");
    const query = params.toString() ? `?${params.toString()}` : "";
    return request("GET", `/admin/bookings${query}`, null, getAdminToken());
  }

  async function adminCheckIn(bookingId) {
    // POST /api/admin/bookings/{id}/check-in  (protected — admin token)
    return request("POST", `/admin/bookings/${bookingId}/check-in`, null, getAdminToken());
  }

  async function adminStartGrooming(bookingId) {
    // POST /api/admin/bookings/{id}/start-grooming  (protected — admin token)
    return request("POST", `/admin/bookings/${bookingId}/start-grooming`, null, getAdminToken());
  }

  async function adminMarkDone(bookingId) {
    // POST /api/admin/bookings/{id}/mark-done  (protected — admin token)
    return request("POST", `/admin/bookings/${bookingId}/mark-done`, null, getAdminToken());
  }

  async function getNotifications() {
    // GET /api/admin/notifications  (protected — admin token)
    return request("GET", "/admin/notifications", null, getAdminToken());
  }

  async function markNotificationRead(notificationId) {
    // PATCH /api/admin/notifications/{id}/read  (protected — admin token)
    return request("PATCH", `/admin/notifications/${notificationId}/read`, null, getAdminToken());
  }

  // ── Customer management (admin only) ─────────────────────────────────────

  async function getCustomers({ status = "active", tier = "", search = "" } = {}) {
    // GET /api/admin/customers  (protected — admin token)
    const params = new URLSearchParams();
    if (status) params.set("status", status);
    if (tier)   params.set("tier",   tier);
    if (search) params.set("search", search);
    const query = params.toString() ? `?${params.toString()}` : "";
    return request("GET", `/admin/customers${query}`, null, getAdminToken());
  }

  async function deactivateCustomer(customerId) {
    // POST /api/admin/customers/{id}/deactivate  (protected — admin token)
    return request("POST", `/admin/customers/${customerId}/deactivate`, null, getAdminToken());
  }

  async function reactivateCustomer(customerId) {
    // POST /api/admin/customers/{id}/reactivate  (protected — admin token)
    return request("POST", `/admin/customers/${customerId}/reactivate`, null, getAdminToken());
  }

  async function archiveCustomer(customerId) {
    // POST /api/admin/customers/{id}/archive  (protected — admin token)
    return request("POST", `/admin/customers/${customerId}/archive`, null, getAdminToken());
  }

  async function unarchiveCustomer(customerId) {
    // POST /api/admin/customers/{id}/unarchive  (protected — admin token)
    return request("POST", `/admin/customers/${customerId}/unarchive`, null, getAdminToken());
  }

  async function adminArchiveBooking(bookingId) {
    // POST /api/admin/bookings/{id}/archive  (protected — admin token)
    return request("POST", `/admin/bookings/${bookingId}/archive`, null, getAdminToken());
  }

  async function getArchivedBookings({ search = "", date = "" } = {}) {
    // GET /api/admin/bookings/archived  (protected — admin token)
    const params = new URLSearchParams();
    if (search) params.set("search", search);
    if (date)   params.set("date", date);
    const query = params.toString() ? `?${params.toString()}` : "";
    return request("GET", `/admin/bookings/archived${query}`, null, getAdminToken());
  }

  async function markAllNotificationsRead() {
    // PATCH /api/admin/notifications/read-all  (protected — admin token)
    return request("PATCH", "/admin/notifications/read-all", null, getAdminToken());
  }

  // ── Clinic closures / no-show ─────────────────────────────────────────────

  async function getClinicStatus() {
    // GET /api/clinic/status  (public — no token needed)
    return request("GET", "/clinic/status");
  }

  async function adminStopToday(reason = null) {
    // POST /api/admin/clinic/stop-today  (protected — admin token)
    return request("POST", "/admin/clinic/stop-today", { reason }, getAdminToken());
  }

  async function adminReopenToday() {
    // POST /api/admin/clinic/reopen-today  (protected — admin token)
    return request("POST", "/admin/clinic/reopen-today", null, getAdminToken());
  }

  async function getBlockedDates() {
    // GET /api/admin/clinic/blocked-dates  (protected — admin token)
    return request("GET", "/admin/clinic/blocked-dates", null, getAdminToken());
  }

  async function addBlockedDate(payload) {
    // POST /api/admin/clinic/blocked-dates  (protected — admin token)
    // payload: { start_date, end_date, reason }
    return request("POST", "/admin/clinic/blocked-dates", payload, getAdminToken());
  }

  async function removeBlockedDate(id) {
    // DELETE /api/admin/clinic/blocked-dates/{id}  (protected — admin token)
    return request("DELETE", `/admin/clinic/blocked-dates/${id}`, null, getAdminToken());
  }

  async function getNoShows() {
    // GET /api/admin/bookings/no-shows  (protected — admin token)
    return request("GET", "/admin/bookings/no-shows", null, getAdminToken());
  }

  async function adminLateCheckIn(bookingId) {
    // POST /api/admin/bookings/{id}/late-check-in  (protected — admin token)
    return request("POST", `/admin/bookings/${bookingId}/late-check-in`, null, getAdminToken());
  }

  async function processPayment(bookingId, payload) {
    // POST /api/admin/bookings/{id}/pay  (protected — admin token)
    // payload: { final_price, amount_paid, notes? }
    return request("POST", `/admin/bookings/${bookingId}/pay`, payload, getAdminToken());
  }

  async function payNow(bookingId, payload) {
    // POST /api/admin/bookings/{id}/pay-now  (protected — admin token)
    // Early payment while booking is still checked_in
    return request("POST", `/admin/bookings/${bookingId}/pay-now`, payload, getAdminToken());
  }

  async function releaseBooking(bookingId) {
    // POST /api/admin/bookings/{id}/release  (protected — admin token)
    // Moves an already-paid for_payment booking to released (To Be Picked Up)
    return request("POST", `/admin/bookings/${bookingId}/release`, null, getAdminToken());
  }

  async function markPickedUp(bookingId) {
    // POST /api/admin/bookings/{id}/picked-up  (protected — admin token)
    // Archives a released booking and notifies the customer
    return request("POST", `/admin/bookings/${bookingId}/picked-up`, null, getAdminToken());
  }

  async function getCustomerNotifications() {
    // GET /api/customer/notifications  (protected — customer token)
    return request("GET", "/customer/notifications", null, getCustomerToken());
  }

  async function markCustomerNotificationRead(id) {
    // PATCH /api/customer/notifications/{id}/read  (protected — customer token)
    return request("PATCH", `/customer/notifications/${id}/read`, null, getCustomerToken());
  }

  async function markAllCustomerNotificationsRead() {
    // PATCH /api/customer/notifications/read-all  (protected — customer token)
    return request("PATCH", "/customer/notifications/read-all", null, getCustomerToken());
  }

  async function getTransactions({ search = "", period = "day", date = "", month = "", year = "" } = {}) {
    // GET /api/admin/transactions  (protected — admin token)
    const params = new URLSearchParams();
    if (search) params.set("search", search);
    if (period) params.set("period", period);
    if (date)   params.set("date",   date);
    if (month)  params.set("month",  month);
    if (year)   params.set("year",   year);
    const query = params.toString() ? `?${params.toString()}` : "";
    return request("GET", `/admin/transactions${query}`, null, getAdminToken());
  }

  async function getServicesPerformedReport({ period = "day", date = "", month = "", year = "" } = {}) {
    // GET /api/admin/reports/services-performed  (protected - admin token)
    const params = new URLSearchParams();
    if (period) params.set("period", period);
    if (date)   params.set("date",   date);
    if (month)  params.set("month",  month);
    if (year)   params.set("year",   year);
    const query = params.toString() ? `?${params.toString()}` : "";
    return request("GET", `/admin/reports/services-performed${query}`, null, getAdminToken());
  }

  async function getCustomerActivityReport({ period = "day", date = "", month = "", year = "" } = {}) {
    // GET /api/admin/reports/customer-activity  (protected - admin token)
    const params = new URLSearchParams();
    if (period) params.set("period", period);
    if (date)   params.set("date",   date);
    if (month)  params.set("month",  month);
    if (year)   params.set("year",   year);
    const query = params.toString() ? `?${params.toString()}` : "";
    return request("GET", `/admin/reports/customer-activity${query}`, null, getAdminToken());
  }

  function normalizePhoneLikeIdentifier(value) {
    const digits = String(value || "").replace(/\D/g, "");

    if (!digits) return null;
    if (/^09\d{9}$/.test(digits)) return digits;
    if (/^639\d{9}$/.test(digits)) return `0${digits.slice(2)}`;
    if (/^9\d{9}$/.test(digits)) return `0${digits}`;

    return null;
  }

  // ── Public interface ──────────────────────────────────────────────────────

  return {
    // Token access (used by other scripts that need to attach the token)
    getCustomerToken,
    setCustomerToken,
    clearCustomerToken,
    getAdminToken,
    setAdminToken,
    clearAdminToken,
    // Role access (used by admin dashboard for staff role-based UI hiding)
    getUserRole,
    setUserRole,
    clearUserRole,
    // Auth
    register,
    signIn,
    logout,
    getMe,
    // Timeslots
    getTimeslots,
    // Pets
    getUserPets,
    addPet,
    updatePet,
    archivePet,
    unarchivePet,
    // Booking
    storeBooking,
    getBookingHistory,
    cancelBooking,
    rescheduleBooking,
    // Admin bookings
    getAdminBookings,
    adminCheckIn,
    adminStartGrooming,
    adminMarkDone,
    // Admin customers
    getCustomers,
    deactivateCustomer,
    reactivateCustomer,
    archiveCustomer,
    unarchiveCustomer,
    // Admin archive
    adminArchiveBooking,
    getArchivedBookings,
    // Admin notifications
    getNotifications,
    markNotificationRead,
    markAllNotificationsRead,
    // Clinic closures / no-show
    getClinicStatus,
    adminStopToday,
    adminReopenToday,
    getBlockedDates,
    addBlockedDate,
    removeBlockedDate,
    getNoShows,
    adminLateCheckIn,
    // Customer notifications
    getCustomerNotifications,
    markCustomerNotificationRead,
    markAllCustomerNotificationsRead,
    // Payments
    processPayment,
    payNow,
    releaseBooking,
    markPickedUp,
    getTransactions,
    getServicesPerformedReport,
    getCustomerActivityReport,
  };
})();
