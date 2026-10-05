const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '..');

function page({ deliveryFailed = false, noStorage = false } = {}) {
  let now = 100000;
  const intervals = new Map();
  const timeouts = new Map();
  const calls = [];
  const storage = new Map([
    ['pendingVerificationEmail', 'customer@example.test'],
    ['pendingVerificationPhone', '09171234542'],
    ['pendingRegistrationEditToken', 'private-edit-credential'],
    ['pendingVerificationResendUntil', String(now + (deliveryFailed ? 0 : 45000))],
    ['pendingVerificationDeliveryFailed', deliveryFailed ? '1' : '0'],
  ]);
  let nextId = 0;
  let ready;
  const elements = new Map();
  const document = {
    activeElement: null,
    addEventListener(name, callback) { if (name === 'DOMContentLoaded') ready = callback; },
    getElementById: element,
  };
  function element(id) {
    if (!elements.has(id)) {
      const classes = new Set(['hidden']);
      const handlers = {};
      elements.set(id, {
        id, value: '', textContent: '', disabled: false, handlers, attributes: {},
        classList: {
          add(name) { classes.add(name); }, remove(name) { classes.delete(name); },
          contains(name) { return classes.has(name); },
          toggle(name, enabled) { if (enabled) classes.add(name); else classes.delete(name); },
        },
        setAttribute(name, value) { this.attributes[name] = value; },
        addEventListener(name, handler) { handlers[name] = handler; },
        setSelectionRange(start, end) { this.selection = [start, end]; },
        focus() { document.activeElement = this; handlers.focus?.(); },
        checkValidity() { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(this.value); },
        reportValidity() {},
        querySelectorAll() { return cells; },
      });
    }
    return elements.get(id);
  }
  const cells = Array.from({ length: 6 }, (_, i) => element(`cell${i}`));
  const window = {
    location: { search: '', hash: '', replace(target) { calls.push(['redirect', target]); } },
    setInterval(callback) { const id = ++nextId; intervals.set(id, callback); return id; },
    clearInterval(id) { intervals.delete(id); },
    setTimeout(callback, ms) { const id = ++nextId; timeouts.set(id, { callback, at: now + ms }); return id; },
  };
  const API = {
    async resendVerification(email) { calls.push(['send', email]); return { resend_after: 45 }; },
    async verifyEmail(email, code) { calls.push(['verify', email, code]); return { token: 'session', user: { role: 'customer' } }; },
  };
  const context = vm.createContext({
    window, document, API, URLSearchParams,
    Date: { now: () => now },
    sessionStorage: {
      getItem(key) { if (noStorage) throw Error('blocked'); return storage.get(key) ?? null; },
      setItem(key, value) { if (noStorage) throw Error('blocked'); storage.set(key, value); },
      removeItem(key) { if (noStorage) throw Error('blocked'); storage.delete(key); },
    },
  });
  for (const source of ['scripts/auth/verification-code.js', 'scripts/auth/client/signup-verification.js']) {
    vm.runInContext(fs.readFileSync(path.join(root, source), 'utf8'), context, { filename: source });
  }
  ready();
  return {
    element, cells, API, calls, storage,
    dispatch(id, event, extra = {}) { return element(id).handlers[event]({ preventDefault() {}, ...extra }); },
    advance(ms) {
      now += ms;
      for (const [id, timeout] of [...timeouts]) if (timeout.at <= now) { timeouts.delete(id); timeout.callback(); }
      for (const callback of [...intervals.values()]) callback();
    },
    type(value) { element('signupCode').value = value; this.dispatch('signupCode', 'input'); },
  };
}

(async () => {
  const p = page();
  assert.equal(p.element('verificationTitle').textContent, 'Verify your email');
  assert.match(p.element('verificationDescription').textContent, /customer@example.test/);
  assert.equal(p.element('signupResend').textContent, 'Resend code in 45s');
  assert.equal(p.element('signupCodeSubmit').disabled, true);
  p.type('12a34b567');
  assert.equal(p.element('signupCode').value, '123456');
  assert.deepEqual(p.cells.map(c => c.textContent), ['1', '2', '3', '4', '5', '6']);
  assert.equal(p.cells[5].classList.contains('is-active'), true);
  assert.equal(p.element('signupCodeSubmit').disabled, false);
  let prevented = false;
  p.dispatch('signupCode', 'paste', { preventDefault() { prevented = true; }, clipboardData: { getData: () => '98 76 54' } });
  assert.equal(prevented, true);
  assert.equal(p.element('signupCode').value, '987654');
  assert.deepEqual(p.cells.map(c => c.textContent), ['9', '8', '7', '6', '5', '4']);

  p.advance(15000);
  p.dispatch('switchVerificationChannel', 'click');
  assert.equal(p.element('verificationTitle').textContent, 'Verify your mobile number');
  assert.match(p.element('verificationDescription').textContent, /0917 ••• ••42/);
  assert.doesNotMatch(p.element('verificationDescription').textContent, /09171234542/);
  assert.equal(p.element('switchVerificationChannel').textContent, 'Use email instead');
  assert.equal(p.element('signupCode').value, '');
  assert.equal(p.element('signupResend').textContent, 'Resend Code');
  const smsSend = p.dispatch('signupResend', 'click');
  assert.equal(p.element('signupResend').textContent, 'Sending...');
  assert.equal(p.element('signupCode').disabled, true);
  p.advance(400);
  await smsSend;
  assert.equal(p.element('signupResend').textContent, 'Resend code in 45s');
  assert.match(p.element('signupCodeMessage').textContent, /couldn't send a text message/);
  p.dispatch('signupCode', 'paste', { clipboardData: { getData: () => '123456' } });
  assert.equal(p.element('signupCode').value, '123456');
  const smsVerify = p.dispatch('signupCodeForm', 'submit');
  assert.equal(p.element('signupCodeSubmit').textContent, 'Verifying...');
  p.advance(400);
  await smsVerify;
  assert.match(p.element('signupCodeMessage').textContent, /couldn't verify this mobile number/);
  assert.deepEqual(p.calls, [], 'SMS must never call an API or authenticate');
  assert.equal(p.element('statePending').classList.contains('hidden'), false);
  assert.equal(p.element('stateSuccess').classList.contains('hidden'), true);
  assert.equal(p.storage.has('pendingVerificationEmail'), true);
  assert.equal([...p.storage.values()].includes('123456'), false, 'OTP must never enter storage');
  p.dispatch('switchVerificationChannel', 'click');
  assert.equal(p.element('verificationTitle').textContent, 'Verify your email');
  assert.equal(p.element('signupCode').value, '');
  assert.match(p.element('signupResend').textContent, /^Resend code in 30s/);
  p.advance(30000);
  assert.equal(p.element('signupResend').textContent, 'Resend Code');
  await p.dispatch('signupResend', 'click');
  assert.deepEqual(p.calls, [['send', 'customer@example.test']]);
  assert.equal(p.element('signupResend').textContent, 'Resend code in 45s');
  p.type('654321');
  await p.dispatch('signupCodeForm', 'submit');
  assert.deepEqual(p.calls.at(-1), ['verify', 'customer@example.test', '654321']);
  assert.equal(p.storage.size, 0);
  assert.equal(p.element('stateSuccess').classList.contains('hidden'), false);
  p.advance(1500);
  assert.deepEqual(p.calls.at(-1), ['redirect', './dashboard.html']);

  const throttled = page({ deliveryFailed: true });
  assert.match(throttled.element('signupCodeMessage').textContent, /registration was saved/);
  assert.equal(throttled.element('signupResend').disabled, false);
  throttled.API.resendVerification = async () => { throw { status: 429, retryAfter: 90 }; };
  await throttled.dispatch('signupResend', 'click');
  assert.equal(throttled.element('signupResend').textContent, 'Resend code in 90s');
  assert.equal(throttled.element('signupCodeMessage').textContent, 'Please wait before requesting another code.');
  throttled.advance(90000);
  throttled.API.resendVerification = async () => { throw { status: 503 }; };
  await throttled.dispatch('signupResend', 'click');
  assert.equal(throttled.element('signupResend').disabled, false);
  assert.match(throttled.element('signupCodeMessage').textContent, /could not be sent/);
  throttled.API.verifyEmail = async () => { throw { status: 429 }; };
  throttled.type('123456');
  await throttled.dispatch('signupCodeForm', 'submit');
  assert.equal(throttled.element('signupCodeMessage').textContent, 'Too many attempts. Please request a new verification code.');
  assert.equal(throttled.element('signupCode').attributes['aria-invalid'], 'true');

  const recovery = page({ noStorage: true });
  assert.equal(recovery.element('verificationEmailRecovery').classList.contains('hidden'), false);
  recovery.element('verificationEmail').value = 'restore@example.test';
  await recovery.dispatch('signupResend', 'click');
  assert.deepEqual(recovery.calls, [['send', 'restore@example.test']]);

  for (const htmlFile of ['pages/client/verify-email.html', 'pages/client/forgot-password.html']) {
    const html = fs.readFileSync(path.join(root, htmlFile), 'utf8');
    assert.equal((html.match(/class="verification-code-cell"/g) || []).length, 6);
    assert.match(html, /verification-code\.js/);
    assert.match(html, /verification-code\.css/);
    assert.match(html, /inputmode="numeric"/);
    assert.match(html, /autocomplete="one-time-code"/);
  }
  console.log('Customer signup verification regression checks passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
