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
            'detail: { appt, section: "vaccinations" }',
            $this->clinicComponent,
        );
        $this->assertStringContainsString(
            'if (appt.pet?.id)',
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
            'Published records may become eligible for future customer visibility',
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
            'Vaccine name is required.',
            'Administration date is required.',
            'Dose amount must be greater than zero.',
            'Next due date cannot be before the administration date.',
            'Product expiration date cannot be before the administration date.',
            'Choose a supported administration route.',
            'Provider information is required before publication.',
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

    public function test_inventory_appointment_and_provider_fallbacks_are_safe_and_optional(): void
    {
        $this->assertStringContainsString('category: "vaccine"', $this->vaccinationComponent);
        $this->assertStringContainsString(
            'item.category === "vaccine"',
            $this->vaccinationComponent,
        );
        $this->assertStringContainsString('No inventory link', $this->clinicPage);
        $this->assertStringContainsString(
            'Stock will not be deducted.',
            $this->clinicPage,
        );
        $this->assertStringNotContainsString(
            'stockOut(',
            $this->vaccinationComponent,
        );

        $this->assertStringContainsString('No appointment link', $this->clinicPage);
        $this->assertStringContainsString(
            'this.appointment.appointment_reference',
            $this->vaccinationComponent,
        );
        $this->assertStringContainsString(
            'Administering Provider Name',
            $this->clinicPage,
        );
        $this->assertStringNotContainsString(
            'administered_by_user_id:',
            $this->sourceBetween(
                $this->vaccinationComponent,
                'buildPayload() {',
                'async saveDraft() {',
            ),
        );
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
