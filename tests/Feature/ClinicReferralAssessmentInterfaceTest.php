<?php

namespace Tests\Feature;

use Tests\TestCase;

class ClinicReferralAssessmentInterfaceTest extends TestCase
{
    private string $api;

    private string $clinicPage;

    private string $clinicComponent;

    private string $schedulesPage;

    private string $concernComponent;

    private string $petDetails;

    private string $clientDashboard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->api = file_get_contents(base_path('scripts/api.js'));
        $this->clinicPage = file_get_contents(base_path('pages/admin/clinic.html'));
        $this->clinicComponent = file_get_contents(base_path('scripts/components/admin-clinic.js'));
        $this->schedulesPage = file_get_contents(base_path('pages/admin/appointments.html'));
        $this->concernComponent = file_get_contents(base_path('scripts/components/admin-grooming-concerns.js'));
        $this->petDetails = file_get_contents(base_path('scripts/components/pet-details.js'));
        $this->clientDashboard = file_get_contents(base_path('scripts/components/client-dashboard.js'));
    }

    public function test_existing_consultation_helpers_are_reused_with_completion_payload(): void
    {
        $this->assertStringContainsString('async function clinicStartConsultation', $this->api);
        $this->assertStringContainsString('async function clinicFinishConsultation(id, payload = {})', $this->api);
        $this->assertStringContainsString('/start-consultation', $this->api);
        $this->assertStringContainsString('/finish-consultation', $this->api);
        $this->assertStringNotContainsString('fetch(', $this->clinicComponent);
        $this->assertStringContainsString('API.clinicFinishConsultation(modal.appointment.id, {', $this->clinicComponent);
        $this->assertStringContainsString('internal_resolution_notes:', $this->clinicComponent);
        $this->assertStringContainsString('customer_resolution_summary:', $this->clinicComponent);
    }

    public function test_clinic_cards_require_stop_and_completion_dialog_has_no_clearance_choice(): void
    {
        foreach ([
            'Stop Grooming Required',
            'Grooming session stopped',
            'Clinic assessment completed',
            'grooming will not resume during this visit',
        ] as $copy) {
            $this->assertStringContainsString($copy, $this->clinicComponent.$this->clinicPage);
        }

        $dialog = $this->sourceBetween(
            $this->clinicPage,
            'Referral-linked consultation',
            '<!-- Medical Record Modal -->',
        );
        foreach ([
            'Finish Clinic Assessment',
            'The grooming session has ended.',
            'Internal assessment/resolution notes',
            'Customer-friendly assessment summary',
            'maxlength="5000"',
            'maxlength="2000"',
            'Confirm Permanent Assessment Result',
            'Moves to For Payment',
            'no treatment or payment is automatically authorized',
            'Grooming Session Stopped',
        ] as $copy) {
            $this->assertStringContainsString($copy, $dialog);
        }
        $this->assertStringNotContainsString('cleared_to_resume', $dialog);
        $this->assertStringNotContainsString('do_not_resume', $dialog);
        $this->assertStringNotContainsString('grooming_clearance_status', $dialog);
        $this->assertStringContainsString(':disabled="clinicAssessmentCompletion.saving"', $dialog);
        $this->assertStringContainsString('role="dialog"', $this->clinicPage);
        $this->assertStringContainsString('role="alertdialog"', $dialog);
    }

    public function test_admin_schedules_offers_explicit_clinic_transfer_stop_and_statuses(): void
    {
        $this->assertStringContainsString('Stop Grooming for Clinic Transfer', $this->schedulesPage);
        $this->assertStringContainsString('clinic_transfer_stop: true', $this->concernComponent);
        $this->assertStringContainsString(
            "medicalConcernActionAvailable(concern, 'stop_for_clinic_transfer')",
            $this->schedulesPage,
        );
        $this->assertStringContainsString(
            'This explicitly ends grooming for this visit so the accepted clinic consultation can begin.',
            $this->concernComponent,
        );
        foreach ([
            'Grooming session stopped',
            'pet transferred to clinic care',
            'Clinic assessment completed',
            'grooming will not resume during this visit',
            'Stopped-payment review',
            'Grooming payment',
            'Physical pickup',
            'Customer-safe resolution summary',
        ] as $copy) {
            $this->assertStringContainsString($copy, $this->schedulesPage);
        }
    }

    public function test_customer_views_use_safe_assessment_fields_and_both_notification_types(): void
    {
        foreach ([
            'clinic_assessment_started_at',
            'clinic_assessment_completed_at',
            'grooming_outcome_label',
            'customer_next_step',
            'customer_resolution_summary',
            'grooming_clinic_assessment_started',
            'grooming_clinic_assessment_completed',
        ] as $safeField) {
            $this->assertStringContainsString($safeField, $this->petDetails);
        }
        $this->assertStringContainsString('grooming_clinic_assessment_started', $this->clientDashboard);
        $this->assertStringContainsString('grooming_clinic_assessment_completed', $this->clientDashboard);
        $this->assertStringNotContainsString('internal_resolution_notes', $this->petDetails);
        $this->assertStringNotContainsString('emergency_without_consent_reason', $this->petDetails);
        $this->assertStringNotContainsString('resolved_by_user_id', $this->petDetails);
    }

    private function sourceBetween(string $source, string $start, string $end): string
    {
        $startPosition = strpos($source, $start);
        $this->assertNotFalse($startPosition, "Missing start marker: {$start}");
        $endPosition = strpos($source, $end, $startPosition);
        $this->assertNotFalse($endPosition, "Missing end marker: {$end}");

        return substr($source, $startPosition, $endPosition - $startPosition);
    }
}
