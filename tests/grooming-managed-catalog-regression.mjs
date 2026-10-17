import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import * as grooming from '../scripts/services/grooming-service.js';
import { escapeHtml, formatPetSizeLabel } from '../scripts/services/booking-draft-service.js';

const catalogue = [
  {serviceId: 31, id: 'coat_care', name: 'Coat Care Grooming', kind: 'package', petType: 'dog',
    descriptionItems: ['Gentle bath and coat conditioning', 'Nail Clipping is available separately'],
    includedServiceIds: [32], includedAlaCarteServiceIds: ['ear_care'], allowsAlaCarteServices: true,
    priceOptions: ['small', 'medium', 'large', 'extra_large'].map((sizeKey, i) => ({sizeKey,
      label: ['Small', 'Medium', 'Large', 'Extra Large'][i], minAmount: [600, 700, 900, 1100][i],
      maxAmount: i > 1 ? null : [600, 700][i], pricingType: i > 1 ? 'starting_at' : 'fixed'}))},
  {serviceId: 32, id: 'ear_care', name: 'Ear Care', kind: 'ala_carte', petTypes: ['cat', 'dog'],
    descriptionItems: [], priceOptions: [{label: 'Standard', sizeKey: '', minAmount: 150, maxAmount: 150, pricingType: 'fixed'}]},
  {serviceId: 33, id: 'nail_care', name: 'Nail Clipping', kind: 'ala_carte', petTypes: ['cat', 'dog'],
    descriptionItems: [], priceOptions: [{label: 'Standard', sizeKey: '', minAmount: 100, maxAmount: 100, pricingType: 'fixed'}]},
];
grooming.applyGroomingCatalogue(catalogue);
assert.deepEqual(grooming.getPackagesByPetType('dog').map(s => s.name), ['Coat Care Grooming']);
assert.equal(grooming.getPackageById('coat_care').descriptionItems[0], 'Gentle bath and coat conditioning');
assert.deepEqual(grooming.getPackageAlaCarteRules('coat_care').includedAlaCarteServiceIds, ['ear_care']);
assert.equal(grooming.getPackagesByPetType('cat').length, 0);
const pet = {id: 'milo', petType: 'dog', size: 'large'};
const selection = {petId: 'milo', servicePackage: 'coat_care', alaCarteServices: [], groomingPreference: ''};
for (const file of ['booking-services-step', 'walk-in-services-step']) {
  const source = fs.readFileSync(new URL(`../scripts/components/${file}.js`, import.meta.url), 'utf8');
  const context = {...grooming, escapeHtml, formatPetSizeLabel};
  vm.runInNewContext(source.slice(source.indexOf('function renderPackageCard('), source.indexOf('function getSelectionByPetId(')), context);
  const card = context.renderPackageCard(pet, selection, grooming.getPackageById('coat_care'));
  assert.match(card, /Coat Care Grooming/);
  assert.match(card, /Gentle bath and coat conditioning/);
  assert.match(card, /900\+/);
  const included = context.renderAlaCarteCard(pet, selection, grooming.getAlaCarteServiceById('ear_care'));
  assert.match(included, /\sdisabled\s/);
  assert.match(included, /Included in Package/);
  const separate = context.renderAlaCarteCard(pet, selection, grooming.getAlaCarteServiceById('nail_care'));
  assert.doesNotMatch(separate, /\sdisabled\s/);
}
const changed = structuredClone(catalogue);
changed[0].name = 'Coat and Skin Care';
changed[0].descriptionItems = ['Gentle skin care'];
changed[0].priceOptions[0].minAmount = changed[0].priceOptions[0].maxAmount = 650;
grooming.applyGroomingCatalogue(changed.filter(s => s.id !== 'nail_care'));
assert.equal(grooming.getPackageById('coat_care').name, 'Coat and Skin Care');
assert.equal(grooming.getPackageById('coat_care').priceOptions[0].minAmount, 650);
assert.equal(grooming.getAlaCarteServiceById('nail_care'), null);
grooming.applyGroomingCatalogue([]);
assert.equal(grooming.getPackageById('coat_care'), null);
assert.equal(grooming.getAlaCarteServicesByPetType('dog').length, 0);
console.log('Managed catalog and both grooming card renderers passed.');
