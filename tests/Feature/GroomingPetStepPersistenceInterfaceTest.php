<?php

namespace Tests\Feature;

use Tests\TestCase;

class GroomingPetStepPersistenceInterfaceTest extends TestCase
{
    public function test_customer_and_walk_in_pet_steps_omit_redundant_helper_messages(): void
    {
        $customerPage = file_get_contents(base_path('pages/client/booking-pet-details.html'));
        $walkInPage = file_get_contents(base_path('pages/admin/walk-in-pet-details.html'));
        $customerStep = file_get_contents(base_path('scripts/components/booking-pet-step.js'));
        $walkInStep = file_get_contents(base_path('scripts/components/walk-in-pet-step.js'));

        foreach ([$customerPage, $walkInPage] as $page) {
            $this->assertStringNotContainsString('id="petStepMessage"', $page);
            $this->assertStringContainsString('id="petStepError"', $page);
            $this->assertStringContainsString('class="mb-6 hidden rounded-2xl border border-red-200', $page);
        }

        foreach ([$customerStep, $walkInStep] as $step) {
            $this->assertStringNotContainsString('petStepMessage', $step);
            $this->assertStringNotContainsString('was removed from this', $step);
        }

        $this->assertStringNotContainsString(
            'Choose how you want to add pets to this booking.',
            $customerPage,
        );
        $this->assertStringNotContainsString(
            'Select an existing pet or add a new pet for this walk-in schedule.',
            $walkInPage,
        );
    }

    public function test_customer_pet_inputs_are_restored_after_returning_to_step_two(): void
    {
        $step = file_get_contents(base_path('scripts/components/booking-pet-step.js'));

        foreach ([
            'newPetForm: getFormValues(elements.addPetForm)',
            'activePetSection,',
            'function restoreAddPetFormDraft()',
            'restoreAddPetFormDraft();',
            'elements.addPetForm.addEventListener("input", saveStepTwoDraft);',
            'elements.addPetForm.addEventListener("change", saveStepTwoDraft);',
        ] as $draftBehavior) {
            $this->assertStringContainsString($draftBehavior, $step);
        }
    }

    public function test_walk_in_pet_inputs_and_selected_pets_are_restored_after_returning(): void
    {
        $step = file_get_contents(base_path('scripts/components/walk-in-pet-step.js'));
        $ownerStep = file_get_contents(base_path('scripts/components/walk-in-owner-step.js'));

        foreach ([
            'const WALK_IN_PET_STORAGE_KEY = "walkInPetStep";',
            'pets: state.pets,',
            'newPetForm: getFormValues(elements.addPetForm)',
            'function restorePetStepDraft()',
            'restorePetStepDraft();',
            'elements.addPetForm.addEventListener("input", savePetStepDraft);',
            'elements.addPetForm.addEventListener("change", savePetStepDraft);',
            'savePetStepDraft();',
        ] as $draftBehavior) {
            $this->assertStringContainsString($draftBehavior, $step);
        }

        $this->assertStringContainsString('"walkInPetStep",', $ownerStep);
    }
}
