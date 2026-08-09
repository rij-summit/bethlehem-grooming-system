<?php

namespace Tests\Feature;

use Tests\TestCase;

class PetInformationVerificationInterfaceTest extends TestCase
{
    private string $myPetsPage;

    private string $myPetsComponent;

    private string $petProfilePage;

    private string $petProfileComponent;

    private string $clientDashboard;

    private string $clientDashboardPage;

    private string $notificationController;

    protected function setUp(): void
    {
        parent::setUp();

        $this->myPetsPage = file_get_contents(
            base_path('pages/client/my-pets.html'),
        );
        $this->myPetsComponent = file_get_contents(
            base_path('scripts/components/my-pets.js'),
        );
        $this->petProfilePage = file_get_contents(
            base_path('pages/client/pet-details.html'),
        );
        $this->petProfileComponent = file_get_contents(
            base_path('scripts/components/pet-details.js'),
        );
        $this->clientDashboard = file_get_contents(
            base_path('scripts/components/client-dashboard.js'),
        );
        $this->clientDashboardPage = file_get_contents(
            base_path('pages/client/dashboard.html'),
        );
        $this->notificationController = file_get_contents(
            base_path('app/Http/Controllers/CustomerNotificationController.php'),
        );
    }

    public function test_verified_pet_fields_are_visible_and_protected_by_a_custom_confirmation(): void
    {
        foreach ([
            'clinic_verified_fields',
            'breed: "Breed"',
            'fur_type: "Fur Type"',
            'weight: "Weight"',
            'size: "Size"',
            '>Verified</span>',
            'Change clinic-verified information?',
            'Some of the information you’re changing was verified by Bethlehem Animal Clinic.',
            'Clinic-verified details being changed:',
            'flex items-center justify-between gap-3',
            'role="alertdialog"',
            'aria-modal="true"',
        ] as $content) {
            $this->assertStringContainsString(
                $content,
                $this->myPetsPage
                    .$this->myPetsComponent
                    .$this->petProfileComponent,
            );
        }
    }

    public function test_pet_archive_and_restore_use_the_customer_custom_modal(): void
    {
        foreach ([
            'id="petConfirmationModal"',
            'id="cancelPetConfirmation"',
            'id="confirmPetAction"',
            'title: "Archive pet?"',
            'confirmLabel: "Archive pet"',
            'title: "Restore pet?"',
        ] as $content) {
            $this->assertStringContainsString(
                $content,
                $this->myPetsPage.$this->myPetsComponent,
            );
        }

        $this->assertStringNotContainsString('confirm(', $this->myPetsComponent);
    }

    public function test_my_pets_prevents_duplicate_names_across_active_and_archived_pets(): void
    {
        $petController = file_get_contents(base_path('app/Http/Controllers/PetController.php'));

        foreach ([
            'let allKnownPets = [];',
            'API.getUserPets({ archived: 0 })',
            'API.getUserPets({ archived: 1 })',
            'normalizePetNameForComparison(pet.pet_name) === normalizedPetName',
            'You already have a pet with this name.',
        ] as $clientValidation) {
            $this->assertStringContainsString($clientValidation, $this->myPetsComponent);
        }

        foreach ([
            'normalizePetName($data[\'pet_name\'])',
            'ensureOwnerPetNameIsUnique(',
            "'pet_name' => ['You already have a pet with this name.']",
        ] as $serverValidation) {
            $this->assertStringContainsString($serverValidation, $petController);
        }
    }

    public function test_verified_text_appears_only_in_profile_overview_and_edit_pet(): void
    {
        $petCard = $this->sourceBetween(
            $this->myPetsComponent,
            'function buildCard(pet)',
            'function openAddModal()',
        );

        $this->assertStringNotContainsString('Verified', $petCard);

        foreach ([
            'id="petBreedVerified"',
            'id="petSizeVerified"',
            'id="petFurTypeVerified"',
            'id="petWeightVerified"',
            'text-[10px]',
            'syncEditVerifiedIndicators(pet);',
            'syncEditVerifiedIndicators(null);',
            'my-pets.js?v=20260809-unique-pet-names',
        ] as $editIndicator) {
            $this->assertStringContainsString(
                $editIndicator,
                $this->myPetsPage.$this->myPetsComponent,
            );
        }

        $this->assertStringContainsString(
            'verifiedIndicator(isClinicVerified(pet, field))',
            $this->petProfileComponent,
        );
    }

    public function test_pet_update_notifications_open_the_linked_pet_overview(): void
    {
        $this->assertStringContainsString(
            '"pet_information_updated"',
            $this->clientDashboard,
        );
        $this->assertStringContainsString(
            "'tab' => 'overview'",
            $this->notificationController,
        );
        $this->assertStringContainsString(
            '$updatedPet?->pet_id',
            $this->notificationController,
        );
        $this->assertStringContainsString(
            'window.location.href = notification.destination;',
            $this->clientDashboard,
        );
        $this->assertStringContainsString(
            'client-dashboard.js?v=20260809-pet-notification-routing',
            $this->clientDashboardPage,
        );
        $this->assertStringContainsString(
            'pet-details.js?v=pet-verified-alignment-20260809',
            $this->petProfilePage,
        );
    }

    public function test_my_pets_uses_normal_capitalization_for_labels(): void
    {
        $this->assertStringNotContainsString(
            'uppercase',
            $this->myPetsPage
                .$this->myPetsComponent
                .$this->petProfilePage
                .$this->petProfileComponent,
        );
    }

    private function sourceBetween(
        string $source,
        string $start,
        string $end,
    ): string {
        $startPosition = strpos($source, $start);
        $endPosition = strpos($source, $end, $startPosition ?: 0);

        $this->assertNotFalse($startPosition, "Missing source marker: {$start}");
        $this->assertNotFalse($endPosition, "Missing source marker: {$end}");

        return substr($source, $startPosition, $endPosition - $startPosition);
    }
}
