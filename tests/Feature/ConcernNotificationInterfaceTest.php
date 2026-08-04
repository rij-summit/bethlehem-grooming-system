<?php

namespace Tests\Feature;

use Tests\TestCase;

class ConcernNotificationInterfaceTest extends TestCase
{
    private string $apiLayer;

    private string $clientDashboard;

    private string $clientPetPage;

    private string $clientPetComponent;

    private string $notificationController;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiLayer = file_get_contents(base_path('scripts/api.js'));
        $this->clientDashboard = file_get_contents(
            base_path('scripts/components/client-dashboard.js'),
        );
        $this->clientPetPage = file_get_contents(
            base_path('pages/client/pet-details.html'),
        );
        $this->clientPetComponent = file_get_contents(
            base_path('scripts/components/pet-details.js'),
        );
        $this->notificationController = file_get_contents(
            base_path('app/Http/Controllers/CustomerNotificationController.php'),
        );
    }

    public function test_central_api_helpers_cover_staff_release_customer_read_and_notification_read(): void
    {
        foreach ([
            'notifyCustomerAboutAdminBookingPetMedicalConcern',
            'getPetMedicalConcerns',
            'getPetMedicalConcern',
            'markCustomerNotificationRead',
        ] as $helper) {
            $this->assertStringContainsString(
                "async function {$helper}",
                $this->apiLayer,
            );
            $this->assertMatchesRegularExpression(
                '/\n\s+'.preg_quote($helper, '/').',/',
                $this->apiLayer,
            );
        }

        $this->assertStringContainsString('/notify-customer`', $this->apiLayer);
        $this->assertStringNotContainsString('fetch(', $this->clientPetComponent);
        $this->assertStringNotContainsString('fetch(', $this->clientDashboard);
    }

    public function test_concern_bell_navigation_uses_linked_metadata_after_marking_read(): void
    {
        $clickHandler = $this->sourceBetween(
            $this->clientDashboard,
            'const notification = notifications[Number(el.dataset.notifIndex)];',
            '} catch { /* silent */ }',
        );

        $readPosition = strpos(
            $clickHandler,
            'await API.markCustomerNotificationRead(id);',
        );
        $navigatePosition = strpos(
            $clickHandler,
            'window.location.href = notification.destination;',
        );

        $this->assertNotFalse($readPosition);
        $this->assertNotFalse($navigatePosition);
        $this->assertLessThan($navigatePosition, $readPosition);
        $this->assertStringContainsString(
            '["grooming_medical_concern", "grooming_clinic_referral_requested", "grooming_clinic_referral_accepted"]',
            $clickHandler,
        );

        foreach ([
            "'groomingMedicalConcern:id,public_id,pet_id'",
            "'groomingMedicalConcern.pet:pet_id,pet_name,species'",
            "'concern_public_id' => \$concern?->public_id",
            '$referralPet?->pet_id ?? $pet?->pet_id',
            ': $this->concernDestination(',
        ] as $linkage) {
            $this->assertStringContainsString(
                $linkage,
                $this->notificationController,
            );
        }

        $this->assertStringContainsString(
            "'tab' => 'notifications'",
            $this->notificationController,
        );
        $this->assertStringContainsString(
            "'concern' => \$publicId",
            $this->notificationController,
        );
    }

    public function test_notifications_tab_has_list_detail_and_all_required_states(): void
    {
        foreach ([
            'Medical-Concern Notifications',
            'id="petConcernNotifications"',
            'id="petConcernDetail"',
            'Loading medical-concern notifications...',
            'No medical-concern notifications are available for this pet.',
            'Medical-concern notifications could not be loaded',
            'Pet profile not found',
            'Medical concern not found',
            'data-retry-concerns',
            'data-retry-concern-detail',
            'View concern details',
            'Back to notifications',
        ] as $content) {
            $this->assertStringContainsString(
                $content,
                $this->clientPetPage.$this->clientPetComponent,
            );
        }

        foreach ([
            'concern.concern_date',
            'concern.customer_message',
            'concern.severity',
            'concern.recommended_grooming_action',
            'concern.applied_grooming_action',
            'concern.status',
            'concern.acknowledgment_required',
            'concern.consent_required',
            'concern.customer_response_status',
            'concern.customer_notified_at',
            'concern.clinic_appointment_reference',
            'concern.customer_resolution_summary',
            'concern.resolved_at',
            'concern.required_customer_action',
        ] as $safeField) {
            $this->assertStringContainsString($safeField, $this->clientPetComponent);
        }
    }

    public function test_client_wording_distinguishes_workflow_urgency_recommendation_and_applied_action(): void
    {
        foreach ([
            'Continue with observation',
            'Pause grooming',
            'Stop grooming',
            'workflow urgency and is not a final veterinary diagnosis',
            'A recommendation only; it does not confirm the action was applied.',
            'No applied action has been recorded.',
            'Your decision is required',
            'Acknowledgment required',
            'Reading this notice does not count as acknowledgment.',
        ] as $wording) {
            $this->assertStringContainsString($wording, $this->clientPetComponent);
        }
    }

    public function test_customer_concern_response_interface_is_accessible_responsive_and_preserves_other_tabs(): void
    {
        foreach ([
            'data-pet-panel="overview"',
            'data-pet-panel="grooming"',
            'data-pet-panel="medical"',
            'data-pet-panel="vaccinations"',
            'data-pet-panel="notifications"',
            'sm:grid-cols-2',
            'xl:grid-cols-3',
            'min-h-11',
            'aria-live="polite"',
        ] as $content) {
            $this->assertStringContainsString(
                $content,
                $this->clientPetPage.$this->clientPetComponent,
            );
        }

        foreach ([
            'acknowledgePetMedicalConcern',
            'submitPetMedicalConcernConsent',
            'data-submit-concern-acknowledgment',
            'data-submit-concern-consent="approved"',
            'data-submit-concern-consent="declined"',
            'data-concern-signature',
            'response_statement',
            'role="alert"',
            'aria-live="polite"',
            'maxlength="200"',
        ] as $responseControl) {
            $this->assertStringContainsString(
                $responseControl,
                $this->clientPetPage.$this->clientPetComponent.$this->apiLayer,
            );
        }

        $this->assertStringNotContainsString('fetch(', $this->clientPetComponent);
        $this->assertStringNotContainsString('checked value="approved"', $this->clientPetComponent);

        foreach ([
            'concern.internal_description',
            'concern.internal_resolution_notes',
            'concern.report_token',
            'concern.reported_by_user_id',
            'concern.booking_pet_id',
            'concern.payment',
            'concern.inventory',
        ] as $privateField) {
            $this->assertStringNotContainsString(
                $privateField,
                $this->clientPetComponent,
            );
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
