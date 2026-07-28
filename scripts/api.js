// api.js — Central HTTP layer for all backend communication.
// All API calls live here. Auth scripts and components call these functions.
// Loaded as a plain <script> tag before any auth script that needs it.

// var (not const) so this is accessible as a global from ES module scripts
var API = (() => {
  const LOCAL_API_ORIGIN = "http://127.0.0.1:8000";
  const REQUEST_TIMEOUT_MS = 12000;

  function trimTrailingSlash(value) {
    return String(value || "").replace(/\/+$/, "");
  }

  function apiUrlFromOrigin(origin) {
    const normalized = trimTrailingSlash(origin);
    if (!normalized) return "";
    return normalized.endsWith("/api") ? normalized : `${normalized}/api`;
  }

  function resolveBaseUrl() {
    const configured = typeof window !== "undefined"
      ? window.BETHLEHEM_API_BASE_URL
      : "";
    const metaConfigured = typeof document !== "undefined"
      ? document.querySelector('meta[name="bethlehem-api-base-url"]')?.content
      : "";
    const explicitBase = apiUrlFromOrigin(configured || metaConfigured);

    if (explicitBase) return explicitBase;

    if (typeof window === "undefined" || !window.location) {
      return `${LOCAL_API_ORIGIN}/api`;
    }

    const { protocol, hostname, port, origin } = window.location;
    const localHostnames = new Set(["localhost", "127.0.0.1", "::1"]);
    const isLocalDevServer = localHostnames.has(hostname) && port && port !== "8000";

    if (protocol === "file:" || isLocalDevServer) {
      return `${LOCAL_API_ORIGIN}/api`;
    }

    return `${origin}/api`;
  }

  const BASE_URL = resolveBaseUrl();

  // ── Token keys ────────────────────────────────────────────────────────────
  const CUSTOMER_TOKEN_KEY = "customer_token";
  const ADMIN_TOKEN_KEY    = "admin_token";
  const USER_ROLE_KEY      = "user_role";
  const AUTH_LOGOUT_EVENT_KEY = "bethlehem.auth.logout";
  const ADMIN_ONLY_PAGE_NAMES = ["reports.html", "settings.html", "services.html"];

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
    "clinicVisitDraft",
    "clinicVisitPets",
    "clinicVisitConfirmation",
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
    const parts = window.location.pathname.replace(/\\/g, "/").split("/").filter(Boolean);
    if (parts.length <= 1) return "./pages/client/sign-in.html";
    return "../".repeat(parts.length - 1) + "pages/client/sign-in.html";
  }

  function redirectToSignIn() {
    if (!isSignInPage()) {
      window.location.href = signInPath();
    }
  }

  function currentPageName() {
    const path = window.location.pathname.replace(/\\/g, "/");
    return path.split("/").filter(Boolean).pop() || "";
  }

  function isAdminOnlyPage() {
    return isAdminPage() && ADMIN_ONLY_PAGE_NAMES.includes(currentPageName());
  }

  function redirectToAdminDashboard() {
    if (currentPageName() !== "dashboard.html") {
      window.location.replace("./dashboard.html");
    }
  }

  function enforceAdminPageAccess() {
    if (!isAdminPage()) return true;

    const role = getUserRole();
    if (!getAdminToken() || (role !== "admin" && role !== "staff")) {
      redirectToSignIn();
      return false;
    }

    if (isAdminOnlyPage() && role !== "admin") {
      redirectToAdminDashboard();
      return false;
    }

    return true;
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

    enforceAdminPageAccess();
  }

  // ── Core request function ─────────────────────────────────────────────────
  // Callers get back the parsed JSON on success.
  // On failure, a structured Error is thrown with:
  //   error.status  — HTTP status code (e.g. 401, 422, 500)
  //   error.message — backend message or generic fallback
  //   error.errors  — Laravel validation errors object (422 only), or null

  async function request(method, endpoint, body = null, token = null, requestOptions = {}) {
    const isFormData = typeof FormData !== "undefined" && body instanceof FormData;
    const headers = {
      Accept: "application/json",
    };

    if (!isFormData) {
      headers["Content-Type"] = "application/json";
    }

    if (token) {
      headers["Authorization"] = `Bearer ${token}`;
    }

    const options = { method, headers };

    if (body !== null) {
      options.body = isFormData ? body : JSON.stringify(body);
    }

    let response;
    const controller = typeof AbortController !== "undefined"
      ? new AbortController()
      : null;
    const timeoutId = controller
      ? setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS)
      : null;

    if (controller) {
      options.signal = controller.signal;
    }

    try {
      response = await fetch(`${BASE_URL}${endpoint}`, options);
    } catch (error) {
      // Network failure (server down, no internet, CORS preflight killed)
      const networkError = new Error(
        error?.name === "AbortError"
          ? "The server is taking too long to respond. Please try again."
          : "Unable to reach the server. Please check your connection."
      );
      networkError.status = 0;
      networkError.errors = null;
      throw networkError;
    } finally {
      if (timeoutId) clearTimeout(timeoutId);
    }

    const expectsBlob = requestOptions.responseType === "blob";
    const data = response.ok && expectsBlob
      ? null
      : await response.json().catch(() => ({}));

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
      if (response.status === 403 && token === getAdminToken() && isAdminOnlyPage()) {
        redirectToAdminDashboard();
      }

      const error = new Error(data.message || "Something went wrong.");
      error.status = response.status;
      error.errors = data.errors || null;
      throw error;
    }

    if (expectsBlob) {
      const contentDisposition = response.headers.get("Content-Disposition") || "";
      const encodedFileName = contentDisposition.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
      const quotedFileName = contentDisposition.match(/filename="([^"]+)"/i)?.[1];
      const plainFileName = contentDisposition.match(/filename=([^;]+)/i)?.[1]?.trim();
      let fileName = encodedFileName || quotedFileName || plainFileName || null;

      if (fileName) {
        try {
          fileName = decodeURIComponent(fileName.replace(/^"|"$/g, ""));
        } catch {
          fileName = fileName.replace(/^"|"$/g, "");
        }
      }

      return {
        blob: await response.blob(),
        fileName,
        contentType: response.headers.get("Content-Type") || "application/octet-stream",
      };
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

  async function getSystemClock() {
    return request("GET", "/system/clock");
  }

  async function sendChatbotMessage(message) {
    // POST /api/chatbot  (public)
    return request("POST", "/chatbot", { message });
  }

  async function getTimeslots(date) {
    // GET /api/timeslots?date=YYYY-MM-DD  (public — no token needed)
    return request("GET", `/timeslots?date=${date}`);
  }

  async function getClinicTimeslots(date) {
    // GET /api/clinic/timeslots?date=YYYY-MM-DD (public)
    return request("GET", `/clinic/timeslots?date=${encodeURIComponent(date)}`);
  }

  async function getUserPets({ archived = 0 } = {}) {
    // GET /api/pets?archived=0|1  (protected)
    // archived=0 → active pets (booking form default)
    // archived=1 → archived pets (My Pets page toggle)
    return request("GET", `/pets?archived=${archived}`, null, getCustomerToken());
  }

  async function getPet(petId) {
    // GET /api/pets/{id}  (protected and owner-scoped)
    return request("GET", `/pets/${petId}`, null, getCustomerToken());
  }

  async function getPetMedicalRecords(petId) {
    // GET /api/pets/{petId}/medical-records  (protected and owner-scoped)
    return request("GET", `/pets/${petId}/medical-records`, null, getCustomerToken());
  }

  async function getPetVaccinations(petId) {
    // GET /api/pets/{petId}/vaccinations  (protected, owner-scoped, and client-safe)
    return request("GET", `/pets/${petId}/vaccinations`, null, getCustomerToken());
  }

  async function getPetMedicalConcerns(petId) {
    return request(
      "GET",
      `/pets/${encodeURIComponent(petId)}/medical-concerns`,
      null,
      getCustomerToken(),
    );
  }

  async function getPetMedicalConcern(petId, publicId) {
    return request(
      "GET",
      `/pets/${encodeURIComponent(petId)}/medical-concerns/${encodeURIComponent(publicId)}`,
      null,
      getCustomerToken(),
    );
  }

  async function acknowledgePetMedicalConcern(petId, publicId) {
    return request(
      "POST",
      `/pets/${encodeURIComponent(petId)}/medical-concerns/${encodeURIComponent(publicId)}/acknowledge`,
      {},
      getCustomerToken(),
    );
  }

  async function submitPetMedicalConcernConsent(
    petId,
    publicId,
    decision,
    signatureName,
  ) {
    return request(
      "POST",
      `/pets/${encodeURIComponent(petId)}/medical-concerns/${encodeURIComponent(publicId)}/consent`,
      {
        decision,
        signature_name: signatureName,
      },
      getCustomerToken(),
    );
  }

  async function addPet(payload) {
    // POST /api/pets  (protected)
    return request("POST", "/pets", payload, getCustomerToken());
  }

  async function updatePet(petId, payload) {
    // PUT /api/pets/{id}  (protected)
    return request("PUT", `/pets/${petId}`, payload, getCustomerToken());
  }

  async function adminUpdatePet(petId, payload) {
    // PUT /api/admin/pets/{id}  (admin/staff — updates any customer's pet)
    return request("PUT", `/admin/pets/${petId}`, payload, getAdminToken());
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

  async function getBookingHistory({ historyLimit = null, petId = null } = {}) {
    // GET /api/booking/history  (protected)
    const params = new URLSearchParams();
    if (historyLimit !== null) {
      params.set("history_limit", String(Math.max(0, Number(historyLimit) || 0)));
    }
    if (petId !== null) {
      params.set("pet_id", String(petId));
    }
    const query = params.toString() ? `?${params.toString()}` : "";
    return request("GET", `/booking/history${query}`, null, getCustomerToken());
  }

  async function getGroomingCapacity() {
    // GET /api/booking/grooming-capacity  (protected)
    return request("GET", "/booking/grooming-capacity", null, getCustomerToken());
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

  async function submitClinicPreRegistration(payload) {
    // POST /api/clinic/pre-register (protected — customer token)
    // payload: { appointment_date, pet_id, chief_complaint }
    return request(
      "POST",
      "/clinic/pre-register",
      payload,
      getCustomerToken(),
    );
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

  async function adminStartPetGrooming(bookingId, bookingPetId) {
    // Starts grooming for one pet while preserving the rest of the owner's queue card.
    return request(
      "POST",
      `/admin/bookings/${bookingId}/pets/${bookingPetId}/start-grooming`,
      null,
      getAdminToken(),
    );
  }

  async function adminMarkDone(bookingId) {
    // POST /api/admin/bookings/{id}/mark-done  (protected — admin token)
    return request("POST", `/admin/bookings/${bookingId}/mark-done`, null, getAdminToken());
  }

  async function adminMarkPetDone(bookingId, bookingPetId) {
    // Finishes one pet while the owner booking remains in progress until all pets are done.
    return request(
      "POST",
      `/admin/bookings/${bookingId}/pets/${bookingPetId}/mark-done`,
      null,
      getAdminToken(),
    );
  }

  function adminBookingPetConcernPath(bookingId, bookingPetId) {
    return `/admin/bookings/${encodeURIComponent(bookingId)}/pets/${encodeURIComponent(bookingPetId)}/medical-concerns`;
  }

  async function getAdminBookingPetMedicalConcerns(bookingId, bookingPetId) {
    return request(
      "GET",
      adminBookingPetConcernPath(bookingId, bookingPetId),
      null,
      getAdminToken(),
    );
  }

  async function createAdminBookingPetMedicalConcern(bookingId, bookingPetId, payload) {
    return request(
      "POST",
      adminBookingPetConcernPath(bookingId, bookingPetId),
      payload,
      getAdminToken(),
    );
  }

  async function getAdminBookingPetMedicalConcern(bookingId, bookingPetId, concernId) {
    return request(
      "GET",
      `${adminBookingPetConcernPath(bookingId, bookingPetId)}/${encodeURIComponent(concernId)}`,
      null,
      getAdminToken(),
    );
  }

  async function updateAdminBookingPetMedicalConcern(
    bookingId,
    bookingPetId,
    concernId,
    payload,
  ) {
    return request(
      "PATCH",
      `${adminBookingPetConcernPath(bookingId, bookingPetId)}/${encodeURIComponent(concernId)}`,
      payload,
      getAdminToken(),
    );
  }

  async function cancelAdminBookingPetMedicalConcern(
    bookingId,
    bookingPetId,
    concernId,
    payload,
  ) {
    return request(
      "POST",
      `${adminBookingPetConcernPath(bookingId, bookingPetId)}/${encodeURIComponent(concernId)}/cancel`,
      payload,
      getAdminToken(),
    );
  }

  async function resolveAdminBookingPetMedicalConcern(
    bookingId,
    bookingPetId,
    concernId,
    payload,
  ) {
    return request(
      "POST",
      `${adminBookingPetConcernPath(bookingId, bookingPetId)}/${encodeURIComponent(concernId)}/resolve`,
      payload,
      getAdminToken(),
    );
  }

  async function notifyCustomerAboutAdminBookingPetMedicalConcern(
    bookingId,
    bookingPetId,
    concernId,
  ) {
    return request(
      "POST",
      `${adminBookingPetConcernPath(bookingId, bookingPetId)}/${encodeURIComponent(concernId)}/notify-customer`,
      null,
      getAdminToken(),
    );
  }

  async function applyAdminBookingPetMedicalConcernAction(
    bookingId,
    bookingPetId,
    concernId,
    payload = {},
  ) {
    return request(
      "POST",
      `${adminBookingPetConcernPath(bookingId, bookingPetId)}/${encodeURIComponent(concernId)}/apply-recommended-action`,
      payload,
      getAdminToken(),
    );
  }

  async function resumeAdminBookingPetGrooming(
    bookingId,
    bookingPetId,
    concernId,
    payload,
  ) {
    return request(
      "POST",
      `${adminBookingPetConcernPath(bookingId, bookingPetId)}/${encodeURIComponent(concernId)}/resume-grooming`,
      payload,
      getAdminToken(),
    );
  }

  async function adminCancelBooking(bookingId) {
    // POST /api/admin/bookings/{id}/cancel  (protected — admin token)
    return request("POST", `/admin/bookings/${bookingId}/cancel`, null, getAdminToken());
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

  async function getCustomerDetails(customerId) {
    // GET /api/admin/customers/{id}  (protected — admin token)
    return request("GET", `/admin/customers/${customerId}`, null, getAdminToken());
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
    return request("POST", "/admin/clinic/reopen-today", null, getAdminToken());
  }

  async function adminUpdateGroomersOnDuty(groomersOnDuty) {
    return request(
      "PATCH",
      "/admin/clinic/settings/groomers-on-duty",
      { groomers_on_duty: groomersOnDuty },
      getAdminToken(),
    );
  }

  async function getAvailabilitySettings() {
    return request(
      "GET",
      "/admin/clinic/settings/availability",
      null,
      getAdminToken(),
    );
  }

  async function adminUpdateAvailability(payload) {
    return request(
      "PATCH",
      "/admin/clinic/settings/availability",
      payload,
      getAdminToken(),
    );
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
    // payload: { final_price, amount_paid, payment_method?, notes?, service_prices? }
    return request("POST", `/admin/bookings/${bookingId}/pay`, payload, getAdminToken());
  }

  async function payNow(bookingId, payload) {
    // POST /api/admin/bookings/{id}/pay-now  (protected — admin token)
    // Early payment while booking is still checked_in; accepts the same payload as processPayment.
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

  async function getTransactions({ search = "", period = "day", date = "", week = "", month = "", year = "" } = {}) {
    // GET /api/admin/transactions  (protected — admin token)
    const params = new URLSearchParams();
    if (search) params.set("search", search);
    if (period) params.set("period", period);
    if (date)   params.set("date",   date);
    if (week)   params.set("week",   week);
    if (month)  params.set("month",  month);
    if (year)   params.set("year",   year);
    const query = params.toString() ? `?${params.toString()}` : "";
    return request("GET", `/admin/transactions${query}`, null, getAdminToken());
  }

  async function getServicesPerformedReport({ period = "day", date = "", week = "", month = "", year = "" } = {}) {
    // GET /api/admin/reports/services-performed  (protected - admin token)
    const params = new URLSearchParams();
    if (period) params.set("period", period);
    if (date)   params.set("date",   date);
    if (week)   params.set("week",   week);
    if (month)  params.set("month",  month);
    if (year)   params.set("year",   year);
    const query = params.toString() ? `?${params.toString()}` : "";
    return request("GET", `/admin/reports/services-performed${query}`, null, getAdminToken());
  }

  async function submitWalkIn(payload) {
    // POST /api/admin/walk-in  (protected — admin token)
    // payload: { fname, lname, mname?, email?, phone, pets: [...], sedation_consent, terms_agreed }
    return request("POST", "/admin/walk-in", payload, getAdminToken());
  }

  async function submitClinicWalkIn(payload) {
    // POST /api/admin/clinic-walk-in  (protected — admin token)
    // payload: { fname, lname, mname?, email?, phone, pet_name, species, breed?, fur_type?, weight?, medical_conditions?, chief_complaint, terms_agreed }
    return request("POST", "/admin/clinic-walk-in", payload, getAdminToken());
  }

  async function getClinicAppointments() {
    return request("GET", "/admin/clinic-appointments", null, getAdminToken());
  }

  async function clinicCheckIn(id) {
    return request("POST", `/admin/clinic-appointments/${id}/check-in`, {}, getAdminToken());
  }

  async function clinicStartConsultation(id) {
    return request("POST", `/admin/clinic-appointments/${id}/start-consultation`, {}, getAdminToken());
  }

  async function clinicFinishConsultation(id) {
    return request("POST", `/admin/clinic-appointments/${id}/finish-consultation`, {}, getAdminToken());
  }

  async function clinicMarkPaid(id, payload) {
    return request("POST", `/admin/clinic-appointments/${id}/pay`, payload, getAdminToken());
  }

  async function clinicCancel(id) {
    return request("POST", `/admin/clinic-appointments/${id}/cancel`, {}, getAdminToken());
  }

  async function clinicSaveRecord(id, payload) {
    return request("POST", `/admin/clinic-appointments/${id}/record`, payload, getAdminToken());
  }

  async function clinicUploadAttachment(id, file, label = "") {
    const formData = new FormData();
    formData.append("file", file);
    if (label) formData.append("label", label);

    return request(
      "POST",
      `/admin/clinic-appointments/${id}/attachments`,
      formData,
      getAdminToken(),
    );
  }

  async function clinicDownloadAttachment(id, attachmentId) {
    return request(
      "GET",
      `/admin/clinic-appointments/${id}/attachments/${attachmentId}/download`,
      null,
      getAdminToken(),
      { responseType: "blob" },
    );
  }

  async function clinicDeleteAttachment(id, attachmentId) {
    return request(
      "DELETE",
      `/admin/clinic-appointments/${id}/attachments/${attachmentId}`,
      null,
      getAdminToken(),
    );
  }

  async function getAdminPetVaccinations(petId) {
    return request(
      "GET",
      `/admin/pets/${encodeURIComponent(petId)}/vaccinations`,
      null,
      getAdminToken(),
    );
  }

  async function getAdminPetVaccination(petId, vaccinationId) {
    return request(
      "GET",
      `/admin/pets/${encodeURIComponent(petId)}/vaccinations/${encodeURIComponent(vaccinationId)}`,
      null,
      getAdminToken(),
    );
  }

  async function createAdminPetVaccination(petId, payload) {
    return request(
      "POST",
      `/admin/pets/${encodeURIComponent(petId)}/vaccinations`,
      payload,
      getAdminToken(),
    );
  }

  async function updateAdminPetVaccination(petId, vaccinationId, payload) {
    return request(
      "PATCH",
      `/admin/pets/${encodeURIComponent(petId)}/vaccinations/${encodeURIComponent(vaccinationId)}`,
      payload,
      getAdminToken(),
    );
  }

  async function publishAdminPetVaccination(petId, vaccinationId) {
    return request(
      "POST",
      `/admin/pets/${encodeURIComponent(petId)}/vaccinations/${encodeURIComponent(vaccinationId)}/publish`,
      {},
      getAdminToken(),
    );
  }

  async function voidAdminPetVaccination(petId, vaccinationId, voidReason) {
    return request(
      "POST",
      `/admin/pets/${encodeURIComponent(petId)}/vaccinations/${encodeURIComponent(vaccinationId)}/void`,
      { void_reason: voidReason },
      getAdminToken(),
    );
  }

  async function getAdminInventoryItems({
    category = "",
    includeInactive = false,
    page = 1,
  } = {}) {
    const params = new URLSearchParams({ page: String(page) });
    if (category) params.set("category", category);
    if (includeInactive) params.set("include_inactive", "1");

    return request(
      "GET",
      `/inventory/items?${params.toString()}`,
      null,
      getAdminToken(),
    );
  }

  async function getCustomerActivityReport({ period = "day", date = "", week = "", month = "", year = "" } = {}) {
    // GET /api/admin/reports/customer-activity  (protected - admin token)
    const params = new URLSearchParams();
    if (period) params.set("period", period);
    if (date)   params.set("date",   date);
    if (week)   params.set("week",   week);
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
    enforceAdminPageAccess,
    isAdminOnlyPage,
    // Auth
    register,
    signIn,
    logout,
    getMe,
    getSystemClock,
    sendChatbotMessage,
    // Timeslots
    getTimeslots,
    getClinicTimeslots,
    // Pets
    getUserPets,
    getPet,
    getPetMedicalRecords,
    getPetVaccinations,
    getPetMedicalConcerns,
    getPetMedicalConcern,
    acknowledgePetMedicalConcern,
    submitPetMedicalConcernConsent,
    addPet,
    updatePet,
    adminUpdatePet,
    archivePet,
    unarchivePet,
    // Booking
    storeBooking,
    getBookingHistory,
    getGroomingCapacity,
    cancelBooking,
    rescheduleBooking,
    submitClinicPreRegistration,
    // Admin bookings
    getAdminBookings,
    adminCheckIn,
    adminStartGrooming,
    adminStartPetGrooming,
    adminMarkDone,
    adminMarkPetDone,
    getAdminBookingPetMedicalConcerns,
    createAdminBookingPetMedicalConcern,
    getAdminBookingPetMedicalConcern,
    updateAdminBookingPetMedicalConcern,
    cancelAdminBookingPetMedicalConcern,
    resolveAdminBookingPetMedicalConcern,
    notifyCustomerAboutAdminBookingPetMedicalConcern,
    applyAdminBookingPetMedicalConcernAction,
    resumeAdminBookingPetGrooming,
    adminCancelBooking,
    // Admin customers
    getCustomers,
    getCustomerDetails,
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
    adminUpdateGroomersOnDuty,
    getAvailabilitySettings,
    adminUpdateAvailability,
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
    // Walk-in
    submitWalkIn,
    submitClinicWalkIn,
    // Clinic queue
    getClinicAppointments,
    clinicCheckIn,
    clinicStartConsultation,
    clinicFinishConsultation,
    clinicMarkPaid,
    clinicCancel,
    clinicSaveRecord,
    clinicUploadAttachment,
    clinicDownloadAttachment,
    clinicDeleteAttachment,
    // Staff vaccination records
    getAdminPetVaccinations,
    getAdminPetVaccination,
    createAdminPetVaccination,
    updateAdminPetVaccination,
    publishAdminPetVaccination,
    voidAdminPetVaccination,
    getAdminInventoryItems,
  };
})();
