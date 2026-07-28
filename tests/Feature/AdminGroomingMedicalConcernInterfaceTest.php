<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminGroomingMedicalConcernInterfaceTest extends TestCase
{
    private string $appointmentsPage;

    private string $dashboardComponent;

    private string $concernComponent;

    private string $apiLayer;

    private string $customStyles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->appointmentsPage = file_get_contents(
            base_path('pages/admin/appointments.html'),
        );
        $this->dashboardComponent = file_get_contents(
            base_path('scripts/components/admin-dashboard.js'),
        );
        $this->concernComponent = file_get_contents(
            base_path('scripts/components/admin-grooming-concerns.js'),
        );
        $this->apiLayer = file_get_contents(base_path('scripts/api.js'));
        $this->customStyles = file_get_contents(base_path('css/custom.css'));
    }

    public function test_central_api_layer_exposes_all_seven_booking_pet_concern_operations(): void
    {
        foreach ([
            'getAdminBookingPetMedicalConcerns',
            'createAdminBookingPetMedicalConcern',
            'getAdminBookingPetMedicalConcern',
            'updateAdminBookingPetMedicalConcern',
            'cancelAdminBookingPetMedicalConcern',
            'resolveAdminBookingPetMedicalConcern',
            'notifyCustomerAboutAdminBookingPetMedicalConcern',
        ] as $helper) {
            $this->assertStringContainsString("async function {$helper}", $this->apiLayer);
            $this->assertMatchesRegularExpression(
                '/\n\s+'.preg_quote($helper, '/').',/',
                $this->apiLayer,
            );
        }

        $this->assertStringContainsString(
            '/medical-concerns`',
            $this->apiLayer,
        );
        $this->assertStringContainsString('/cancel`', $this->apiLayer);
        $this->assertStringContainsString('/resolve`', $this->apiLayer);
        $this->assertStringContainsString('/notify-customer`', $this->apiLayer);
        $this->assertStringNotContainsString('fetch(', $this->concernComponent);
    }

    public function test_concern_actions_and_indicators_are_scoped_to_each_pet_card(): void
    {
        $this->assertSame(
            4,
            substr_count(
                $this->appointmentsPage,
                '@click="openMedicalConcerns(booking, pet)"',
            ),
        );
        $this->assertSame(
            2,
            substr_count(
                $this->appointmentsPage,
                '@click="openReportMedicalConcern(booking, pet)"',
            ),
        );
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($this->appointmentsPage, 'Report Medical Concern'),
        );
        $this->assertStringContainsString(
            'medicalConcernIndicatorLabel(booking, pet)',
            $this->appointmentsPage,
        );
        $this->assertStringContainsString(
            'const bookingPetId = this.bookingPetIdentifier(pet);',
            $this->concernComponent,
        );
        $this->assertStringContainsString(
            'return bookingId && bookingPetId ? `${bookingId}:${bookingPetId}` : "";',
            $this->concernComponent,
        );
        $this->assertStringContainsString(
            'API.getAdminBookingPetMedicalConcerns(',
            $this->concernComponent,
        );
    }

    public function test_history_has_loading_empty_error_retry_and_complete_lifecycle_states(): void
    {
        foreach ([
            'Loading medical concern history...',
            'No medical concerns have been reported for this pet.',
            'Medical concern history could not be loaded',
            '@click="loadMedicalConcerns()"',
            'Grooming pet record unavailable',
            'Internal staff observation',
            'Customer-visible message',
            'Acknowledgment required',
            'Consent required',
            'Customer response exists',
            'Resolution information',
        ] as $content) {
            $this->assertStringContainsString($content, $this->appointmentsPage);
        }

        foreach ([
            'open: "Open"',
            'awaiting_customer: "Awaiting customer"',
            'referred_to_clinic: "Referred to clinic"',
            'under_clinic_review: "Under clinic review"',
            'resolved: "Resolved"',
            'cancelled: "Cancelled"',
        ] as $status) {
            $this->assertStringContainsString($status, $this->concernComponent);
        }

        foreach (['Low', 'Moderate', 'Urgent'] as $severity) {
            $this->assertStringContainsString("label: \"{$severity}\"", $this->concernComponent);
        }
    }

    public function test_create_form_mirrors_validation_and_records_only_advisory_actions(): void
    {
        foreach ([
            'Concern category is required.',
            'Concern category must not exceed 50 characters.',
            'Select Low, Moderate, or Urgent severity.',
            'Internal staff observation is required.',
            'A separate customer-visible message is required.',
            'Select a recommended grooming action.',
            'Acknowledgment requirement must be Yes or No.',
            'Consent requirement must be Yes or No.',
        ] as $message) {
            $this->assertStringContainsString($message, $this->concernComponent);
        }

        foreach ([
            'continue_with_observation',
            'pause_grooming',
            'stop_grooming',
        ] as $action) {
            $this->assertStringContainsString($action, $this->concernComponent);
        }

        $this->assertStringNotContainsString('refer_to_clinic', $this->concernComponent);
        $this->assertStringContainsString(
            'This records the recommended action. It does not yet pause or stop grooming automatically.',
            $this->appointmentsPage,
        );
        $this->assertStringContainsString(
            'this.createMedicalConcernReportToken()',
            $this->concernComponent,
        );
        $this->assertStringContainsString(
            'payload.report_token = this.medicalConcernForm.report_token;',
            $this->concernComponent,
        );

        $payloadBuilder = $this->sourceBetween(
            $this->concernComponent,
            'buildMedicalConcernPayload() {',
            'async saveMedicalConcern() {',
        );
        foreach ([
            'booking_id',
            'booking_pet_id',
            'pet_id',
            'reported_by_user_id',
            'reported_by_name',
            'reported_at',
            'public_id',
            'status:',
            'customer_response_status',
            'customer_notified_at',
            'clinic_appointment_id',
            'applied_grooming_action',
            'resolved_by_user_id',
            'resolved_at',
        ] as $managedField) {
            $this->assertStringNotContainsString($managedField, $payloadBuilder);
        }
    }

    public function test_idempotent_duplicate_locking_and_terminal_controls_follow_api_state(): void
    {
        $this->assertStringContainsString(
            'response.message || (editing',
            $this->concernComponent,
        );
        $this->assertStringContainsString(
            '/active medical concern.*category|active.*category/i',
            $this->concernComponent,
        );
        $this->assertStringContainsString(
            'Open existing concern history',
            $this->appointmentsPage,
        );
        $this->assertStringContainsString(
            'customerFieldsEditable: Boolean(concern.customer_visible_fields_editable)',
            $this->concernComponent,
        );
        $this->assertStringContainsString(
            ':disabled="!medicalConcernFormModal.customerFieldsEditable"',
            $this->appointmentsPage,
        );
        $this->assertStringContainsString(
            'medicalConcernActionAvailable(concern, "update")',
            $this->concernComponent,
        );
        $this->assertStringContainsString(
            "medicalConcernActionAvailable(concern, 'resolve')",
            $this->appointmentsPage,
        );
        $this->assertStringContainsString(
            "medicalConcernActionAvailable(concern, 'cancel')",
            $this->appointmentsPage,
        );
        $this->assertStringNotContainsString(
            'deleteAdminBookingPetMedicalConcern',
            $this->apiLayer.$this->concernComponent,
        );
    }

    public function test_cancel_and_resolve_preserve_history_and_require_the_expected_summaries(): void
    {
        foreach ([
            'Cancellation preserves the concern history',
            'It does not delete the record.',
            'Resolution closes the concern while preserving its complete history.',
            'An internal cancellation reason is required.',
            'Internal resolution notes are required.',
            'A customer-safe resolution summary is required.',
        ] as $content) {
            $this->assertStringContainsString(
                $content,
                $this->appointmentsPage.$this->concernComponent,
            );
        }

        $this->assertStringContainsString(
            'internal_cancellation_reason: internalReason',
            $this->concernComponent,
        );
        $this->assertStringContainsString(
            'customer_cancellation_summary: customerSummary || null',
            $this->concernComponent,
        );
        $this->assertStringContainsString(
            'internal_resolution_notes: internalReason',
            $this->concernComponent,
        );
        $this->assertStringContainsString(
            'customer_resolution_summary: customerSummary',
            $this->concernComponent,
        );
    }

    public function test_send_to_customer_requires_confirmation_and_refreshes_locked_history(): void
    {
        foreach ([
            'Send to Customer',
            'After sending, customer-visible details can no longer be edited normally.',
            'Customer-visible message',
            'Acknowledgment required',
            'Consent required',
            'No linked customer account',
            'Customer notified',
            'Locked after notification or response',
        ] as $content) {
            $this->assertStringContainsString($content, $this->appointmentsPage);
        }

        foreach ([
            'openMedicalConcernNotifyDialog(concern)',
            'submitMedicalConcernNotification()',
            'API.notifyCustomerAboutAdminBookingPetMedicalConcern(',
            'await this.loadMedicalConcerns(booking, pet);',
            'medicalConcernActionAvailable(concern, "notify_customer")',
        ] as $behavior) {
            $this->assertStringContainsString(
                $behavior,
                $this->appointmentsPage.$this->concernComponent,
            );
        }

        $this->assertStringContainsString(
            '.admin-medical-concern-send',
            $this->customStyles,
        );
        $this->assertStringNotContainsString(
            'resend',
            strtolower($this->appointmentsPage.$this->concernComponent),
        );
    }

    public function test_interface_is_responsive_and_existing_grooming_and_customer_controls_remain(): void
    {
        $this->assertStringContainsString(
            '@media (max-width: 767px)',
            $this->customStyles,
        );
        $this->assertStringContainsString(
            '.admin-medical-concern-dialog',
            $this->customStyles,
        );
        $this->assertStringContainsString(
            'aria-modal="true"',
            $this->appointmentsPage,
        );
        $this->assertStringContainsString(
            'x-ref="medicalConcernValidationSummary"',
            $this->appointmentsPage,
        );
        $this->assertStringContainsString(
            'Confirm Start Grooming',
            $this->dashboardComponent,
        );
        $this->assertStringContainsString(
            'confirmMarkPetDone(booking, pet)',
            $this->appointmentsPage,
        );
        $this->assertStringContainsString(
            'openPaymentModal(booking',
            $this->appointmentsPage,
        );
        $this->assertStringContainsString('confirmPickedUp(booking)', $this->appointmentsPage);

        $clientPetPage = file_get_contents(base_path('pages/client/pet-details.html'));
        $this->assertStringContainsString('data-pet-panel="grooming"', $clientPetPage);
        $this->assertStringContainsString('data-pet-panel="medical"', $clientPetPage);
        $this->assertStringContainsString('data-pet-panel="vaccinations"', $clientPetPage);
    }

    public function test_concern_component_does_not_call_unrelated_grooming_payment_capacity_or_notification_mutations(): void
    {
        foreach ([
            'adminStartPetGrooming',
            'adminMarkPetDone',
            'processPayment',
            'payNow',
            'releaseBooking',
            'markPickedUp',
            'adminUpdateGroomersOnDuty',
            'getNotifications',
            'markNotificationRead',
            'clinicSaveRecord',
        ] as $unrelatedMutation) {
            $this->assertStringNotContainsString(
                "API.{$unrelatedMutation}",
                $this->concernComponent,
            );
        }

        $this->assertStringContainsString(
            'test_concern_lifecycle_has_no_grooming_payment_notification_clinic_or_inventory_side_effects',
            file_get_contents(base_path('tests/Feature/AdminGroomingMedicalConcernApiTest.php')),
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
