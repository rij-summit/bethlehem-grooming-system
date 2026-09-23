const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const projectRoot = path.resolve(__dirname, "..");
const apiSource = fs.readFileSync(path.join(projectRoot, "scripts/api.js"), "utf8");

class MemoryStorage {
  constructor(seed = {}) {
    this.values = new Map(Object.entries(seed));
  }

  getItem(key) {
    return this.values.has(key) ? this.values.get(key) : null;
  }

  setItem(key, value) {
    this.values.set(key, String(value));
  }

  removeItem(key) {
    this.values.delete(key);
  }
}

function jsonResponse(status, data) {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: async () => data,
    headers: { get: () => null },
  };
}

function createBrowser({
  pathname = "/pages/client/sign-in.html",
  local = {},
  session = {},
  elements = {},
  fetchImpl = async () => jsonResponse(200, {}),
} = {}) {
  const localStorage = new MemoryStorage(local);
  const sessionStorage = new MemoryStorage(session);
  const listeners = new Map();
  const replacements = [];
  const location = {
    protocol: "http:",
    hostname: "127.0.0.1",
    port: "8000",
    origin: "http://127.0.0.1:8000",
    pathname,
    href: "",
    replace(target) {
      replacements.push(target);
      this.href = target;
    },
  };
  const window = {
    location,
    addEventListener(name, handler) {
      const handlers = listeners.get(name) || [];
      handlers.push(handler);
      listeners.set(name, handlers);
    },
  };
  const context = {
    AbortController,
    FormData: class FormData {},
    URLSearchParams,
    clearTimeout,
    console,
    document: {
      readyState: "loading",
      querySelector: () => null,
      getElementById: (id) => elements[id] || null,
      addEventListener() {},
    },
    fetch: (...args) => fetchImpl(...args),
    localStorage,
    sessionStorage,
    setTimeout,
    window,
  };

  vm.createContext(context);
  vm.runInContext(apiSource, context, { filename: "scripts/api.js" });

  return {
    API: context.API,
    localStorage,
    sessionStorage,
    location,
    replacements,
    dispatch(name, event = {}) {
      for (const handler of listeners.get(name) || []) handler(event);
    },
  };
}

async function testLoginCodeHonorsRememberMeStorage() {
  const requests = [];
  const browser = createBrowser({
    local: { customer_token: "obsolete-local-token", user_role: "customer" },
    session: {
      pending_login_poll_token: "login-poll-token",
      pending_login_confirmation_email: "customer@example.com",
    },
    fetchImpl: async (url, options) => {
      requests.push({ url, body: JSON.parse(options.body) });
      if (url.endsWith("/email/login/complete")) {
        return jsonResponse(200, { success: true });
      }

      return jsonResponse(200, {
        approved: true,
        session_established: true,
        token: "new-session-token",
        remember_me: false,
        user: { role: "customer" },
      });
    },
  });

  await browser.API.confirmLoginCode("012345");

  assert.equal(browser.API.getBaseUrl(), "http://127.0.0.1:8000/api");
  assert.equal(browser.localStorage.getItem("customer_token"), null);
  assert.equal(browser.localStorage.getItem("user_role"), null);
  assert.equal(browser.sessionStorage.getItem("customer_token"), "new-session-token");
  assert.equal(browser.sessionStorage.getItem("user_role"), "customer");
  assert.equal(browser.API.getCustomerToken(), "new-session-token");
  assert.deepEqual(requests, [
    {
      url: "http://127.0.0.1:8000/api/email/login/confirm",
      body: { poll_token: "login-poll-token", code: "012345" },
    },
    {
      url: "http://127.0.0.1:8000/api/email/login/complete",
      body: { poll_token: "login-poll-token" },
    },
  ]);
}

async function testProfileNameLivesAndDiesWithTheSession() {
  const profileKey = "bethlehem.customer.profile_name";
  const user = { role: "customer", first_name: "Ridge", last_name: "Marino" };
  const confirm = (rememberMe) => createBrowser({
    session: {
      pending_login_poll_token: "login-poll-token",
      pending_login_confirmation_email: "customer@example.com",
    },
    fetchImpl: async (url) => url.endsWith("/email/login/complete")
      ? jsonResponse(200, { success: true })
      : jsonResponse(200, {
        approved: true,
        session_established: true,
        token: "session-token",
        remember_me: rememberMe,
        user,
      }),
  });

  const sessionOnly = confirm(false);
  await sessionOnly.API.confirmLoginCode("012345");
  assert.ok(sessionOnly.sessionStorage.getItem(profileKey), "Name should be stored with a session-only login.");
  assert.equal(sessionOnly.localStorage.getItem(profileKey), null);
  sessionOnly.API.clearAuthState();
  assert.equal(sessionOnly.sessionStorage.getItem(profileKey), null);

  const remembered = confirm(true);
  await remembered.API.confirmLoginCode("012345");
  assert.ok(remembered.localStorage.getItem(profileKey), "Name should be stored with a remembered login.");
  assert.equal(remembered.sessionStorage.getItem(profileKey), null);
  remembered.API.clearAuthState();
  assert.equal(remembered.localStorage.getItem(profileKey), null);
}

function testProfileNameIsAppliedWithoutWaitingForDomContentLoaded() {
  const nameEl = { textContent: "Loading..." };
  const initialsEl = { textContent: "--" };
  createBrowser({
    pathname: "/pages/client/my-pets.html",
    local: {
      customer_token: "token",
      user_role: "customer",
      "bethlehem.customer.profile_name": JSON.stringify({ first: "Ridge", last: "Marino" }),
    },
    elements: { clientProfileName: nameEl, clientProfileInitials: initialsEl },
  });
  assert.equal(nameEl.textContent, "Ridge Marino");
  assert.equal(initialsEl.textContent, "RM");

  const otherName = { textContent: "Loading..." };
  createBrowser({
    pathname: "/pages/client/my-pets.html",
    local: { "bethlehem.customer.profile_name": JSON.stringify({ first: "Ridge", last: "Marino" }) },
    elements: { clientProfileName: otherName },
  });
  assert.equal(otherName.textContent, "Loading...", "A cached name must not show without a session token.");
}

async function testCustomerPasswordStepDoesNotCreateBrowserSession() {
  let submittedRequest = null;
  const browser = createBrowser({
    fetchImpl: async (url, options) => {
      submittedRequest = { url, body: JSON.parse(options.body) };
      return jsonResponse(202, {
        success: true,
        requires_login_confirmation: true,
        login_poll_token: "pending-poll-token",
        email: "customer@example.com",
      });
    },
  });

  const response = await browser.API.signIn(
    "customer@example.com",
    "password",
    false,
  );

  assert.equal(response.requires_login_confirmation, true);
  assert.equal(submittedRequest.url, "http://127.0.0.1:8000/api/sign-in");
  assert.equal(submittedRequest.body.remember, false);
  assert.equal(browser.API.getCustomerToken(), null);
  assert.equal(browser.API.getUserRole(), null);
  assert.equal(
    browser.sessionStorage.getItem("pending_login_poll_token"),
    "pending-poll-token",
  );
  assert.equal(
    browser.API.getPendingLoginConfirmationEmail(),
    "customer@example.com",
  );
}

async function testTemporaryServerFailureDoesNotRedirect() {
  const browser = createBrowser({
    pathname: "/pages/client/dashboard.html",
    local: { customer_token: "valid-looking-token", user_role: "customer" },
    fetchImpl: async () => jsonResponse(500, { message: "Temporary failure" }),
  });

  await assert.rejects(() => browser.API.getMe("customer"), /Temporary failure/);
  assert.deepEqual(browser.replacements, []);
  assert.equal(browser.API.getCustomerToken(), "valid-looking-token");
}

async function testReal401ClearsAndUsesNestedSafePath() {
  const browser = createBrowser({
    pathname: "/bethlehem/pages/admin/inventory/pos.html",
    local: { admin_token: "expired-admin-token", user_role: "admin" },
    fetchImpl: async () => jsonResponse(401, { message: "Unauthenticated." }),
  });

  await assert.rejects(() => browser.API.getAdminInventoryItems(), /Unauthenticated/);
  assert.equal(browser.API.getUserRole(), null);
  assert.equal(browser.localStorage.getItem("admin_token"), null);
  assert.equal(
    browser.replacements.at(-1),
    "/bethlehem/pages/client/sign-in.html",
  );
}

async function testAdminRequestUsesTheCurrentAdminSession() {
  let request = null;
  const browser = createBrowser({
    local: { admin_token: "inventory-admin-token", user_role: "admin" },
    fetchImpl: async (url, options) => {
      request = { url, options };
      return jsonResponse(200, { data: [] });
    },
  });

  await browser.API.adminRequest("GET", "/inventory/items");

  assert.equal(request.url, "http://127.0.0.1:8000/api/inventory/items");
  assert.equal(request.options.headers.Authorization, "Bearer inventory-admin-token");
}

async function testStale401CannotClearANewerLogin() {
  let resolveRequest;
  const browser = createBrowser({
    pathname: "/pages/client/dashboard.html",
    local: { customer_token: "old-token", user_role: "customer" },
    fetchImpl: () => new Promise((resolve) => {
      resolveRequest = resolve;
    }),
  });

  const oldRequest = browser.API.getMe("customer");
  browser.API.setCustomerToken("new-token", true);
  resolveRequest(jsonResponse(401, { message: "Old token expired" }));

  await assert.rejects(() => oldRequest, /Old token expired/);
  assert.equal(browser.API.getCustomerToken(), "new-token");
  assert.deepEqual(browser.replacements, []);
}

async function testOnlyExplicit403AuthCodesInvalidateSession() {
  const generalForbidden = createBrowser({
    pathname: "/pages/client/dashboard.html",
    local: { customer_token: "customer-token", user_role: "customer" },
    fetchImpl: async () => jsonResponse(403, { message: "Forbidden", code: "forbidden" }),
  });

  await assert.rejects(() => generalForbidden.API.getMe("customer"), /Forbidden/);
  assert.equal(generalForbidden.API.getCustomerToken(), "customer-token");
  assert.deepEqual(generalForbidden.replacements, []);

  const disabled = createBrowser({
    pathname: "/pages/client/dashboard.html",
    local: { customer_token: "disabled-token", user_role: "customer" },
    fetchImpl: async () => jsonResponse(403, {
      message: "Account disabled",
      code: "account_disabled",
    }),
  });

  await assert.rejects(() => disabled.API.getMe("customer"), /Account disabled/);
  assert.equal(disabled.API.getCustomerToken(), null);
  assert.equal(disabled.replacements.at(-1), "/pages/client/sign-in.html");
}

async function testLogoutClearsBeforeNetworkAndSynchronizesCustomerTabs() {
  let stateAtRequest = null;
  let browser;
  browser = createBrowser({
    pathname: "/pages/client/dashboard.html",
    session: { customer_token: "session-token", user_role: "customer" },
    fetchImpl: async () => {
      stateAtRequest = {
        token: browser.sessionStorage.getItem("customer_token"),
        role: browser.sessionStorage.getItem("user_role"),
      };
      return jsonResponse(200, { success: true });
    },
  });

  await browser.API.logout("customer");
  assert.deepEqual(stateAtRequest, { token: null, role: null });

  const otherTab = createBrowser({
    pathname: "/pages/client/settings.html",
    session: { customer_token: "other-tab-token", user_role: "customer" },
  });
  otherTab.dispatch("storage", {
    storageArea: otherTab.localStorage,
    key: "bethlehem.auth.logout",
    newValue: JSON.stringify({ role: "customer", reason: "logout" }),
  });
  assert.equal(otherTab.API.getCustomerToken(), null);
  assert.equal(otherTab.replacements.at(-1), "/pages/client/sign-in.html");

  const adminTab = createBrowser({
    pathname: "/pages/admin/dashboard.html",
    local: { admin_token: "admin-token", user_role: "staff" },
  });
  adminTab.dispatch("storage", {
    storageArea: adminTab.localStorage,
    key: "bethlehem.auth.logout",
    newValue: JSON.stringify({ role: "admin", reason: "logout" }),
  });
  assert.equal(adminTab.API.getAdminToken(), null);
  assert.equal(adminTab.replacements.at(-1), "/pages/client/sign-in.html");
}

function testTokenReplacementRemovalEventCannotClearFreshLogin() {
  const browser = createBrowser({
    pathname: "/pages/client/dashboard.html",
    local: { customer_token: "fresh-token", user_role: "customer" },
  });

  browser.dispatch("storage", {
    storageArea: browser.localStorage,
    key: "customer_token",
    oldValue: "old-token",
    newValue: null,
  });

  assert.equal(browser.API.getCustomerToken(), "fresh-token");
  assert.deepEqual(browser.replacements, []);
}

function testBackForwardCacheGuard() {
  const browser = createBrowser({
    pathname: "/pages/admin/dashboard.html",
    local: { admin_token: "admin-token", user_role: "admin" },
  });

  browser.API.clearAuthState();
  browser.dispatch("pageshow", { persisted: true });
  assert.equal(browser.replacements.at(-1), "/pages/client/sign-in.html");
}

function verificationPageHarness({ search, hash, pendingLogin = false }) {
  const source = fs.readFileSync(
    path.join(projectRoot, "scripts/auth/client/verify-email.js"),
    "utf8",
  );
  const events = [];
  const elements = new Map();
  const elementListeners = new Map();
  const intervals = new Map();
  let nextIntervalId = 0;
  let onReady = null;

  function element(id) {
    if (!elements.has(id)) {
      const classes = new Set();
      elements.set(id, {
        id,
        classList: {
          add(name) { classes.add(name); },
          remove(name) { classes.delete(name); },
          contains(name) { return classes.has(name); },
        },
        addEventListener(name, handler) {
          elementListeners.set(`${id}:${name}`, handler);
        },
        className: "",
        disabled: id === "loginCodeSubmit",
        textContent: "",
        value: "",
        focus() {
          events.push({ type: "focus", id });
        },
      });
    }
    return elements.get(id);
  }

  const pathname = "/pages/client/verify-email.html";
  const location = {
    hash,
    href: `https://clinic.example${pathname}${search}${hash}`,
    pathname,
    search,
    replace(target) {
      events.push({ type: "redirect", target });
    },
  };
  const context = {
    API: {
      verifyEmail(token) {
        events.push({ type: "verify", token });
        return new Promise(() => {});
      },
      async confirmLoginCode(code) {
        events.push({ type: "confirm-login-code", code });
        return { token: "customer-token", user: { role: "customer" } };
      },
      hasPendingLoginConfirmation: () => pendingLogin,
      getPendingLoginConfirmationEmail: () => "customer@example.com",
      resendVerification: async () => ({}),
      async resendLoginCode() {
        events.push({ type: "resend-login-code" });
      },
    },
    URL,
    URLSearchParams,
    console,
    document: {
      addEventListener(name, handler) {
        if (name === "DOMContentLoaded") onReady = handler;
      },
      getElementById: element,
    },
    history: {
      replaceState(_state, _title, target) {
        events.push({ type: "scrub", target });
      },
    },
    sessionStorage: new MemoryStorage(),
    setTimeout(callback) { callback(); },
    setInterval(callback) {
      const id = ++nextIntervalId;
      intervals.set(id, callback);
      return id;
    },
    clearInterval(id) { intervals.delete(id); },
    window: { location },
  };

  vm.createContext(context);
  vm.runInContext(source, context, {
    filename: "scripts/auth/client/verify-email.js",
  });
  assert.equal(typeof onReady, "function");
  onReady();
  return {
    elements,
    events,
    advanceSeconds(seconds) {
      for (let second = 0; second < seconds; second++) {
        for (const callback of [...intervals.values()]) callback();
      }
    },
    async dispatch(id, name) {
      const handler = elementListeners.get(`${id}:${name}`);
      assert.equal(typeof handler, "function", `Missing ${name} handler for ${id}`);
      return handler({ preventDefault() {} });
    },
  };
}

function testVerificationCredentialsAreScrubbedBeforeUse() {
  const fragmentEvents = verificationPageHarness({
    search: "?token=legacy-token&campaign=welcome",
    hash: "#token=fragment-token",
  });
  assert.deepEqual(fragmentEvents.events, [
    { type: "scrub", target: "/pages/client/verify-email.html?campaign=welcome" },
    { type: "verify", token: "fragment-token" },
  ]);

  const legacyEvents = verificationPageHarness({
    search: "?token=legacy-token",
    hash: "",
  });
  assert.deepEqual(legacyEvents.events, [
    { type: "scrub", target: "/pages/client/verify-email.html" },
    { type: "verify", token: "legacy-token" },
  ]);
}

async function testLoginCodeIsEnteredOnTheOriginalTab() {
  const page = verificationPageHarness({
    search: "?mode=login",
    hash: "",
    pendingLogin: true,
  });
  const input = page.elements.get("loginCode");
  const submit = page.elements.get("loginCodeSubmit");

  assert.equal(page.elements.get("loginCodeEmail").textContent, "customer@example.com");
  assert.equal(page.elements.get("stateLoginCode").classList.contains("hidden"), false);
  assert.equal(submit.disabled, true);

  input.value = "01a2345";
  await page.dispatch("loginCode", "input");
  assert.equal(input.value, "012345");
  assert.equal(submit.disabled, false);

  await page.dispatch("loginCodeForm", "submit");
  assert.ok(page.events.some((event) => (
    event.type === "confirm-login-code" && event.code === "012345"
  )));
  assert.ok(page.events.some((event) => (
    event.type === "redirect" && event.target === "./dashboard.html"
  )));
}

async function testLoginCodeResendCooldownAndLimit() {
  const page = verificationPageHarness({
    search: "?mode=login",
    hash: "",
    pendingLogin: true,
  });
  const resend = page.elements.get("loginCodeResendBtn");

  assert.equal(resend.disabled, true);
  page.advanceSeconds(59);
  assert.equal(resend.disabled, true);
  page.advanceSeconds(1);
  assert.equal(resend.disabled, false);

  for (let attempt = 1; attempt <= 3; attempt++) {
    await page.dispatch("loginCodeResendBtn", "click");
    assert.equal(resend.disabled, true);
    assert.equal(
      page.events.filter((event) => event.type === "resend-login-code").length,
      attempt,
    );
    page.advanceSeconds(60);
    assert.equal(resend.disabled, attempt === 3);
  }
  assert.match(page.elements.get("loginCodeMessage").textContent, /Too many resend attempts/);
}

function testStaticAuthContracts() {
  const signIn = fs.readFileSync(
    path.join(projectRoot, "scripts/auth/sign-in.js"),
    "utf8",
  );
  const verify = fs.readFileSync(
    path.join(projectRoot, "scripts/auth/client/verify-email.js"),
    "utf8",
  );
  const signup = fs.readFileSync(
    path.join(projectRoot, "scripts/auth/client/signup.js"),
    "utf8",
  );
  const webRoutes = fs.readFileSync(path.join(projectRoot, "routes/web.php"), "utf8");
  const verifyHtml = fs.readFileSync(
    path.join(projectRoot, "pages/client/verify-email.html"),
    "utf8",
  );

  assert.doesNotMatch(signIn, /Auto-redirect already-authenticated users/);
  assert.doesNotMatch(signIn, /API\.(?:getUserRole|getAdminToken|getCustomerToken)/);
  assert.match(verify, /new URLSearchParams\(window\.location\.hash\.slice\(1\)\)/);
  assert.match(verify, /fragmentParams\.get\("token"\)/);
  assert.match(verify, /searchParams\.delete\("token"\)/);
  assert.match(verify, /cleanUrl\.hash = ""/);
  assert.ok(
    verify.indexOf('history.replaceState(') < verify.indexOf('await API.verifyEmail(verificationToken)'),
    "Authentication credentials must be scrubbed before the API request",
  );
  assert.doesNotMatch(verify, /login_token|approveLogin|pollLoginConfirmation/);
  assert.match(verify, /await API\.confirmLoginCode\(code\)/);
  assert.match(verify, /await API\.verifyEmail\(verificationToken\)/);
  assert.match(verify, /dashboard\.html/);
  assert.doesNotMatch(verifyHtml, /<img\b[^>]*Bethlehem_Logo-256\.png/);
  assert.match(verifyHtml, /id="loginCode"/);
  assert.match(verifyHtml, /inputmode="numeric"/);
  assert.match(verifyHtml, /autocomplete="one-time-code"/);
  assert.match(verifyHtml, /maxlength="6"/);
  assert.match(signIn, /window\.location\.replace\("\.\/verify-email\.html\?mode=login"\)/);
  assert.match(signup, /email_delivery_queued === false/);
  assert.match(signup, /pendingVerificationDeliveryFailed/);
  assert.match(webRoutes, /no-store, private, max-age=0, must-revalidate/);
  assert.match(webRoutes, /public, max-age=3600/);

  const htmlFiles = [
    path.join(projectRoot, "index.html"),
    ...fs.readdirSync(path.join(projectRoot, "pages"), { recursive: true })
      .filter((file) => file.endsWith(".html"))
      .map((file) => path.join(projectRoot, "pages", file)),
  ];
  const authAssets = [
    "scripts/api.js",
    "scripts/auth/sign-in.js",
    "scripts/auth/forgot-password.js",
    "scripts/auth/reset-password.js",
    "scripts/auth/set-up-password.js",
    "scripts/auth/client/signup.js",
    "scripts/auth/client/verify-email.js",
    "scripts/components/admin-sidebar.js",
    "scripts/components/admin-dashboard.js",
    "scripts/components/admin-clinic.js",
    "scripts/components/client-dashboard.js",
    "scripts/components/client-settings.js",
    "scripts/components/my-pets.js",
    "scripts/components/pet-details.js",
  ];

  for (const htmlFile of htmlFiles) {
    const html = fs.readFileSync(htmlFile, "utf8");
    for (const tag of html.matchAll(/<script[^>]+src="([^"]+)"[^>]*>/g)) {
      const source = tag[1].replace(/\\/g, "/");
      const matchedAsset = authAssets.find((asset) => source.includes(asset));
      if (matchedAsset) {
        assert.match(
          source,
          /\?v=(?:auth-session-20260816|pending-registration-20260818|customer-account-delete-20260819|login-email-auth-20260819|login-approval-polling-20260819|login-code-20260819|admin-notifications-20260821|sedation-consent-20260822|admin-login-code-20260828|security-code-20260828|password-reset-20260828|session-inactivity-20260828|staff-identity-20260830|chatbot-context-20260830|chatbot-safety-insights-20260830|clinic-records-20260906|grooming-size-confirmation-20260915|staff-password-setup-20260923|staff-setup-link-renewal-20260923)(?:$|&)/,
          `Stale ${matchedAsset} cache key in ${path.relative(projectRoot, htmlFile)}`,
        );
      }
    }
  }
}

(async () => {
  await testLoginCodeHonorsRememberMeStorage();
  await testProfileNameLivesAndDiesWithTheSession();
  testProfileNameIsAppliedWithoutWaitingForDomContentLoaded();
  await testCustomerPasswordStepDoesNotCreateBrowserSession();
  await testTemporaryServerFailureDoesNotRedirect();
  await testReal401ClearsAndUsesNestedSafePath();
  await testAdminRequestUsesTheCurrentAdminSession();
  await testStale401CannotClearANewerLogin();
  await testOnlyExplicit403AuthCodesInvalidateSession();
  await testLogoutClearsBeforeNetworkAndSynchronizesCustomerTabs();
  testTokenReplacementRemovalEventCannotClearFreshLogin();
  testBackForwardCacheGuard();
  testVerificationCredentialsAreScrubbedBeforeUse();
  await testLoginCodeIsEnteredOnTheOriginalTab();
  await testLoginCodeResendCooldownAndLimit();
  testStaticAuthContracts();
  console.log("frontend auth/session regression checks passed");
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
