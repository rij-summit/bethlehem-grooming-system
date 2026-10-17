import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { test } from "node:test";
import vm from "node:vm";

// Only the catalogue request is replaced; exercise the actual shared controls.
const catalogue = JSON.parse(readFileSync(new URL("../scripts/data/breed-coat-options.json", import.meta.url)));
let releaseCatalogue;
const pendingCatalogue = new Promise(resolve => { releaseCatalogue = resolve; });
globalThis.fetch = async () => ({ ok: true, json: () => pendingCatalogue });
const { createBreedCombobox } = await import("../scripts/components/breed-combobox.js");
const { createBreedCoatCombobox } = await import("../scripts/components/breed-coat-combobox.js");
const { breedCoatCatalogueReady, getCoatOptions, OTHER_BREED, UNKNOWN_BREED, getMixedBreed } =
  await import("../scripts/components/breed-coat-catalogue.js");

// Minimal DOM boundary, following the existing vanilla component regression tests.
function element(value = "") {
  const listeners = new Map();
  const classes = new Set();
  const attributes = new Map();
  return {
    value, disabled: false, readOnly: true, dataset: {}, children: [],
    classList: {
      add(...names) { names.forEach(name => classes.add(name)); },
      remove(...names) { names.forEach(name => classes.delete(name)); },
      contains(name) { return classes.has(name); },
      toggle(name, force = !classes.has(name)) {
        if (force) classes.add(name); else classes.delete(name);
      },
    },
    set innerHTML(value) { this.children = []; },
    setAttribute(name, value) { attributes.set(name, value); },
    getAttribute(name) { return attributes.get(name); },
    appendChild(child) { this.children.push(child); },
    addEventListener(name, listener) {
      if (!listeners.has(name)) listeners.set(name, []);
      listeners.get(name).push(listener);
    },
    dispatchEvent(event) {
      for (const listener of listeners.get(event.type) || []) listener(event);
      return true;
    },
    focus() { document.activeElement = this; },
    contains(target) { return target === this || this.children.some(child => child.contains(target)); },
    closest(selector) {
      return selector === "[data-breed-value]" && this.dataset.breedValue !== undefined ? this
        : selector === "[data-coat-value]" && this.dataset.coatValue !== undefined ? this : null;
    },
    querySelectorAll() { return this.children.filter(child => child.dataset.breedValue || child.dataset.coatValue); },
  };
}

globalThis.document = { createElement: () => element(), addEventListener() {}, activeElement: null };
globalThis.window = { setTimeout };

function form({ species = "", breed = "", coat = "", integration } = {}) {
  const petTypeInput = element(species);
  const breedInput = element(breed);
  const coatInput = element(coat);
  const breedRoot = element();
  const breedList = element();
  const breedToggle = element();
  const coatRoot = element();
  const coatList = element();
  const coatToggle = element();
  const nodes = new Map();
  const getElementById = id => {
    if (!nodes.has(id)) nodes.set(id, element());
    return nodes.get(id);
  };
  for (const prefix of ["breed", "petBreed", "adminPetBreed"]) {
    nodes.set(`${prefix}Combobox`, breedRoot);
    nodes.set(`${prefix}Options`, breedList);
    nodes.set(`${prefix}DropdownButton`, breedToggle);
  }
  for (const prefix of ["furType", "petFurType", "adminPetFurType"]) {
    nodes.set(`${prefix}Combobox`, coatRoot);
    nodes.set(`${prefix}Options`, coatList);
    nodes.set(`${prefix}DropdownButton`, coatToggle);
  }
  const elements = { petType: petTypeInput, species: petTypeInput, breed: breedInput, furType: coatInput,
    petName: element(), birthdate: element(), color: element(), weight: element(), medicalNotes: element() };
  const context = {
    document: { getElementById }, elements, petBreed: breedInput, petSpecies: petTypeInput, petFurType: coatInput,
    createBreedCombobox, createBreedCoatCombobox, tools: { createBreedCombobox, createBreedCoatCombobox },
  };
  const source = integration && readFileSync(new URL(`../scripts/components/${integration}.js`, import.meta.url), "utf8");
  const fromIntegration = name => vm.runInNewContext(
    source.match(new RegExp(`${name}\\(\\{[\\s\\S]*?\\n\\s*\\}\\)`))[0], context,
  );
  const breedControl = integration ? fromIntegration("createBreedCombobox") : createBreedCombobox({
    root: breedRoot, input: breedInput, listbox: breedList, toggleButton: breedToggle,
    petTypeInput, getPetType: () => petTypeInput.value,
  });
  const coatControl = integration ? fromIntegration("createBreedCoatCombobox") : createBreedCoatCombobox({
    root: coatRoot, breedInput, petTypeInput, input: coatInput, listbox: coatList, toggleButton: coatToggle,
  });
  const change = (input, value, type = "change") => {
    input.value = value;
    input.dispatchEvent(new Event(type));
  };
  function chooseBreed(value) {
    const option = element();
    option.dataset.breedValue = value;
    breedRoot.dispatchEvent({ type: "click", target: option });
  }
  function chooseCoat(value) {
    coatToggle.dispatchEvent(new Event("click"));
    const option = coatList.children.find(child => child.dataset.coatValue === value);
    assert.ok(option, `Fur option ${value} should be offered`);
    coatRoot.dispatchEvent({ type: "click", target: option });
  }
  return { petTypeInput, breedInput, coatInput, breedToggle, coatToggle, breedList, breedControl, coatControl,
    chooseBreed, chooseCoat, change, elements, context, source };
}

const loadingSavedPet = form({ species: "Cat", breed: "Bengal", coat: "Longhair Coat" });
const loadingNewPet = form({ species: "Dog" });
loadingNewPet.chooseBreed("Beagle");
const loadingCustomPet = form({ species: "Cat" });
loadingCustomPet.chooseBreed(OTHER_BREED);
loadingCustomPet.change(loadingCustomPet.breedInput, "Custom cat", "input");
const loadingReopenedPet = form({ species: "Dog" });
loadingReopenedPet.chooseBreed("Beagle");
loadingReopenedPet.breedControl.setValue("Beagle");
loadingReopenedPet.coatControl.update({ preserveValue: true, autoSelect: false });
releaseCatalogue(catalogue);
await breedCoatCatalogueReady;

test("catalogue loading preserves saved edits and updates choices already made by the user", () => {
  assert.equal(loadingSavedPet.breedInput.value, "Bengal");
  assert.equal(loadingSavedPet.coatInput.value, "Longhair Coat");
  assert.equal(loadingNewPet.coatInput.value, "Smooth Short Coat");
  assert.equal(loadingCustomPet.coatInput.disabled, false);
  assert.equal(loadingCustomPet.coatInput.value, "");
  assert.equal(loadingReopenedPet.coatInput.value, "");
});

test("no species disables both controls, and species enables only Breed", () => {
  const ui = form();
  assert.equal(ui.breedInput.disabled, true);
  assert.equal(ui.breedToggle.disabled, true);
  assert.equal(ui.coatInput.disabled, true);
  ui.change(ui.petTypeInput, "Dog");
  assert.equal(ui.breedInput.disabled, false);
  assert.equal(ui.coatInput.disabled, true);
  assert.deepEqual(getCoatOptions("Beagle", ""), []);
});

for (const [species, single, coat, multi, multiCoat] of [
  ["Dog", "French Bulldog", "Smooth Short Coat", "German Shepherd", "Long Stock Coat"],
  ["Cat", "Persian", "Long Dense Coat", "Bengal", "Longhair Coat"],
]) {
  test(`${species}: known single coats auto-select; multiple coats require a choice`, () => {
    const ui = form({ species });
    ui.chooseBreed(OTHER_BREED);
    assert.equal(ui.breedInput.readOnly, false);
    ui.change(ui.breedInput, single.slice(0, 3), "input");
    assert.ok(ui.breedList.children.some(child => child.dataset.breedValue === single));
    ui.chooseBreed(single);
    assert.equal(ui.coatInput.value, coat);
    assert.equal(ui.coatInput.disabled, false);
    ui.chooseBreed(multi);
    assert.equal(ui.coatInput.value, "");
    ui.chooseCoat(multiCoat);
    assert.equal(ui.coatInput.value, multiCoat);
    assert.equal(ui.coatControl.getValidationMessage(), "");
  });

  test(`${species}: Mixed, Unknown and custom breeds offer generic fur without auto-selection`, () => {
    const ui = form({ species });
    for (const breed of [getMixedBreed(species), UNKNOWN_BREED]) {
      ui.chooseBreed(breed);
      assert.equal(ui.breedInput.readOnly, true);
      assert.equal(ui.coatInput.disabled, false);
      assert.equal(ui.coatInput.value, "");
    }
    ui.chooseBreed(OTHER_BREED);
    assert.equal(ui.breedInput.value, "");
    assert.equal(ui.coatInput.disabled, true);
    ui.change(ui.breedInput, "My custom breed", "input");
    assert.equal(ui.coatInput.disabled, false);
    assert.equal(ui.coatToggle.disabled, false);
    assert.equal(ui.coatInput.value, "");
    const genericCoat = species === "Dog" ? "Wire Coat" : "Rex Coat";
    ui.chooseCoat(genericCoat);
    assert.equal(ui.coatInput.value, genericCoat);
    assert.equal(ui.coatControl.getValidationMessage(), "");
    assert.deepEqual(getCoatOptions("My custom breed", species), catalogue[species][UNKNOWN_BREED]);
    ui.change(ui.breedInput, "123", "input");
    assert.ok(ui.breedControl.getValidationMessage());
  });

  test(`${species}: opening and refreshing an edit preserves saved values until a dependency changes`, async () => {
    const ui = form({ species, breed: multi, coat: multiCoat });
    await Promise.resolve();
    assert.equal(ui.breedInput.value, multi);
    assert.equal(ui.coatInput.value, multiCoat);
    ui.change(ui.petTypeInput, species);
    ui.change(ui.breedInput, multi);
    assert.equal(ui.coatInput.value, multiCoat);
    ui.chooseBreed(single);
    assert.equal(ui.coatInput.value, coat);
    ui.change(ui.petTypeInput, species === "Dog" ? "Cat" : "Dog");
    assert.equal(ui.breedInput.value, "");
    assert.equal(ui.coatInput.value, "");
    assert.equal(ui.coatInput.disabled, true);
    ui.change(ui.petTypeInput, "");
    assert.equal(ui.breedInput.disabled, true);
  });

  test(`${species}: typing a known breed applies its exact options`, () => {
    const ui = form({ species });
    ui.chooseBreed(OTHER_BREED);
    ui.change(ui.breedInput, ` ${single.toLowerCase()} `, "input");
    assert.equal(ui.coatInput.value, coat);
    ui.change(ui.breedInput, multi, "input");
    assert.equal(ui.coatInput.value, "");
    ui.chooseCoat(multiCoat);
    assert.equal(ui.coatInput.value, multiCoat);
  });
}

test("Beagle auto-selects and Dachshund leaves its multiple fur types unselected", () => {
  const ui = form({ species: "Dog" });
  ui.chooseBreed("Beagle");
  assert.equal(ui.coatInput.value, "Smooth Short Coat");
  ui.chooseBreed("Dachshund");
  assert.equal(ui.coatInput.value, "");
  ui.chooseCoat("Wirehaired Coat");
});

test("clearing a saved fur type on species change notifies form consumers", () => {
  const ui = form({ species: "Dog", breed: "Beagle", coat: "Smooth Short Coat" });
  const changes = [];
  ui.coatInput.addEventListener("change", () => changes.push(ui.coatInput.value));
  ui.change(ui.petTypeInput, "Cat");
  assert.deepEqual(changes, [""]);
});

test("an edit retains a coat still available after a breed change", () => {
  const ui = form({ species: "Cat", breed: "Bengal", coat: "Longhair Coat" });
  ui.chooseBreed("American Curl");
  assert.equal(ui.coatInput.value, "Longhair Coat");
  assert.equal(ui.coatControl.getValidationMessage(), "");
});

for (const integration of ["my-pets", "admin-customers", "booking-pet-step", "walk-in-pet-step"]) {
  test(`${integration}: actual field wiring enforces the species dependency`, () => {
    const ui = form({ integration });
    assert.equal(ui.breedInput.disabled, true);
    ui.change(ui.petTypeInput, "Dog");
    assert.equal(ui.breedInput.disabled, false);
    ui.chooseBreed("Beagle");
    assert.equal(ui.coatInput.value, "Smooth Short Coat");
    ui.change(ui.petTypeInput, "Cat");
    assert.equal(ui.breedInput.value, "");
    assert.equal(ui.coatInput.value, "");
    ui.chooseBreed("Persian");
    assert.equal(ui.coatInput.value, "Long Dense Coat");
  });

  for (const [species, breed, coat] of [
    ["Dog", "Beagle", "Smooth Short Coat"], ["Dog", "German Shepherd", "Long Stock Coat"],
    ["Dog", "Mixed Breed / Aspin", "Wire Coat"], ["Dog", UNKNOWN_BREED, "Wavy Coat"],
    ["Dog", "Custom dog", "Curly Coat"],
    ["Cat", "Persian", "Long Dense Coat"], ["Cat", "Bengal", "Longhair Coat"],
    ["Cat", "Mixed Breed / Puspin", "Rex Coat"], ["Cat", UNKNOWN_BREED, "Fine Down Coat"],
    ["Cat", "Custom cat", "Shorthair Coat"],
    ["Dog", "Beagle", ""], ["Cat", "Persian", ""],
  ]) {
    test(`${integration}: restores ${species} / ${breed} / ${coat || "unset fur"} and preserves it on repeated species events`, async () => {
      const ui = form({ integration });
      const petTypeCombobox = { setValue(value) { ui.petTypeInput.value = value; } };
      const noop = () => {};
      const weightTools = { breedCoatCatalogueReady, initializeWeightField: noop, formatSavedWeight: () => "",
        getSizeForWeight: noop };
      const shared = { ...ui.context, ...weightTools, breedCombobox: ui.breedControl, breedCoatCombobox: ui.coatControl,
        petTypeCombobox, genderCombobox: { setValue: noop }, sizeCombobox: { setValue: noop },
        syncWeightAndSize: noop, setStoredSize: noop };
      if (integration === "my-pets") {
        const handler = ui.source.slice(ui.source.indexOf("async function openEditModal("), ui.source.indexOf("  function showModal()"));
        const open = vm.runInNewContext(`(${handler.trim()})`, {
          ...shared, allPets: [{ pet_id: 1, species, breed, fur_type: coat }], editingPet: null,
          syncEditVerifiedIndicators: noop, petModalTitle: element(), petBirthdate: element(), petWeight: element(),
          normalizeSpecies: value => value, hideFormError: noop, showModal: noop,
        });
        await open(1);
        await open(1);
      } else if (integration === "admin-customers") {
        const handler = ui.source.slice(ui.source.indexOf("    async startEditPet()"), ui.source.indexOf("    cancelEditPet()"));
        const edit = vm.runInNewContext(`({${handler}}).startEditPet`, {
          adminCustomerPetFields: { tools: weightTools, elements: ui.elements,
            controls: { species: petTypeCombobox, gender: shared.genderCombobox, breed: ui.breedControl, furType: ui.coatControl } },
        });
        const owner = { petModal: { pet: { species, breed, furType: coat } }, initializePetFormFields: async () => true,
          normalizePetSpecies: value => value, syncPetWeightAndSize: noop, setStoredPetSize: noop, refreshIcons: noop };
        await edit.call(owner);
        await edit.call(owner);
      } else {
        const name = integration === "booking-pet-step" ? "restoreAddPetFormDraft" : "restorePetStepDraft";
        const handler = ui.source.slice(ui.source.indexOf(`function ${name}()`), ui.source.indexOf("function validatePetForm("));
        const draft = { newPetForm: { petType: species, breed, furType: coat } };
        const restore = vm.runInNewContext(`(${handler.trim()})`, {
          ...shared, state: {}, readStepTwoDraft: () => draft, readPetStepDraft: () => draft,
        });
        restore();
        restore();
      }
      assert.equal(ui.breedInput.value, breed);
      assert.equal(ui.coatInput.value, coat);
      assert.equal(ui.breedInput.disabled, false);
      ui.change(ui.petTypeInput, species);
      assert.equal(ui.breedInput.value, breed);
      assert.equal(ui.coatInput.value, coat);
      ui.change(ui.petTypeInput, species === "Dog" ? "Cat" : "Dog");
      assert.equal(ui.breedInput.value, "");
      assert.equal(ui.coatInput.value, "");
    });
  }
}
