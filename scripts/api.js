// api.js — Central HTTP layer for all backend communication.
// All API calls live here. Auth scripts and components call these functions.
// Loaded as a plain <script> tag before any auth script that needs it.

// var (not const) so this is accessible as a global from ES module scripts
var API = (() => {
  const BASE_URL = "http://127.0.0.1:8000/api";

  // ── Token keys ────────────────────────────────────────────────────────────
  const CUSTOMER_TOKEN_KEY = "customer_token";
  const ADMIN_TOKEN_KEY = "admin_token";

  // ── Token helpers ─────────────────────────────────────────────────────────

  function getCustomerToken() {
    return localStorage.getItem(CUSTOMER_TOKEN_KEY);
  }

  function setCustomerToken(token) {
    localStorage.setItem(CUSTOMER_TOKEN_KEY, token);
  }

  function clearCustomerToken() {
    localStorage.removeItem(CUSTOMER_TOKEN_KEY);
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
    // payload: { first_name, last_name, email, phone, password, password_confirmation }
    return request("POST", "/register", payload);
  }

  async function customerLogin(phone, password) {
    // POST /api/login
    // Saves the returned token to localStorage under 'customer_token'.
    const data = await request("POST", "/login", { phone, password });
    setCustomerToken(data.token);
    return data;
  }

  async function adminLogin(email, password) {
    // POST /api/admin/login
    // Saves the returned token to localStorage under 'admin_token'.
    const data = await request("POST", "/admin/login", { email, password });
    setAdminToken(data.token);
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
      if (role === "admin") {
        clearAdminToken();
      } else {
        clearCustomerToken();
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

  async function getUserPets() {
    // GET /api/pets  (protected)
    return request("GET", "/pets", null, getCustomerToken());
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

  async function getAdminBookings(date = null) {
    // GET /api/admin/bookings?date=YYYY-MM-DD  (protected — admin token)
    const query = date ? `?date=${date}` : "";
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

  // ── Public interface ──────────────────────────────────────────────────────

  return {
    // Token access (used by other scripts that need to attach the token)
    getCustomerToken,
    setCustomerToken,
    clearCustomerToken,
    getAdminToken,
    setAdminToken,
    clearAdminToken,
    // Auth
    register,
    customerLogin,
    adminLogin,
    logout,
    getMe,
    // Timeslots
    getTimeslots,
    // Pets
    getUserPets,
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
  };
})();
