const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function harness(statuses) {
  const timers = new Map();
  const events = [];
  let checks = 0;
  let resolveRequest;
  const window = {
    setTimeout(callback) { timers.set(1, callback); return 1; },
    clearTimeout(id) { timers.delete(id); },
  };
  const API = {
    async emailDeliveryStatus(receipt) {
      assert.equal(receipt, 'private-receipt');
      checks++;
      const next = statuses.shift() ?? 'queued';
      if (next === 'offline') throw Error('Network unavailable');
      if (next === 'pending') return new Promise(resolve => { resolveRequest = resolve; });
      return { status: next };
    },
  };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../scripts/auth/email-delivery.js'), 'utf8'), { window, API });
  const stop = window.AuthenticationEmailDelivery.watch('private-receipt', status => events.push(status));
  return {
    events, stop,
    get checks() { return checks; },
    get scheduled() { return timers.size; },
    async tick() {
      const callback = timers.get(1);
      timers.clear();
      await callback?.();
    },
    complete(status) { resolveRequest({ status }); },
  };
}

(async () => {
  const queued = harness(['queued', 'sent']);
  await new Promise(resolve => setImmediate(resolve));
  assert.deepEqual(queued.events, ['queued'], 'Enqueueing must not claim delivery');
  await queued.tick();
  assert.deepEqual(queued.events, ['queued', 'sent']);
  assert.equal(queued.scheduled, 0, 'Stop polling after transport acceptance');

  const failed = harness(['failed']);
  await new Promise(resolve => setImmediate(resolve));
  assert.deepEqual(failed.events, ['queued', 'failed']);
  assert.equal(failed.scheduled, 0);

  const delayed = harness(['offline']);
  await new Promise(resolve => setImmediate(resolve));
  for (let i = 1; i < 24; i++) await delayed.tick();
  assert.equal(delayed.checks, 24, 'Bound network traffic while a worker is unavailable');
  assert.deepEqual(delayed.events, ['queued', 'delayed']);
  assert.equal(delayed.scheduled, 0);

  const obsolete = harness(['pending']);
  obsolete.stop();
  obsolete.complete('failed');
  await new Promise(resolve => setImmediate(resolve));
  assert.deepEqual(obsolete.events, ['queued'], 'An old send must not overwrite the state of a resend or completed session');
  assert.equal(obsolete.scheduled, 0);
  console.log('Email delivery regression checks passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
