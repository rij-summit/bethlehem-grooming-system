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

  function getBaseUrl() {
    return BASE_URL;
  }

  // ── Token keys ────────────────────────────────────────────────────────────
  const CUSTOMER_TOKEN_KEY = "customer_token";
  const ADMIN_TOKEN_KEY    = "admin_token";
  const USER_ROLE_KEY      = "user_role";
  const LOGIN_POLL_TOKEN_KEY = "pending_login_poll_token";
  const LOGIN_CONFIRMATION_EMAIL_KEY = "pending_login_confirmation_email";
  const AUTH_LOGOUT_EVENT_KEY = "bethlehem.auth.logout";
  const AUTH_LAST_ACTIVITY_KEY = "bethlehem.auth.last_activity";
  const INACTIVITY_WARNING_ID = "bethlehem-session-inactivity-warning";
  const ACTIVITY_WRITE_THROTTLE_MS = 1000;
  const SESSION_INACTIVITY_POLICIES = Object.freeze({
    admin: Object.freeze({ timeoutMs: 15 * 60 * 1000, warningMs: 14 * 60 * 1000 }),
    staff: Object.freeze({ timeoutMs: 15 * 60 * 1000, warningMs: 14 * 60 * 1000 }),
    customer: Object.freeze({ timeoutMs: 30 * 60 * 1000, warningMs: 29 * 60 * 1000 }),
  });
  const ADMIN_ONLY_PAGE_NAMES = [
    "reports.html",
    "settings.html",
    "services.html",
    "chatbot-insights.html",
  ];
  const PUBLIC_CLIENT_PAGE_NAMES = new Set([
    "sign-in.html",
    "signup.html",
    "verify-email.html",
    "forgot-password.html",
    "reset-password.html",
  ]);
  const INVALID_SESSION_CODES = new Set([
    "account_disabled",
    "email_not_verified",
  ]);
  const AUTH_STORAGE_KEYS = [
    CUSTOMER_TOKEN_KEY,
    ADMIN_TOKEN_KEY,
    USER_ROLE_KEY,
    AUTH_LAST_ACTIVITY_KEY,
  ];

  let inactivityTimerId = null;
  let inactivityManagerInstalled = false;
  let inactivityLogoutStarted = false;
  let lastActivityWriteAt = 0;

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
    "bethlehem.chatbot.conversation.v1",
  ];
  const BOOKING_LOCAL_KEYS = [
    "bethlehem.bookingFormLock",
    "shownPickupNotifs",
    "clientPets",
  ];

  function clearBookingDraft() {
    BOOKING_SESSION_KEYS.forEach((key) => safeStorageRemove(sessionStorage, key));
    BOOKING_LOCAL_KEYS.forEach((key) => safeStorageRemove(localStorage, key));
  }

  // ── Token helpers ─────────────────────────────────────────────────────────

  function isAdminRole(role) {
    return role === "admin" || role === "staff";
  }

  function safeStorageGet(storage, key) {
    try {
      return storage.getItem(key);
    } catch {
      return null;
    }
  }

  function safeStorageRemove(storage, key) {
    try {
      storage.removeItem(key);
    } catch {
      // Storage can be unavailable in privacy-restricted browser contexts.
    }
  }

  function rawCustomerToken() {
    return safeStorageGet(localStorage, CUSTOMER_TOKEN_KEY)
      || safeStorageGet(sessionStorage, CUSTOMER_TOKEN_KEY);
  }

  function rawAdminToken() {
    return safeStorageGet(localStorage, ADMIN_TOKEN_KEY)
      || safeStorageGet(sessionStorage, ADMIN_TOKEN_KEY);
  }

  function readAuthSessionFrom(storage, persistent) {
    const role = safeStorageGet(storage, USER_ROLE_KEY);
    const tokenKey = isAdminRole(role) ? ADMIN_TOKEN_KEY : CUSTOMER_TOKEN_KEY;
    const token = role === "customer" || isAdminRole(role)
      ? safeStorageGet(storage, tokenKey)
      : null;

    return token ? { token, role, tokenKey, persistent } : null;
  }

  function getAuthSession() {
    const persistent = readAuthSessionFrom(localStorage, true);
    const temporary = readAuthSessionFrom(sessionStorage, false);

    // Mixed persistent/temporary sessions are ambiguous legacy state. Treat
    // them as signed out instead of guessing and sending an obsolete token.
    if (persistent && temporary) return null;

    return persistent || temporary;
  }

  function clearAuthStorage() {
    AUTH_STORAGE_KEYS.forEach((key) => {
      safeStorageRemove(localStorage, key);
      safeStorageRemove(sessionStorage, key);
    });
    clearPendingLoginConfirmation();
    pauseInactivityTracking();
  }

  function clearPendingLoginConfirmation() {
    safeStorageRemove(sessionStorage, LOGIN_POLL_TOKEN_KEY);
    safeStorageRemove(sessionStorage, LOGIN_CONFIRMATION_EMAIL_KEY);
  }

  function storePendingLoginConfirmation(pollToken, email = "") {
    if (!pollToken) {
      throw new Error("The server did not return a valid login confirmation.");
    }

    clearAuthStorage();
    try {
      sessionStorage.setItem(LOGIN_POLL_TOKEN_KEY, pollToken);
      sessionStorage.setItem(LOGIN_CONFIRMATION_EMAIL_KEY, email);
    } catch (error) {
      clearPendingLoginConfirmation();
      throw error;
    }
  }

  function hasPendingLoginConfirmation() {
    return !!safeStorageGet(sessionStorage, LOGIN_POLL_TOKEN_KEY);
  }

  function getPendingLoginConfirmationEmail() {
    return safeStorageGet(sessionStorage, LOGIN_CONFIRMATION_EMAIL_KEY) || "";
  }

  function setAuthSession(token, role, remember = true) {
    if (!token || (role !== "customer" && !isAdminRole(role))) {
      throw new Error("The server returned an invalid authentication session.");
    }

    const persistent = isAdminRole(role) || remember;
    const storage = persistent ? localStorage : sessionStorage;
    const tokenKey = isAdminRole(role) ? ADMIN_TOKEN_KEY : CUSTOMER_TOKEN_KEY;

    // A browser has one current Bethlehem session. Clear every old token/role
    // first so localStorage can never shadow a newer sessionStorage login.
    clearAuthStorage();

    try {
      storage.setItem(tokenKey, token);
      storage.setItem(USER_ROLE_KEY, role);
    } catch (error) {
      // Do not leave a half-written token without its matching role.
      clearAuthStorage();
      throw error;
    }

    inactivityLogoutStarted = false;
    const session = getAuthSession();
    const timestamp = Date.now();
    writeSessionActivity(session, timestamp);
    lastActivityWriteAt = timestamp;
    if (inactivityManagerInstalled) evaluateSessionInactivity(timestamp);
  }

  function getCustomerToken() {
    const session = getAuthSession();
    return session?.role === "customer" ? session.token : null;
  }

  function setCustomerToken(token, remember = true) {
    setAuthSession(token, "customer", remember);
  }

  function clearCustomerToken() {
    const role = getUserRole();
    safeStorageRemove(localStorage, CUSTOMER_TOKEN_KEY);
    safeStorageRemove(sessionStorage, CUSTOMER_TOKEN_KEY);

    if (role === "customer") {
      clearUserRole();
    }
  }

  function getUserRole() {
    return getAuthSession()?.role ?? null;
  }

  function setUserRole(role, remember = true) {
    const token = isAdminRole(role) ? rawAdminToken() : rawCustomerToken();
    if (token) {
      setAuthSession(token, role, remember);
      return;
    }

    clearUserRole();
  }

  function clearUserRole() {
    safeStorageRemove(localStorage, USER_ROLE_KEY);
    safeStorageRemove(sessionStorage, USER_ROLE_KEY);
  }

  function getAdminToken() {
    const session = getAuthSession();
    return isAdminRole(session?.role) ? session.token : null;
  }

  function setAdminToken(token) {
    setAuthSession(token, "admin", true);
  }

  function clearAdminToken() {
    const role = getUserRole();
    safeStorageRemove(localStorage, ADMIN_TOKEN_KEY);
    safeStorageRemove(sessionStorage, ADMIN_TOKEN_KEY);

    if (isAdminRole(role)) {
      clearUserRole();
    }
  }

  function isAdminPage() {
    return window.location.pathname.replace(/\\/g, "/").includes("/pages/admin/");
  }

  function isSignInPage() {
    return window.location.pathname.replace(/\\/g, "/").includes("/pages/client/sign-in.html");
  }

  function isProtectedClientPage() {
    const path = window.location.pathname.replace(/\\/g, "/");
    if (!path.includes("/pages/client/")) return false;
    return !PUBLIC_CLIENT_PAGE_NAMES.has(currentPageName());
  }

  function appBasePath(pathname = window.location.pathname) {
    const path = String(pathname || "/").replace(/\\/g, "/");
    const markerIndex = path.toLowerCase().indexOf("/pages/");

    if (markerIndex >= 0) {
      return path.slice(0, markerIndex).replace(/\/$/, "");
    }

    const directory = path.endsWith("/")
      ? path.replace(/\/$/, "")
      : path.slice(0, path.lastIndexOf("/"));

    return directory === "/" ? "" : directory;
  }

  function appPath(relativePath) {
    const suffix = String(relativePath || "").replace(/^\/+/, "");
    return `${appBasePath()}/${suffix}`.replace(/\/{2,}/g, "/");
  }

  function signInPath() {
    return appPath("pages/client/sign-in.html");
  }

  function redirectToSignIn({ replace = true } = {}) {
    if (!isSignInPage()) {
      const target = signInPath();
      if (replace && typeof window.location.replace === "function") {
        window.location.replace(target);
      } else {
        window.location.href = target;
      }
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
      window.location.replace(appPath("pages/admin/dashboard.html"));
    }
  }

  function hasAuthenticatedSession(scope = "any") {
    const session = getAuthSession();
    if (!session) return false;
    if (scope === "customer") return session.role === "customer";
    if (scope === "admin") return isAdminRole(session.role);
    return true;
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

  function enforceProtectedPageAccess() {
    if (isAdminPage()) return enforceAdminPageAccess();

    if (isProtectedClientPage() && !hasAuthenticatedSession("customer")) {
      redirectToSignIn();
      return false;
    }

    return true;
  }

  function notifyLogout(role, reason = "logout") {
    try {
      localStorage.setItem(
        AUTH_LOGOUT_EVENT_KEY,
        JSON.stringify({ role, reason, at: Date.now() })
      );
    } catch {
      // Non-fatal: removing the token still syncs logout in supported browsers.
    }
  }

  function invalidateSession(role = null, reason = "expired") {
    const resolvedRole = getAuthSession()?.role ?? role ?? "customer";
    clearAuthStorage();
    if (resolvedRole === "customer") clearBookingDraft();
    notifyLogout(resolvedRole, reason);
    redirectToSignIn();
  }

  function handleCrossTabLogout(role) {
    clearAuthStorage();
    if (role === "customer") clearBookingDraft();

    if (isAdminPage() || isProtectedClientPage()) {
      redirectToSignIn();
    }
  }

  function getSessionInactivityPolicy(role = getUserRole()) {
    return SESSION_INACTIVITY_POLICIES[role] || null;
  }

  function activitySessionId(session) {
    if (!session?.token || !session?.role) return "";

    const tokenId = String(session.token).split("|", 1)[0];
    return `${session.role}:${tokenId}`;
  }

  function readSessionActivity(session) {
    const expectedSessionId = activitySessionId(session);
    if (!expectedSessionId) return null;

    const stored = safeStorageGet(localStorage, AUTH_LAST_ACTIVITY_KEY)
      || safeStorageGet(sessionStorage, AUTH_LAST_ACTIVITY_KEY);
    if (!stored) return null;

    try {
      const activity = JSON.parse(stored);
      const timestamp = Number(activity?.at);
      return activity?.session === expectedSessionId && Number.isFinite(timestamp)
        ? timestamp
        : null;
    } catch {
      return null;
    }
  }

  function writeSessionActivity(session, timestamp) {
    const sessionId = activitySessionId(session);
    if (!sessionId) return;

    const value = JSON.stringify({ session: sessionId, at: timestamp });
    try {
      localStorage.setItem(AUTH_LAST_ACTIVITY_KEY, value);
    } catch {
      try {
        sessionStorage.setItem(AUTH_LAST_ACTIVITY_KEY, value);
      } catch {
        // The in-memory timer still protects this tab when storage is unavailable.
      }
    }
  }

  function isManagedProtectedSession(session = getAuthSession()) {
    if (!session || !getSessionInactivityPolicy(session.role)) return false;
    if (isAdminPage()) return isAdminRole(session.role);
    return isProtectedClientPage() && session.role === "customer";
  }

  function inactivityWarningElement() {
    if (typeof document === "undefined") return null;
    return document.getElementById?.(INACTIVITY_WARNING_ID) || null;
  }

  function ensureInactivityWarningElement() {
    const existing = inactivityWarningElement();
    if (existing) return existing;
    if (
      typeof document === "undefined"
      || typeof document.createElement !== "function"
      || !document.body
    ) {
      return null;
    }

    const toast = document.createElement("div");
    toast.id = INACTIVITY_WARNING_ID;
    toast.setAttribute("role", "status");
    toast.setAttribute("aria-live", "polite");
    toast.textContent = "Your session will expire soon due to inactivity.";
    toast.style.cssText = [
      "position: fixed",
      "right: 1rem",
      "bottom: 1rem",
      "z-index: 10001",
      "display: none",
      "max-width: min(24rem, calc(100vw - 2rem))",
      "border: 1px solid #f59e0b",
      "border-radius: 0.875rem",
      "background: #fff7ed",
      "padding: 0.875rem 1rem",
      "color: #9a3412",
      "font-size: 0.875rem",
      "font-weight: 700",
      "line-height: 1.4",
      "box-shadow: 0 16px 35px rgba(15, 23, 42, 0.22)",
    ].join(";");
    document.body.appendChild(toast);
    return toast;
  }

  function showInactivityWarning() {
    const toast = ensureInactivityWarningElement();
    if (toast) toast.style.display = "block";
  }

  function hideInactivityWarning() {
    const toast = inactivityWarningElement();
    if (toast) toast.style.display = "none";
  }

  function pauseInactivityTracking() {
    if (inactivityTimerId !== null) {
      clearTimeout(inactivityTimerId);
      inactivityTimerId = null;
    }
    hideInactivityWarning();
  }

  function scheduleInactivityCheck(delayMs) {
    if (inactivityTimerId !== null) clearTimeout(inactivityTimerId);
    inactivityTimerId = setTimeout(
      () => evaluateSessionInactivity(),
      Math.max(0, Math.ceil(delayMs)),
    );
  }

  function expireSessionForInactivity(session) {
    if (inactivityLogoutStarted) return;
    inactivityLogoutStarted = true;
    pauseInactivityTracking();

    // logout() clears browser state synchronously and starts a keepalive
    // revocation request before navigation, so the abandoned token is unusable.
    void logout(session.role, { reason: "inactivity", keepalive: true }).catch(() => {});
    redirectToSignIn({ replace: true });
  }

  function evaluateSessionInactivity(timestamp = Date.now()) {
    const session = getAuthSession();
    if (!isManagedProtectedSession(session)) {
      pauseInactivityTracking();
      return { state: "inactive", idleMs: 0 };
    }

    const policy = getSessionInactivityPolicy(session.role);
    let lastActivityAt = readSessionActivity(session);
    if (lastActivityAt === null) {
      lastActivityAt = timestamp;
      writeSessionActivity(session, lastActivityAt);
      lastActivityWriteAt = lastActivityAt;
    }

    const idleMs = Math.max(0, timestamp - lastActivityAt);
    if (idleMs >= policy.timeoutMs) {
      expireSessionForInactivity(session);
      return { state: "expired", idleMs };
    }

    if (idleMs >= policy.warningMs) {
      showInactivityWarning();
      scheduleInactivityCheck(policy.timeoutMs - idleMs);
      return { state: "warning", idleMs };
    }

    hideInactivityWarning();
    scheduleInactivityCheck(policy.warningMs - idleMs);
    return { state: "active", idleMs };
  }

  function recordSessionActivity(force = false) {
    const session = getAuthSession();
    if (!isManagedProtectedSession(session) || inactivityLogoutStarted) return;

    const timestamp = Date.now();
    if (!force && timestamp - lastActivityWriteAt < ACTIVITY_WRITE_THROTTLE_MS) {
      return;
    }

    lastActivityWriteAt = timestamp;
    writeSessionActivity(session, timestamp);
    hideInactivityWarning();
    const policy = getSessionInactivityPolicy(session.role);
    scheduleInactivityCheck(policy.warningMs);
  }

  function startSessionInactivityManager() {
    const session = getAuthSession();
    if (!isManagedProtectedSession(session)) return false;
    if (
      typeof document === "undefined"
      || typeof document.addEventListener !== "function"
      || typeof document.createElement !== "function"
    ) {
      return false;
    }

    inactivityLogoutStarted = false;

    if (!inactivityManagerInstalled) {
      const onActivity = () => recordSessionActivity(false);
      document.addEventListener("pointerdown", onActivity, { passive: true });
      document.addEventListener("mousemove", onActivity, { passive: true });
      document.addEventListener("keydown", onActivity);
      document.addEventListener("touchstart", onActivity, { passive: true });
      document.addEventListener("scroll", onActivity, { passive: true, capture: true });
      document.addEventListener("visibilitychange", () => {
        if (document.visibilityState === "visible") evaluateSessionInactivity();
      });
      window.addEventListener("focus", () => evaluateSessionInactivity());
      inactivityManagerInstalled = true;
    }

    evaluateSessionInactivity();
    return true;
  }

  if (typeof window !== "undefined") {
    window.addEventListener("storage", (event) => {
      if (event.storageArea !== localStorage) return;

      if (event.key === AUTH_LAST_ACTIVITY_KEY && event.newValue) {
        evaluateSessionInactivity();
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

    if (enforceProtectedPageAccess()) startSessionInactivityManager();
    window.addEventListener("pageshow", () => {
      // A logout page navigation can leave a protected dashboard in the
      // browser back-forward cache. Re-check storage whenever it is restored.
      if (enforceProtectedPageAccess()) startSessionInactivityManager();
    });
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
    if (requestOptions.keepalive) {
      options.keepalive = true;
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

    // A response for an older in-flight request must never erase a newer
    // login that replaced its bearer token while the request was pending.
    const currentSession = getAuthSession();
    const responseBelongsToCurrentSession = !!token
      && currentSession?.token === token;
    const invalidAccountSession = response.status === 403
      && responseBelongsToCurrentSession
      && INVALID_SESSION_CODES.has(data.code);
    const lostAuthenticatedSession = response.status === 401
      && responseBelongsToCurrentSession;

    if (
      (lostAuthenticatedSession || invalidAccountSession)
      && !requestOptions.suppressAuthRedirect
    ) {
      // Public sign-in/register failures have no bearer token and therefore
      // fall through for their forms to render. Only a real authenticated
      // session failure clears state and navigates away.
      const failedRole = token === rawAdminToken() ? "admin" : "customer";
      invalidateSession(
        failedRole,
        invalidAccountSession ? data.code : "expired",
      );
    }

    if (response.status === 429) {
      const error = new Error(data.message || "Too many attempts. Please wait before trying again.");
      error.status = 429;
      error.retryAfter = data.retry_after ?? null;
      error.errors = null;
      throw error;
    }

    if (!response.ok) {
      if (
        response.status === 403
        && !invalidAccountSession
        && token === getAdminToken()
        && isAdminOnlyPage()
      ) {
        redirectToAdminDashboard();
      }

      const error = new Error(data.message || "Something went wrong.");
      error.status = response.status;
      error.errors = data.errors || null;
      error.emailNotVerified = data.email_not_verified ?? false;
      error.email = data.email ?? null;
      error.expired = data.expired ?? false;
      error.code = data.code || null;
      error.data = data;
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

  function isAuthenticationError(error) {
    return error?.status === 401
      || (error?.status === 403 && INVALID_SESSION_CODES.has(error?.code));
  }

  // ── Auth API calls ────────────────────────────────────────────────────────

  async function register(payload) {
    // POST /api/register
    // payload: { first_name, last_name, username?, email, phone, password, password_confirmation }
    // Registration establishes a browser session only after the customer
    // follows the verification link sent to their email address.
    return request("POST", "/register", payload);
  }

  async function signIn(identifier, password, remember = true) {
    // POST /api/sign-in
    // Accepts email, phone (09... or +639...), or username. Saves to the
    // correct token key based on role. remember=false uses sessionStorage so
    // the session dies when the tab closes.
    const normalized = normalizePhoneLikeIdentifier(identifier);
    const resolvedIdentifier = normalized || identifier;
    const data = await request("POST", "/sign-in", {
      identifier: resolvedIdentifier,
      password,
      remember,
    });

    if (data?.requires_login_confirmation) {
      storePendingLoginConfirmation(data?.login_poll_token, data?.email);
      return data;
    }

    const role = data?.user?.role;
    if (role === "customer") clearBookingDraft();
    setAuthSession(data?.token, role, isAdminRole(role) ? true : remember);
    return data;
  }

  async function requestPasswordReset(email) {
    return request("POST", "/password/forgot", { email });
  }

  async function verifyPasswordResetToken(token) {
    return request("POST", "/password/reset/verify", { token });
  }

  async function resetPassword(token, password, passwordConfirmation) {
    return request("POST", "/password/reset", {
      token,
      password,
      password_confirmation: passwordConfirmation,
    });
  }

  async function verifyEmail(token) {
    // POST /api/email/verify  { token }
    // Successful customer signup verification also establishes the first
    // authenticated browser session.
    const data = await request("POST", "/email/verify", { token });
    if (data?.token && data?.user?.role === "customer") {
      clearBookingDraft();
      setAuthSession(data.token, "customer", true);
    }
    return data;
  }

  async function adminGetPetProfile(petId) {
    return request("GET", `/admin/pets/${encodeURIComponent(petId)}/profile`, null, getAdminToken());
  }

  async function resendVerification(email) {
    // POST /api/email/resend  { email }
    return request("POST", "/email/resend", { email });
  }

  async function resendLoginCode() {
    const pollToken = safeStorageGet(sessionStorage, LOGIN_POLL_TOKEN_KEY);
    if (!pollToken) {
      throw new Error("This browser no longer has a pending sign-in. Please sign in again.");
    }
    return request("POST", "/email/login/resend", { poll_token: pollToken });
  }

  async function confirmLoginCode(code) {
    const pollToken = safeStorageGet(sessionStorage, LOGIN_POLL_TOKEN_KEY);
    if (!pollToken) {
      throw new Error("This browser no longer has a pending sign-in. Please sign in again.");
    }

    try {
      const data = await request("POST", "/email/login/confirm", {
        poll_token: pollToken,
        code,
      });

      const role = data?.user?.role;
      if (!data?.token || !["customer", "admin", "staff"].includes(role)) {
        throw new Error("The server returned an invalid session.");
      }

      if (role === "customer") clearBookingDraft();
      setAuthSession(
        data.token,
        role,
        isAdminRole(role) ? true : data?.remember_me !== false,
      );

      // Acknowledge only after the browser has stored the session. Failure is
      // non-fatal: the approved challenge remains retryable until it expires.
      try {
        await request(
          "POST",
          "/email/login/complete",
          { poll_token: pollToken },
          data.token,
          { suppressAuthRedirect: true },
        );
      } catch {
        // The authenticated session is already safely stored.
      }

      return data;
    } catch (error) {
      if (error?.expired || error?.status === 403 || error?.status === 429) {
        clearPendingLoginConfirmation();
      }
      throw error;
    }
  }

  async function logout(role = null, logoutOptions = {}) {
    // POST /api/logout  (protected — sends the correct token in the header)
    // Capture the current bearer token, then clear the browser before waiting
    // for the network. Logout remains immediate even if the server is down.
    const currentSession = getAuthSession();
    const resolvedRole = currentSession?.role ?? role ?? "customer";
    const token = currentSession?.token
      ?? (isAdminRole(role) ? rawAdminToken() : rawCustomerToken());

    clearAuthStorage();
    if (resolvedRole === "customer") clearBookingDraft();
    notifyLogout(resolvedRole, logoutOptions.reason || "logout");

    if (!token) return { success: true, local_only: true };

    try {
      return await request(
        "POST",
        "/logout",
        null,
        token,
        {
          suppressAuthRedirect: true,
          keepalive: logoutOptions.keepalive === true,
        },
      );
    } catch (error) {
      // A missing/expired server session is already equivalent to logout.
      if (error?.status === 401) {
        return { success: true, local_only: true };
      }
      throw error;
    }
  }

  async function getMe(role = "customer") {
    // GET /api/me  (protected)
    const token =
      isAdminRole(role) ? getAdminToken() : getCustomerToken();
    return request("GET", "/me", null, token);
  }

  async function getSystemClock() {
    return request("GET", "/system/clock");
  }

  async function sendChatbotMessage(message, history = []) {
    // POST /api/chatbot  (public)
    return request("POST", "/chatbot", {
      message,
      history: Array.isArray(history) ? history : [],
    }, getCustomerToken(), { suppressAuthRedirect: true });
  }

  async function sendChatbotFeedback(feedbackToken, helpful) {
    return request("POST", "/chatbot/feedback", {
      feedback_token: feedbackToken,
      helpful: Boolean(helpful),
    });
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

  async function adminAddCustomerPet(ownerType, ownerId, payload) {
    return request(
      "POST",
      `/admin/customer-pets/${encodeURIComponent(ownerType)}/${encodeURIComponent(ownerId)}`,
      payload,
      getAdminToken(),
    );
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

  async function getPreRegistrationAccess() {
    // GET /api/pre-registration/access (protected)
    return request(
      "GET",
      "/pre-registration/access",
      null,
      getCustomerToken(),
    );
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

  async function adminRecordSedationConsent(bookingId) {
    return request(
      "POST",
      `/admin/bookings/${bookingId}/sedation-consent`,
      { customer_understood_and_agreed: true },
      getAdminToken(),
    );
  }

  async function adminRevertCheckIn(bookingId) {
    return request(
      "POST",
      `/admin/bookings/${bookingId}/revert-check-in`,
      null,
      getAdminToken(),
    );
  }

  async function adminStartGrooming(bookingId) {
    // POST /api/admin/bookings/{id}/start-grooming  (protected — admin token)
    return request("POST", `/admin/bookings/${bookingId}/start-grooming`, null, getAdminToken());
  }

  async function adminRevertStartGrooming(bookingId) {
    return request(
      "POST",
      `/admin/bookings/${bookingId}/revert-start-grooming`,
      null,
      getAdminToken(),
    );
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

  async function adminCancelBooking(bookingId, cancellationReason = "") {
    // POST /api/admin/bookings/{id}/cancel  (protected — admin token)
    const reason = String(cancellationReason ?? "").trim();

    return request(
      "POST",
      `/admin/bookings/${bookingId}/cancel`,
      { cancellation_reason: reason || null },
      getAdminToken(),
    );
  }

  async function getNotifications(filters = {}) {
    // GET /api/admin/notifications  (protected — admin token)
    const params = new URLSearchParams();

    if (filters.status) params.set("status", filters.status);
    if (filters.category) params.set("category", filters.category);
    if (filters.mode) params.set("mode", filters.mode);
    if (filters.page) params.set("page", String(filters.page));
    if (filters.per_page) params.set("per_page", String(filters.per_page));

    const query = params.toString() ? `?${params.toString()}` : "";
    return request("GET", `/admin/notifications${query}`, null, getAdminToken());
  }

  async function markNotificationRead(notificationId) {
    // PATCH /api/admin/notifications/{id}/read  (protected — admin token)
    return request("PATCH", `/admin/notifications/${notificationId}/read`, null, getAdminToken());
  }

  // ── Customer management (admin only) ─────────────────────────────────────

  async function searchAdminDashboard(search = "") {
    const params = new URLSearchParams({ q: String(search).trim() });
    return request("GET", `/admin/dashboard/search?${params.toString()}`, null, getAdminToken());
  }

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

  async function createUnregisteredCustomer(payload) {
    return request("POST", "/admin/customers/unregistered", payload, getAdminToken());
  }

  async function getUnregisteredCustomerDetails(customerId) {
    return request("GET", `/admin/customers/unregistered/${customerId}`, null, getAdminToken());
  }

  async function archiveUnregisteredCustomer(customerId) {
    return request("POST", `/admin/customers/unregistered/${customerId}/archive`, null, getAdminToken());
  }

  async function deleteUnregisteredCustomer(customerId, confirmationName) {
    return request(
      "DELETE",
      `/admin/customers/unregistered/${customerId}`,
      { confirmation_name: confirmationName },
      getAdminToken(),
    );
  }

  async function searchWalkInCustomers(search = "") {
    const params = new URLSearchParams({ q: String(search).trim() });
    return request("GET", `/admin/walk-in/customers?${params.toString()}`, null, getAdminToken());
  }

  async function validateWalkInNewOwner(payload) {
    return request(
      "POST",
      "/admin/walk-in/customers/validate-new-owner",
      payload,
      getAdminToken(),
    );
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

  async function deleteCustomer(customerId, confirmationName) {
    return request(
      "DELETE",
      `/admin/customers/${customerId}`,
      { confirmation_name: confirmationName },
      getAdminToken(),
    );
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

  async function getArchivedClinicAppointments({ search = "", date = "" } = {}) {
    const params = new URLSearchParams();
    if (search) params.set("search", search);
    if (date) params.set("date", date);
    const query = params.toString() ? `?${params.toString()}` : "";
    return request("GET", `/admin/clinic-appointments/archived${query}`, null, getAdminToken());
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

  async function getAdminSecurityAccounts() {
    return request("GET", "/admin/security/accounts", null, getAdminToken());
  }

  async function requestAdminCredentialChange(payload) {
    return request(
      "POST",
      "/admin/security/account/credential-change",
      payload,
      getAdminToken(),
    );
  }

  async function requestStaffCredentialChange(staffId, payload) {
    return request(
      "POST",
      `/admin/security/staff/${encodeURIComponent(staffId)}/credential-change`,
      payload,
      getAdminToken(),
    );
  }

  async function requestStaffAccount(payload) {
    return request(
      "POST",
      "/admin/security/staff-accounts",
      payload,
      getAdminToken(),
    );
  }

  async function confirmStaffAccountEmail(pendingStaffId, code) {
    return request(
      "POST",
      `/admin/security/staff-accounts/${encodeURIComponent(pendingStaffId)}/confirm`,
      { code },
      getAdminToken(),
    );
  }

  async function resendStaffAccountEmailCode(pendingStaffId) {
    return request(
      "POST",
      `/admin/security/staff-accounts/${encodeURIComponent(pendingStaffId)}/resend`,
      null,
      getAdminToken(),
    );
  }

  async function updateStaffAccountStatus(staffId, active) {
    return request(
      "PATCH",
      `/admin/security/staff/${encodeURIComponent(staffId)}/status`,
      { active },
      getAdminToken(),
    );
  }

  async function confirmSecurityCredentialChange(changeId, code) {
    return request(
      "POST",
      `/admin/security/credential-changes/${encodeURIComponent(changeId)}/confirm`,
      { code },
      getAdminToken(),
    );
  }

  async function resendSecurityCredentialChangeCode(changeId) {
    return request(
      "POST",
      `/admin/security/credential-changes/${encodeURIComponent(changeId)}/resend`,
      null,
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

  async function getCustomerNotifications(options = {}) {
    // GET /api/customer/notifications  (protected — customer token)
    const page = Number(options.page || 1);
    const params = new URLSearchParams();
    if (page > 1) params.set("page", String(page));
    if (options.sort === "recent") params.set("sort", "recent");
    if (options.status === "unread") params.set("status", "unread");
    const suffix = params.size ? `?${params.toString()}` : "";
    return request("GET", `/customer/notifications${suffix}`, null, getCustomerToken());
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
    // payload: { owner_record_type, customer_user_id?, unregistered_customer_id?, pet_id?, pet_name, species, breed?, gender?, birthdate?, is_neutered?, neutered_date?, fur_type?, weight?, size?, color?, medical_conditions?, chief_complaint, terms_agreed }
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

  async function getAdminPetProfile(petId) {
    return request(
      "GET",
      `/admin/pets/${encodeURIComponent(petId)}/profile`,
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

  async function getChatbotInsights({
    status = "all",
    reason = "",
    search = "",
    page = 1,
    perPage = 25,
  } = {}) {
    const params = new URLSearchParams({
      status,
      page: String(page),
      per_page: String(perPage),
    });
    if (reason) params.set("reason", reason);
    if (search) params.set("search", search);

    return request(
      "GET",
      `/admin/chatbot-insights?${params.toString()}`,
      null,
      getAdminToken(),
    );
  }

  async function updateChatbotInsightStatus(insightId, status) {
    return request(
      "PATCH",
      `/admin/chatbot-insights/${insightId}/status`,
      { status },
      getAdminToken(),
    );
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
    // Resolved endpoint (shared by standalone browser services).
    getBaseUrl,
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
    clearAuthState: clearAuthStorage,
    hasAuthenticatedSession,
    isAuthenticationError,
    invalidateSession,
    redirectToSignIn,
    signInPath,
    getSessionInactivityPolicy,
    recordSessionActivity,
    evaluateSessionInactivity,
    startSessionInactivityManager,
    enforceAdminPageAccess,
    enforceProtectedPageAccess,
    isAdminOnlyPage,
    // Auth
    register,
    signIn,
    requestPasswordReset,
    verifyPasswordResetToken,
    resetPassword,
    logout,
    getMe,
    getSystemClock,
    sendChatbotMessage,
    sendChatbotFeedback,
    // Timeslots
    getTimeslots,
    getClinicTimeslots,
    // Pets
    getUserPets,
    getPet,
    getPetMedicalRecords,
    getPetVaccinations,
    addPet,
    updatePet,
    adminUpdatePet,
    adminAddCustomerPet,
    archivePet,
    unarchivePet,
    // Booking
    storeBooking,
    getPreRegistrationAccess,
    getBookingHistory,
    getGroomingCapacity,
    cancelBooking,
    rescheduleBooking,
    submitClinicPreRegistration,
    // Admin bookings
    getAdminBookings,
    adminCheckIn,
    adminRecordSedationConsent,
    adminRevertCheckIn,
    adminStartGrooming,
    adminRevertStartGrooming,
    adminStartPetGrooming,
    adminMarkDone,
    adminMarkPetDone,
    adminCancelBooking,
    // Admin customers
    searchAdminDashboard,
    getCustomers,
    getCustomerDetails,
    createUnregisteredCustomer,
    getUnregisteredCustomerDetails,
    archiveUnregisteredCustomer,
    deleteUnregisteredCustomer,
    searchWalkInCustomers,
    validateWalkInNewOwner,
    deactivateCustomer,
    reactivateCustomer,
    archiveCustomer,
    unarchiveCustomer,
    deleteCustomer,
    // Admin archive
    adminArchiveBooking,
    getArchivedBookings,
    getArchivedClinicAppointments,
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
    getAdminSecurityAccounts,
    requestAdminCredentialChange,
    requestStaffCredentialChange,
    requestStaffAccount,
    confirmStaffAccountEmail,
    resendStaffAccountEmailCode,
    updateStaffAccountStatus,
    confirmSecurityCredentialChange,
    resendSecurityCredentialChangeCode,
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
    getChatbotInsights,
    updateChatbotInsightStatus,
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
    // Staff pet profile and vaccination records
    getAdminPetProfile,
    getAdminPetVaccinations,
    getAdminPetVaccination,
    createAdminPetVaccination,
    updateAdminPetVaccination,
    publishAdminPetVaccination,
    voidAdminPetVaccination,
    getAdminInventoryItems,
    // Email verification
    verifyEmail,
    adminGetPetProfile,
    resendVerification,
    resendLoginCode,
    confirmLoginCode,
    hasPendingLoginConfirmation,
    getPendingLoginConfirmationEmail,
    clearPendingLoginConfirmation,
  };
})();
