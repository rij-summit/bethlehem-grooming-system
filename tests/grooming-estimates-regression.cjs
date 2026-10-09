const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { spawnSync } = require('node:child_process');
const path = require('node:path');

async function main() {
  const server = spawnSync('php', [path.join(__dirname, 'grooming-estimate-cases.php')], { encoding: 'utf8' });
  assert.equal(server.status, 0, server.stderr || server.stdout);
  const { rules, cases } = JSON.parse(server.stdout);
  await import('../scripts/services/grooming-estimate-engine.js');
  const engine = globalThis.GroomingEstimates;
  engine.configure(rules);
  const grooming = await import('../scripts/services/grooming-service.js?v=grooming-pricing-20261006');
  const component = await import('../scripts/components/grooming-estimate-selection.js');
  assert.deepEqual(engine.preferences('regular_dog_grooming').map((p) => p.label), ['Summer Cut', 'Semi-Kalbo', 'Kalbo', 'Regular Trim']);
  assert.deepEqual(engine.preferences('deluxe_dog_grooming').map((p) => p.label), ['Puppy Cut', 'Custom Hairstyle']);
  for (const packageId of ['partial_grooming', 'bath_and_go', 'cat_full_grooming']) {
    assert.deepEqual(engine.preferences(packageId), []);
    const html = component.renderEstimateSelection({ id: 1, size: 'small' }, { servicePackage: packageId });
    assert.doesNotMatch(html, /data-role="grooming-preference"|<legend>Grooming preference/);
  }
  const regular = component.renderEstimateSelection({ id: 1, size: 'small' }, { servicePackage: 'regular_dog_grooming' });
  assert.equal((regular.match(/type="radio"/g) || []).length, 4);
  assert.doesNotMatch(regular, /Puppy Cut|Custom Hairstyle/);
  const deluxe = component.renderEstimateSelection({ id: 1, size: 'small' }, { servicePackage: 'deluxe_dog_grooming' });
  assert.equal((deluxe.match(/type="radio"/g) || []).length, 2);
  assert.doesNotMatch(deluxe, /Summer Cut|Semi-Kalbo|Kalbo|Regular Trim/);
  assert.equal(grooming.hasRequiredGroomingPreference({ servicePackage: 'regular_dog_grooming' }), false);
  assert.equal(grooming.hasRequiredGroomingPreference({ servicePackage: 'regular_dog_grooming', groomingPreference: 'regular_trim' }), true);
  for (const { selection, size, estimate } of cases) {
    assert.deepEqual(engine.calculate(selection, size), estimate, `${selection.servicePackage}/${selection.groomingPreference}/${size}`);
  }
  const fixture = JSON.parse(fs.readFileSync(path.join(__dirname, 'grooming-catalogue-fixture.json'), 'utf8').replace(/^\uFEFF/, ''));
  grooming.applyGroomingCatalogue(fixture.data);
  const pets = [{ id: 'coco', size: 'medium' }, { id: 'bruno', size: 'large' }];
  const selections = pets.map((pet) => ({ petId: pet.id, servicePackage: 'regular_dog_grooming', groomingPreference: 'regular_trim', alaCarteServices: [] }));
  const review = grooming.buildBookingReviewPayload({ pets }, selections);
  assert.equal(review.items[0].groomingEstimate.formatted, '1 hr–1 hr 30 min');
  assert.equal(review.items[1].groomingEstimate.formatted, '2 hrs');
  assert.equal(grooming.normalizeStepThreeDraft(grooming.buildStepThreeDraftPayload(selections), { pets })[0].groomingPreference, 'regular_trim');
  assert.equal(engine.formatDuration(75), '1 hr 15 min');
  assert.equal(engine.formatDuration(72), '1 hr 12 min');
  assert.equal(engine.formatRange(120, 240), '2–4 hrs');

  const now = Date.parse('2026-10-09T13:15:00+08:00');
  const estimate = { minMinutes: 60, maxMinutes: 90 };
  const ready = engine.readyWindow(estimate, new Date(now).toISOString(), now);
  assert.equal(ready.label, '2:15–2:45 PM');
  assert.equal(engine.readyWindow(estimate, new Date(now).toISOString(), now + 91 * 60000).label, 'Taking longer than estimated');
  const elapsed = engine.readyWindow(estimate, new Date(now).toISOString(), now + 75 * 60000);
  assert.equal(elapsed.overdue, false);
  assert.doesNotMatch(elapsed.label, /2:15/);
  const waiting = [{ key: 'a', estimate }, { key: 'b', estimate }];
  const one = engine.queueETAs(waiting, [], 1, now), two = engine.queueETAs(waiting, [], 2, now);
  assert.equal(one.b.estDoneMax, now + 180 * 60000);
  assert.equal(two.b.estDoneMax, now + 90 * 60000);
  assert.equal(engine.queueETAs(waiting, [], 0, now).a.unavailable, true);
  const overdue = [{ key: 'active', estimate, startedAt: new Date(now - 91 * 60000).toISOString() }];
  const blocked = engine.queueETAs(waiting, overdue, 1, now);
  assert.equal(blocked.active.overdue, true);
  assert.equal(blocked.a.unavailable, true);
  assert.equal(engine.queueETAs(waiting, overdue, 2, now).a.unavailable, undefined);

  const context = { window: { GroomingEstimates: engine }, Date, Intl, console };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../scripts/components/admin-dashboard.js'), 'utf8'), context);
  const ui = context.adminDashboard();
  ui.activeGroomers = 1;
  const first = { id: 1, bookingPetId: 1, groomingEstimate: estimate };
  const second = { id: 2, bookingPetId: 2, groomingEstimate: { minMinutes: 120, maxMinutes: 120 } };
  ui.queuedList = [{ id: 1, queueNumber: 1, pets: [first, second], services: [{ bookingPetId: 1, durationMinutes: 9999 }] }];
  ui.inProgressList = [];
  assert.equal(ui.petETAs['1'].estDoneMax - ui.petETAs['1'].estDoneMin, 30 * 60000);
  assert.match(ui.queueSummary.avgWaitLabel, /1 hr 30 min–1 hr 45 min/);
  assert.equal(ui.getBookingETA(ui.queuedList[0]), engine.formatTimeWindow(ui.petETAs['2'].estDoneMin, ui.petETAs['2'].estDoneMax));
  ui.activeGroomingPets = 1;
  assert.equal(ui.isGroomerCapacityFull, true);
  ui.inProgressList = [{ id: 3, pets: [{ ...first, groomingStartedAtIso: new Date(Date.now() - 91 * 60000).toISOString(), isGroomingStarted: true }] }];
  ui.queuedList = [];
  assert.equal(ui.getPetEstDone(ui.inProgressList[0], first), 'Taking longer than estimated');
  for (const file of ['booking-services-step', 'walk-in-services-step']) {
    const source = fs.readFileSync(path.join(__dirname, `../scripts/components/${file}.js`), 'utf8');
    assert.match(source, /renderEstimateSelection\(pet, selection,/);
    assert.match(source, /hasRequiredGroomingPreference\(selection\)/);
  }
  console.log(`Grooming estimate regression tests passed (${cases.length} server/preview comparisons).`);
}
main().catch((error) => { console.error(error); process.exitCode = 1; });
