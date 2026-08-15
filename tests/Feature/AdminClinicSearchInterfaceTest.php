<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminClinicSearchInterfaceTest extends TestCase
{
    public function test_clinic_search_labels_owners_and_supports_direct_pet_results(): void
    {
        $page = file_get_contents(base_path('pages/admin/clinic.html'));
        $component = file_get_contents(base_path('scripts/components/admin-clinic.js'));

        foreach ([
            'petSearchResults.length > 0',
            "c.recordType === 'unregistered' ? 'Unregistered' : 'Active'",
            '@click="pickPet(pet)"',
            'data-lucide="dog"',
            'data-lucide="cat"',
            'x-text="petResultSummary(pet)"',
            '<p class="text-xs text-slate-400">Owner: <span x-text="pet.ownerName"></span></p>',
        ] as $searchUi) {
            $this->assertStringContainsString($searchUi, $page);
        }

        foreach ([
            'API.searchWalkInCustomers(q)',
            'this.petSearchResults = res.pets || [];',
            'API.getUnregisteredCustomerDetails(c.id)',
            'async pickPet(result)',
            'petResultSummary(pet)',
            '].filter(Boolean).join(" · ");',
            'owner_record_type: c.recordType || "registered"',
            'unregistered_customer_id: c.recordType === "unregistered" ? c.id : undefined',
        ] as $searchBehavior) {
            $this->assertStringContainsString($searchBehavior, $component);
        }
    }

    public function test_clinic_new_pet_queue_form_uses_smart_fields_without_deceased_controls(): void
    {
        $page = file_get_contents(base_path('pages/admin/clinic.html'));
        $component = file_get_contents(base_path('scripts/components/admin-clinic.js'));

        foreach ([
            'id="clinicQueuePetSpeciesCombobox"',
            'id="clinicQueuePetBreedCombobox"',
            'id="clinicQueuePetFurTypeCombobox"',
            'id="clinicQueuePetSizeCombobox"',
            'id="clinicQueuePetNeutered"',
            'id="clinicQueueChiefComplaint"',
            'Pet Name <span class="text-red-500">*</span>',
            'Breed <span class="text-red-500">*</span>',
            'Chief Complaint <span class="text-red-500">*</span>',
        ] as $newPetUi) {
            $this->assertStringContainsString($newPetUi, $page);
        }

        $this->assertStringNotContainsString('clinicQueuePetDeceased', $page);

        foreach ([
            'import("./breed-combobox.js")',
            'import("./breed-coat-combobox.js")',
            'import("./fixed-option-combobox.js")',
            'import("./pet-weight-size.js")',
            'if (!payload.breed) return "Breed is required.";',
            'clinic_quick_entry: true',
        ] as $smartBehavior) {
            $this->assertStringContainsString($smartBehavior, $component);
        }
    }
}
