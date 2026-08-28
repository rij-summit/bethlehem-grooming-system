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

function createIdleBrowser(role) {
  let now = 1_000_000;
  let timerSequence = 0;
  const timers = new Map();
  const windowListeners = new Map();
  const documentListeners = new Map();
  const elements = new Map();
  const replacements = [];
  const requests = [];
  const tokenKey = role === "customer" ? "customer_token" : "admin_token";
  const token = `${role}-token`;
  const localStorage = new MemoryStorage({
    [tokenKey]: token,
    user_role: role,
  });
  const sessionStorage = new MemoryStorage();

  function addListener(collection, name, handler) {
    const handlers = collection.get(name) || [];
    handlers.push(handler);
    collection.set(name, handlers);
  }

  function setFakeTimeout(callback, delay = 0) {
    const id = ++timerSequence;
    timers.set(id, { at: now + Math.max(0, Number(delay) || 0), callback });
    return id;
  }

  function clearFakeTimeout(id) {
    timers.delete(id);
  }

  async function advance(milliseconds) {
    const target = now + milliseconds;

    while (true) {
      const due = [...timers.entries()]
        .filter(([, timer]) => timer.at <= target)
        .sort((left, right) => left[1].at - right[1].at)[0];
      if (!due) break;

      const [id, timer] = due;
      timers.delete(id);
      now = timer.at;
      timer.callback();
      await Promise.resolve();
    }

    now = target;
    await Promise.resolve();
  }

  const body = {
    appendChild(element) {
      elements.set(element.id, element);
      return element;
    },
  };
  const document = {
    body,
    visibilityState: "visible",
    querySelector: () => null,
    getElementById(id) {
      return elements.get(id) || null;
    },
    createElement() {
      return {
        id: "",
        style: {},
        textContent: "",
        attributes: {},
        setAttribute(name, value) {
          this.attributes[name] = String(value);
        },
      };
    },
    addEventListener(name, handler) {
      addListener(documentListeners, name, handler);
    },
  };
  const location = {
    protocol: "https:",
    hostname: "clinic.example",
    port: "",
    origin: "https://clinic.example",
    pathname: role === "customer"
      ? "/pages/client/dashboard.html"
      : "/pages/admin/dashboard.html",
    href: "",
    replace(target) {
      replacements.push(target);
      this.href = target;
    },
  };
  const window = {
    location,
    addEventListener(name, handler) {
      addListener(windowListeners, name, handler);
    },
  };
  class FakeDate extends Date {
    static now() {
      return now;
    }
  }

  const context = {
    AbortController,
    Date: FakeDate,
    FormData: class FormData {},
    URLSearchParams,
    clearTimeout: clearFakeTimeout,
    console,
    document,
    fetch: async (url, options) => {
      requests.push({ url, options });
      return jsonResponse(200, { success: true });
    },
    localStorage,
    sessionStorage,
    setTimeout: setFakeTimeout,
    window,
  };

  vm.createContext(context);
  vm.runInContext(apiSource, context, { filename: "scripts/api.js" });

  return {
    API: context.API,
    localStorage,
    replacements,
    requests,
    warning() {
      return elements.get("bethlehem-session-inactivity-warning") || null;
    },
    async advance(minutes) {
      await advance(minutes * 60 * 1000);
    },
    dispatchDocument(name) {
      for (const handler of documentListeners.get(name) || []) handler({ type: name });
    },
  };
}

async function testPrivilegedWarningActivityResetAndLogout(role) {
  const browser = createIdleBrowser(role);
  const policy = browser.API.getSessionInactivityPolicy(role);
  assert.equal(policy.warningMs, 14 * 60 * 1000);
  assert.equal(policy.timeoutMs, 15 * 60 * 1000);

  await browser.advance(13);
  assert.equal(browser.warning(), null);
  assert.deepEqual(browser.replacements, []);

  await browser.advance(1);
  assert.equal(browser.warning().textContent, "Your session will expire soon due to inactivity.");
  assert.equal(browser.warning().style.display, "block");
  assert.match(browser.warning().style.cssText, /#fff7ed/);

  browser.dispatchDocument("pointerdown");
  assert.equal(browser.warning().style.display, "none");

  await browser.advance(14);
  assert.equal(browser.warning().style.display, "block");
  assert.deepEqual(browser.replacements, []);

  await browser.advance(1);
  assert.equal(browser.localStorage.getItem("admin_token"), null);
  assert.equal(browser.localStorage.getItem("user_role"), null);
  assert.equal(browser.replacements.at(-1), "/pages/client/sign-in.html");
  assert.equal(browser.requests.at(-1).url, "https://clinic.example/api/logout");
  assert.equal(browser.requests.at(-1).options.keepalive, true);
  assert.equal(
    browser.requests.at(-1).options.headers.Authorization,
    `Bearer ${role}-token`,
  );
  assert.equal(
    JSON.parse(browser.localStorage.getItem("bethlehem.auth.logout")).reason,
    "inactivity",
  );
}

async function testCustomerThirtyMinuteTimeout() {
  const browser = createIdleBrowser("customer");
  const policy = browser.API.getSessionInactivityPolicy("customer");
  assert.equal(policy.warningMs, 29 * 60 * 1000);
  assert.equal(policy.timeoutMs, 30 * 60 * 1000);

  await browser.advance(29);
  assert.equal(browser.warning().style.display, "block");
  assert.deepEqual(browser.replacements, []);

  await browser.advance(1);
  assert.equal(browser.localStorage.getItem("customer_token"), null);
  assert.equal(browser.replacements.at(-1), "/pages/client/sign-in.html");
}

(async () => {
  await testPrivilegedWarningActivityResetAndLogout("admin");
  await testPrivilegedWarningActivityResetAndLogout("staff");
  await testCustomerThirtyMinuteTimeout();
  console.log("Session inactivity regression tests passed.");
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
