import assert from "node:assert/strict";
import { canSelectAllSavedPets, showPetSelectionSection } from "../scripts/components/pet-selection-tabs.js";

function element() {
  const classes = new Set();
  return {
    attributes: {},
    classList: {
      add(...names) { names.forEach((name) => classes.add(name)); },
      remove(...names) { names.forEach((name) => classes.delete(name)); },
      toggle(name, enabled) {
        if (enabled) classes.add(name);
        else classes.delete(name);
      },
      contains(name) { return classes.has(name); },
    },
    setAttribute(name, value) { this.attributes[name] = value; },
  };
}

const tabs = {
  existingButton: element(),
  addButton: element(),
  existingSection: element(),
  addSection: element(),
};

showPetSelectionSection(tabs, "existing");
assert.equal(tabs.existingSection.classList.contains("hidden"), false);
assert.equal(tabs.addSection.classList.contains("hidden"), true);
assert.equal(tabs.existingButton.attributes["aria-pressed"], "true");
assert.equal(tabs.existingButton.classList.contains("bg-[#315b7e]"), true);
assert.equal(tabs.addButton.classList.contains("bg-white"), true);

showPetSelectionSection(tabs, "add");
assert.equal(tabs.existingSection.classList.contains("hidden"), true);
assert.equal(tabs.addSection.classList.contains("hidden"), false);
assert.equal(tabs.existingButton.classList.contains("bg-white"), true);
assert.equal(tabs.addButton.attributes["aria-pressed"], "true");
assert.equal(tabs.addButton.classList.contains("bg-[#315b7e]"), true);

const saved = Array.from({ length: 11 }, (_, index) => ({ id: String(index) }));
assert.equal(canSelectAllSavedPets(saved, [], 10), false);
assert.equal(canSelectAllSavedPets(saved.slice(0, 10), [], 10), true);
assert.equal(canSelectAllSavedPets(saved.slice(0, 10), [{ id: "new" }], 10), false);
assert.equal(canSelectAllSavedPets(saved.slice(0, 10), [{ id: "0" }], 10), true);
assert.equal(canSelectAllSavedPets([], [], 10), false);
