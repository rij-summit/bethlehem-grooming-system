<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminVaccinationInterfaceTest extends TestCase
{
    private string $clinicPage;

    private string $clinicComponent;

    private string $vaccinationComponent;

    private string $apiLayer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinicPage = file_get_contents(base_path('pages/admin/clinic.html'));
        $this->clinicComponent = file_get_contents(base_path('scripts/components/admin-clinic.js'));
        $this->vaccinationComponent = file_get_contents(
            base_path('scripts/components/admin-vaccinations.js'),
        );
        $this->apiLayer = file_get_contents(base_path('scripts/api.js'));
    }

    public function test_vaccinations_are_integrated_into_the_selected_patient_record_workflow(): void
    {
        $this->assertStringContainsString('Patient Record', $this->clinicComponent);
        $this->assertStringContainsString('Medical Record', $this->clinicPage);
        $this->assertStringContainsString("selectSection('vaccinations')", $this->clinicPage);
        $this->assertStringContainsString('x-data="adminClinicVaccinations()"', $this->clinicPage);
        $this->assertStringContainsString('Add Vaccination Record', $this->clinicPage);
        $this->assertStringContainsString('No vaccination records have been added for this pet.', $this->clinicPage);
        $this->assertStringContainsString('Loading vaccination history…', $this->clinicPage);
        $this->assertStringContainsString('@click="loadVaccinations()"', $this->clinicPage);

        $this->assertStringContainsString(
            'section: section || (item.case_type === "vaccination" ? "vaccinations" : "medical")',
            $this->clinicComponent,
        );
        $this->assertStringContainsString(
            'detail: { appt: this.currentAppt }',
            $this->clinicComponent,
        );
    }

    public function test_central_api_layer_exposes_all_six_vaccination_operations(): void
    {
        foreach ([
            'getAdminPetVaccinations',
            'getAdminPetVaccination',
            'createAdminPetVaccination',
            'updateAdminPetVaccination',
            'publishAdminPetVaccination',
            'voidAdminPetVaccination',
        ] as $helper) {
            $this->assertStringContainsString("async function {$helper}", $this->apiLayer);
            $this->assertMatchesRegularExpression(
                '/\n\s+'.preg_quote($helper, '/').',/',
                $this->apiLayer,
            );
        }

        $this->assertStringContainsString(
            '`/admin/pets/${encodeURIComponent(petId)}/vaccinations`',
            $this->apiLayer,
        );
        $this->assertStringContainsString(
            '/publish`',
            $this->apiLayer,
        );
        $this->assertStringContainsString(
            '/void`',
            $this->apiLayer,
        );
    }

    public function test_component_uses_only_the_central_api_and_refreshes_after_draft_changes(): void
    {
        $this->assertStringNotContainsString('fetch(', $this->vaccinationComponent);
        $this->assertStringContainsString(
            'API.getAdminPetVaccinations(requestedPetId)',
            $this->vaccinationComponent,
        );
        $this->assertStringContainsString(
            'API.createAdminPetVaccination(this.pet.id, payload)',
            $this->vaccinationComponent,
        );
        $this->assertStringContainsString(
            'API.updateAdminPetVaccination(',
            $this->vaccinationComponent,
        );
        $this->assertStringContainsString(
            'await this.loadVaccinations();',
            $this->vaccinationComponent,
        );
        $this->assertStringContainsString(
            '<template x-for="record in records"',
            $this->clinicPage,
        );
    }

    public function test_lifecycle_controls_are_state_specific_and_never_hard_delete_records(): void
    {
        $this->assertStringContainsString(
            'x-show="record.state === \'draft\'"',
            $this->clinicPage,
        );
        $this->assertStringContainsString(
            'x-show="record.state === \'published\'"',
            $this->clinicPage,
        );
        $this->assertStringContainsString(
            'record?.state !== "draft"',
            $this->vaccinationComponent,
        );
        $this->assertStringContainsString(
            'record?.state !== "published"',
            $this->vaccinationComponent,
        );
        $this->assertStringContainsString(
            'will be deducted from clinic inventory.',
            $this->vaccinationComponent,
        );
        $this->assertStringContainsString(
            'The record will be published and can no longer be edited.',
            $this->vaccinationComponent,
        );
        $this->assertStringContainsString(
            'Voiding preserves this vaccination as historical information.',
            $this->clinicPage,
        );
        $this->assertStringNotContainsString(
            'deleteAdminPetVaccination',
            $this->apiLayer.$this->vaccinationComponent,
        );
    }

    public function test_form_mirrors_validation_and_does_not_send_server_managed_fields(): void
    {
        foreach ([
            'Select a vaccine from clinic inventory.',
            'Select who administered the vaccine.',
            'Dose amount is required.',
            'Dose amount must be greater than zero.',
            'Dose unit is required.',
            'Next vaccination date cannot be before today.',
            'Choose a supported administration route.',
            'Complete the vaccination details before publication.',
        ] as $message) {
            $this->assertStringContainsString($message, $this->vaccinationComponent);
        }

        $payloadBuilder = $this->sourceBetween(
            $this->vaccinationComponent,
            'buildPayload() {',
            'async saveDraft() {',
        );

        foreach ([
            'pet_id',
            'recorded_by_user_id',
            'published_at',
            'published_by_user_id',
            'voided_at',
            'voided_by_user_id',
        ] as $serverManagedField) {
            $this->assertStringNotContainsString($serverManagedField, $payloadBuilder);
        }
    }

    public function test_vaccine_comes_from_clinic_inventory_search_with_read_only_batch_details(): void
    {
        $form = $this->sourceBetween(
            $this->clinicPage,
            '<!-- Vaccination form dialog (create / edit) -->',
            '<!-- Vaccination detail dialog -->',
        );

        $this->assertStringContainsString('API.getAdminVaccinationOptions()', $this->vaccinationComponent);
        $this->assertStringContainsString('async function getAdminVaccinationOptions', $this->apiLayer);
        $this->assertStringContainsString('"/admin/vaccination-options"', $this->apiLayer);
        $this->assertStringNotContainsString('getAdminInventoryItems', $this->apiLayer.$this->vaccinationComponent);

        $this->assertStringContainsString('x-model="vaccineQuery"', $form);
        $this->assertStringContainsString('filteredVaccines()', $form);
        $this->assertStringContainsString('vaccineAvailabilityLabel(item)', $form);
        $this->assertStringContainsString('From clinic inventory', $form);
        $this->assertStringContainsString('selectedVaccine?.next_batch?.batch_number', $form);
        $this->assertStringContainsString('selectedVaccine?.next_batch?.expiry_date', $form);
        $this->assertStringContainsString('Next Vaccination Date', $form);

        $inventoryForm = $this->sourceBetween($form, '<section x-show="recordKind === \'inventory\'">', '<section x-show="recordKind === \'historical\'"');
        foreach ([
            'x-model="form.vaccine_name"',
            'x-model="form.product_name"',
            'x-model="form.manufacturer"',
            'x-model="form.batch_number"',
            'x-model="form.product_expiry_date"',
            'x-model="form.clinic_appointment_id"',
            'x-model="form.administered_by_name"',
            'No inventory link',
            'Stock will not be deducted.',
            'Next Due Date',
        ] as $removed) {
            $this->assertStringNotContainsString($removed, $inventoryForm);
        }
    }

    public function test_provider_is_an_account_and_the_payload_carries_only_staff_entered_facts(): void
    {
        $form = $this->sourceBetween(
            $this->clinicPage,
            '<!-- Vaccination form dialog (create / edit) -->',
            '<!-- Vaccination detail dialog -->',
        );
        $payloadBuilder = $this->sourceBetween(
            $this->vaccinationComponent,
            'buildPayload() {',
            'async saveDraft() {',
        );

        $this->assertStringContainsString('x-model="form.administered_by_user_id"', $form);
        $this->assertStringContainsString('x-for="provider in veterinarians"', $form);
        $this->assertStringContainsString('x-show="canManageStaff" href="settings.html?tab=security"', $form);
        $this->assertStringContainsString(
            'this.form.administered_by_user_id = this.currentUserId ? String(this.currentUserId) : "";',
            $this->vaccinationComponent,
        );

        $this->assertStringContainsString('id="vaccination-administered-date" type="text" readonly', $form);
        $this->assertStringContainsString('New administrations use today; existing dates are preserved.', $form);
        $this->assertStringContainsString('this.recordKind === "historical" ? {', $payloadBuilder);
        $this->assertStringContainsString('administered_date: nullableText("administered_date")', $payloadBuilder);

        $this->assertStringContainsString('administered_by_user_id: nullableId("administered_by_user_id")', $payloadBuilder);
        $this->assertStringContainsString('inventory_item_id: this.recordKind === "inventory" ? nullableId("inventory_item_id") : null', $payloadBuilder);
        $this->assertStringContainsString('clinic_appointment_id: this.formModal.clinicAppointmentId', $payloadBuilder);
        foreach ([
            'vaccine_name',
            'product_name',
            'manufacturer',
            'batch_number',
            'product_expiry_date',
            'administered_by_name',
        ] as $inventoryManagedField) {
            $this->assertStringContainsString($inventoryManagedField.': nullableText("'.$inventoryManagedField.'")', $payloadBuilder);
        }
    }

    public function test_save_and_finish_case_finalizes_through_publish_instead_of_a_separate_case_call(): void
    {
        $finish = $this->sourceBetween(
            $this->vaccinationComponent,
            'async saveAndFinishCase() {',
            'confirmDeduction(vaccine, confirmLabel) {',
        );

        $this->assertStringContainsString('{ finishCase: true, consumeInventory: true }', $finish);
        $this->assertStringNotContainsString('finishClinicCase', $finish);
        $this->assertStringContainsString('finishCase ? { finish_case: true } : {}', $this->apiLayer);
        $this->assertStringNotContainsString('stockOut(', $this->vaccinationComponent);
    }

    public function test_existing_medical_and_customer_vaccination_interfaces_remain_separate(): void
    {
        $clientPage = file_get_contents(base_path('pages/client/pet-details.html'));
        $clientComponent = file_get_contents(base_path('scripts/components/pet-details.js'));

        $this->assertStringContainsString('Medical Record', $this->clinicPage);
        $this->assertStringContainsString('clinicSaveRecord', $this->clinicComponent);
        $this->assertStringContainsString('clinicUploadAttachment', $this->clinicComponent);
        $this->assertStringContainsString('data-pet-panel="vaccinations"', $clientPage);
        $this->assertStringContainsString(
            'Published vaccination information recorded by the clinic for this pet.',
            $clientPage,
        );
        $this->assertStringContainsString('API.getPetVaccinations(petId)', $clientComponent);
        $this->assertStringNotContainsString(
            'getAdminPetVaccinations',
            $clientComponent,
        );
    }

    private function sourceBetween(string $source, string $start, string $end): string
    {
        $startPosition = strpos($source, $start);
        $endPosition = strpos($source, $end, $startPosition ?: 0);

        $this->assertNotFalse($startPosition, "Missing source marker: {$start}");
        $this->assertNotFalse($endPosition, "Missing source marker: {$end}");

        return substr($source, $startPosition, $endPosition - $startPosition);
    }
}
