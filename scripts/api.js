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
  };
})();
