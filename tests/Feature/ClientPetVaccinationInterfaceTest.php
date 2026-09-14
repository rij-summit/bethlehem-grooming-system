<?php

namespace Tests\Feature;

use Tests\TestCase;

class ClientPetVaccinationInterfaceTest extends TestCase
{
    private string $clientPage;

    private string $clientComponent;

    private string $apiLayer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientPage = file_get_contents(base_path('pages/client/pet-details.html'));
        $this->clientComponent = file_get_contents(base_path('scripts/components/pet-details.js'));
        $this->apiLayer = file_get_contents(base_path('scripts/api.js'));
    }

    public function test_client_tab_uses_the_owner_scoped_central_api_helper(): void
    {
        $this->assertStringContainsString(
            'async function getPetVaccinations(petId)',
            $this->apiLayer,
        );
        $this->assertStringContainsString(
            'request("GET", `/pets/${petId}/vaccinations`, null, getCustomerToken())',
            $this->apiLayer,
        );
        $this->assertMatchesRegularExpression(
            '/\n\s+getPetVaccinations,/',
            $this->apiLayer,
        );
        $this->assertStringContainsString(
            'API.getPetVaccinations(petId)',
            $this->clientComponent,
        );
        $this->assertStringNotContainsString('fetch(', $this->clientComponent);
        $this->assertStringNotContainsString(
            'API.getAdminPetVaccinations',
            $this->clientComponent,
        );
    }

    public function test_vaccination_tab_is_read_only_and_uses_the_cached_customer_tab_loader(): void
    {
        $panel = $this->sourceBetween(
            $this->clientPage,
            '<section data-pet-panel="vaccinations"',
            '<section data-pet-panel="notifications"',
        );

        $this->assertStringContainsString('Vaccination History', $panel);
        $this->assertStringContainsString('id="petVaccinationRecords"', $panel);
        $this->assertStringContainsString(
            'if (petProfileReady) void loadPetTabData(selected);',
            $this->clientComponent,
        );
        $this->assertStringContainsString('vaccinations: loadVaccinations,', $this->clientComponent);
        $this->assertStringContainsString(
            '(!retry && vaccinationLoadState === "loaded")',
            $this->clientComponent,
        );

        foreach ([
            '>Add Vaccination',
            '>Edit<',
            '>Publish<',
            '>Void<',
            '>Delete<',
            'inventory',
        ] as $staffAction) {
            $this->assertStringNotContainsString($staffAction, $panel);
        }

        foreach ([
            'createAdminPetVaccination',
            'updateAdminPetVaccination',
            'publishAdminPetVaccination',
            'voidAdminPetVaccination',
        ] as $adminHelper) {
            $this->assertStringNotContainsString($adminHelper, $this->clientComponent);
        }
    }

    public function test_customer_statuses_and_guidance_are_explicit_and_non_alarming(): void
    {
        foreach ([
            'Current',
            'Due soon',
            'Overdue',
            'Unknown',
        ] as $status) {
            $this->assertStringContainsString("label: \"{$status}\"", $this->clientComponent);
        }

        $this->assertStringContainsString(
            'The clinic did not provide a next-due date for this vaccination.',
            $this->clientComponent,
        );
        $this->assertStringContainsString(
            'The recorded next-due date has passed. Contact the clinic for guidance.',
            $this->clientComponent,
        );
        $this->assertStringNotContainsString(
            'reminder will be sent',
            $this->clientComponent,
        );
        $this->assertStringContainsString(
            'Vaccine Product / Batch Expiration Date',
            $this->clientComponent,
        );
    }

    public function test_loading_empty_not_found_error_and_retry_states_are_present(): void
    {
        foreach ([
            'Loading vaccination history...',
            'No published vaccination records are available for this pet yet.',
            'Vaccination history could not be loaded',
            'This pet does not exist or is not available for your account.',
            'data-retry-vaccinations',
            'loadVaccinations({ retry: true })',
        ] as $stateContract) {
            $this->assertStringContainsString($stateContract, $this->clientComponent.$this->clientPage);
        }

        $this->assertStringContainsString('sm:grid-cols-3', $this->clientComponent);
        $this->assertStringContainsString('xl:grid-cols-3', $this->clientComponent);
        $this->assertStringContainsString('flex-col', $this->clientComponent);
    }

    public function test_only_customer_safe_fields_are_rendered(): void
    {
        foreach ([
            'record.vaccine_name',
            'record.administered_date',
            'record.next_due_date',
            'record.due_status',
            'record.product_name',
            'record.manufacturer',
            'record.dose_amount',
            'record.dose_unit',
            'record.route',
            'record.administering_provider',
            'record.batch_number',
            'record.administration_site',
            'record.appointment_reference',
            'record.product_expiry_date',
        ] as $safeField) {
            $this->assertStringContainsString($safeField, $this->clientComponent);
        }

        foreach ([
            'record.id',
            'record.pet_id',
            'record.inventory_item_id',
            'record.administered_by_user_id',
            'record.recorded_by_user_id',
            'record.published_by_user_id',
            'record.voided_by_user_id',
            'record.notes',
            'record.void_reason',
        ] as $internalField) {
            $this->assertStringNotContainsString($internalField, $this->clientComponent);
        }
    }

    public function test_concern_notifications_and_existing_profile_tabs_remain_present(): void
    {
        foreach ([
            'data-pet-panel="overview"',
            'data-pet-panel="grooming"',
            'data-pet-panel="medical"',
            'data-pet-panel="vaccinations"',
            'data-pet-panel="notifications"',
            'Medical-Concern Notifications',
        ] as $existingContent) {
            $this->assertStringContainsString($existingContent, $this->clientPage);
        }
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
