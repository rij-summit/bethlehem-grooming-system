<?php

namespace Tests\Feature;

use Tests\TestCase;

class ClinicReferralInterfaceTest extends TestCase
{
    private string $apiLayer;

    private string $appointmentsPage;

    private string $dashboardComponent;

    private string $staffComponent;

    private string $clientPage;

    private string $clientComponent;

    private string $clientDashboard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiLayer = file_get_contents(base_path('scripts/api.js'));
        $this->appointmentsPage = file_get_contents(base_path('pages/admin/appointments.html'));
        $this->dashboardComponent = file_get_contents(base_path('scripts/components/admin-dashboard.js'));
        $this->staffComponent = file_get_contents(base_path('scripts/components/admin-clinic-referrals.js'));
        $this->clientPage = file_get_contents(base_path('pages/client/pet-details.html'));
        $this->clientComponent = file_get_contents(base_path('scripts/components/pet-details.js'));
        $this->clientDashboard = file_get_contents(base_path('scripts/components/client-dashboard.js'));
    }

    public function test_central_api_helpers_use_only_the_existing_step_17d_routes(): void
    {
        foreach ([
            'getAdminBookingPetClinicReferral',
            'createAdminBookingPetClinicReferral',
            'recordAdminBookingPetClinicReferralInPersonConsent',
            'getPetGroomingClinicReferral',
            'submitPetGroomingClinicReferralConsent',
        ] as $helper) {
            $this->assertStringContainsString("async function {$helper}", $this->apiLayer);
            $this->assertMatchesRegularExpression(
                '/\n\s+'.preg_quote($helper, '/').',/',
                $this->apiLayer,
            );
        }

        $this->assertStringContainsString('/clinic-referral`', $this->apiLayer);
        $this->assertStringContainsString('/in-person-consent`', $this->apiLayer);
        $this->assertStringContainsString('/grooming-clinic-referrals/', $this->apiLayer);
        $this->assertStringNotContainsString('fetch(', $this->staffComponent);
        $this->assertStringNotContainsString('fetch(', $this->clientComponent);
    }

    public function test_staff_component_is_loaded_before_dashboard_and_scoped_to_exact_concern(): void
    {
        $componentPosition = strpos(
            $this->appointmentsPage,
            'scripts/components/admin-clinic-referrals.js',
        );
        $dashboardPosition = strpos(
            $this->appointmentsPage,
            'scripts/components/admin-dashboard.js',
        );

        $this->assertNotFalse($componentPosition);
        $this->assertNotFalse($dashboardPosition);
        $this->assertLessThan($dashboardPosition, $componentPosition);
        $this->assertStringContainsString(
            '...adminClinicReferralState()',
            $this->dashboardComponent,
        );
        $this->assertStringContainsString(
            'return bookingId && bookingPetId && concernId',
            $this->staffComponent,
        );
        $this->assertStringContainsString(
            '? `${bookingId}:${bookingPetId}:${concernId}`',
            $this->staffComponent,
        );
        $this->assertStringContainsString(
            'openClinicReferral(medicalConcernModal.booking, medicalConcernModal.pet, concern, $event.currentTarget)',
            $this->appointmentsPage,
        );
        $this->assertStringContainsString('Refer to Clinic', $this->appointmentsPage);
        $this->assertStringContainsString('View Clinic Referral', $this->staffComponent);
        $this->assertStringContainsString(
            'This pet already has an active clinic referral under another medical concern.',
            $this->staffComponent,
        );
        $this->assertStringContainsString(
            'clinicReferralOtherActive(booking, pet, concern)',
            $this->staffComponent,
        );
    }

    public function test_referral_request_form_has_urgencies_separate_explanations_and_safe_retry_token(): void
    {
        foreach ([
            'Routine',
            'Urgent',
            'Emergency',
            'Internal referral reason',
            'Customer-friendly explanation',
            'The customer will be able to read this explanation.',
            'Allow clinic intake before customer consent',
            'Internal intake-before-consent reason',
            'No clinic appointment is created.',
            'Grooming is not automatically paused or stopped.',
            'Veterinary treatment is not automatically authorized.',
        ] as $content) {
            $this->assertStringContainsString($content, $this->appointmentsPage);
        }

        foreach ([
            'Internal referral reason may not exceed 5,000 characters.',
            'Customer-friendly explanation may not exceed 2,000 characters.',
            'An internal intake-before-consent reason is required.',
            'this.createClinicReferralRequestToken()',
            'request_token: form.request_token',
        ] as $behavior) {
            $this->assertStringContainsString($behavior, $this->staffComponent);
        }

        $payload = $this->sourceBetween(
            $this->staffComponent,
            'clinicReferralPayload() {',
            'async submitClinicReferral() {',
        );
        foreach ([
            'booking_id',
            'booking_pet_id',
            'pet_id',
            'status:',
            'clinic_appointment_id',
            'referred_by_user_id',
        ] as $serverManagedField) {
            $this->assertStringNotContainsString($serverManagedField, $payload);
        }
    }

    public function test_staff_status_includes_required_warnings_and_in_person_consent_is_immutable(): void
    {
        foreach ([
            'Pending Customer Consent',
            'Pending Clinic Acceptance',
            'Accepted by Clinic',
            'Under Clinic Review',
            'Completed',
            'Cancelled',
            'Clinic acceptance is currently blocked',
            'Apply Pause Grooming or Stop Grooming to this exact concern first.',
            'administrator correction or refund workflow will be required',
            'Record In-Person Consent',
            'You are recording the owner or decision-maker’s response. You are not responding on their behalf.',
            'This response is permanent and cannot be edited, replaced, or deleted.',
        ] as $content) {
            $this->assertStringContainsString($content, $this->appointmentsPage.$this->staffComponent);
        }

        $this->assertStringContainsString(
            '!referral.owner_account_linked',
            $this->staffComponent,
        );
        $this->assertStringContainsString(
            'referral.consent_status !== "recorded"',
            $this->staffComponent,
        );
        $this->assertStringNotContainsString('Edit In-Person Consent', $this->appointmentsPage);
        $this->assertStringNotContainsString('Delete In-Person Consent', $this->appointmentsPage);
    }

    public function test_notification_navigation_marks_read_then_uses_safe_destination_metadata(): void
    {
        $handler = $this->sourceBetween(
            $this->clientDashboard,
            'const notification = notifications[Number(el.dataset.notifIndex)];',
            '} catch { /* silent */ }',
        );
        $readPosition = strpos($handler, 'await API.markCustomerNotificationRead(id);');
        $navigationPosition = strpos($handler, 'window.location.href = notification.destination;');

        $this->assertNotFalse($readPosition);
        $this->assertNotFalse($navigationPosition);
        $this->assertLessThan($navigationPosition, $readPosition);
        $this->assertStringContainsString(
            '"grooming_clinic_referral_requested"',
            $handler,
        );
        $this->assertStringContainsString(
            'const requestedReferralPublicId = profileParams.get("referral")',
            $this->clientComponent,
        );
        $this->assertStringContainsString(
            'API.getPetGroomingClinicReferral(petId, publicId)',
            $this->clientComponent,
        );
        $this->assertStringContainsString(
            'API.getCustomerNotifications().catch(() => ({ notifications: [] }))',
            $this->clientComponent,
        );
        foreach ([
            '"grooming_clinic_referral_requested"',
            '"grooming_clinic_referral_accepted"',
            '"grooming_clinic_assessment_started"',
            '"grooming_clinic_assessment_completed"',
        ] as $notificationType) {
            $this->assertStringContainsString($notificationType, $this->clientComponent);
        }
        $this->assertStringContainsString(
            'Number(notification.pet_id) === petId',
            $this->clientComponent,
        );
        $this->assertStringContainsString(
            'data-open-clinic-referral',
            $this->clientComponent,
        );
    }

    public function test_customer_detail_uses_safe_fields_and_permanent_confirmation(): void
    {
        foreach ([
            'Clinic referral request',
            'Why this referral was requested',
            'Clinic acceptance',
            'Clinic appointment reference',
            'Clinic assessment',
            'Your clinic-referral decision is required',
            'No decision is selected for you.',
            'data-clinic-referral-decision="approved"',
            'data-clinic-referral-decision="declined"',
            'data-clinic-referral-signature',
            'Confirm permanent referral response',
            'The original response is permanent and cannot be edited, replaced, or deleted.',
            'consent_responded_by_name',
            'consent_responded_at',
        ] as $content) {
            $this->assertStringContainsString($content, $this->clientComponent);
        }

        foreach ([
            'referral.referral_reason',
            'referral.emergency_without_consent_reason',
            'referral.consent_captured_by_name',
            'referral.referred_by_user_id',
            'referral.internal_resolution_notes',
            'referral.internal_cancellation_reason',
            'referral.statement_hash',
            'referral.signature_name',
            'referral.payment_review',
        ] as $privateField) {
            $this->assertStringNotContainsString($privateField, $this->clientComponent);
        }
    }

    public function test_interfaces_include_loading_error_retry_accessibility_and_responsive_behavior(): void
    {
        foreach ([
            'Loading clinic referral status...',
            'Clinic referral could not be loaded',
            '@click="loadClinicReferralStatus()"',
            'Loading clinic referral details...',
            'Clinic referral could not be loaded',
            'data-retry-clinic-referral',
            'role="dialog"',
            'role="alertdialog"',
            'aria-modal="true"',
            'aria-live="polite"',
            'tabindex="-1"',
            'sm:grid-cols-2',
            'xl:grid-cols-3',
            'overflow-y-auto',
            'min-h-11',
        ] as $content) {
            $this->assertStringContainsString(
                $content,
                $this->appointmentsPage.$this->clientPage.$this->clientComponent,
            );
        }

        $this->assertStringContainsString(
            'this.$nextTick?.(() => returnFocus?.focus?.())',
            $this->staffComponent,
        );
        $this->assertStringContainsString(
            'originatingButton?.focus()',
            $this->clientComponent,
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
