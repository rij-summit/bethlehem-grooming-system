<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClinicReferralAcceptanceInterfaceTest extends TestCase
{
    private string $api;

    private string $clinicPage;

    private string $clinicComponent;

    private string $referralComponent;

    private string $schedulesPage;

    private string $petDetails;

    private string $clientDashboard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->api = file_get_contents(base_path('scripts/api.js'));
        $this->clinicPage = file_get_contents(base_path('pages/admin/clinic.html'));
        $this->clinicComponent = file_get_contents(base_path('scripts/components/admin-clinic.js'));
        $this->referralComponent = file_get_contents(base_path('scripts/components/admin-clinic-referral-queue.js'));
        $this->schedulesPage = file_get_contents(base_path('pages/admin/appointments.html'));
        $this->petDetails = file_get_contents(base_path('scripts/components/pet-details.js'));
        $this->clientDashboard = file_get_contents(base_path('scripts/components/client-dashboard.js'));
    }

    #[Test]
    public function central_api_helpers_use_the_protected_referral_routes(): void
    {
        $this->assertStringContainsString('function getAdminClinicReferrals', $this->api);
        $this->assertStringContainsString('function getAdminClinicReferral', $this->api);
        $this->assertStringContainsString('function acceptAdminClinicReferral', $this->api);
        $this->assertStringContainsString('`/admin/clinic-referrals${query}`', $this->api);
        $this->assertStringContainsString('`/admin/clinic-referrals/${encodeURIComponent(publicId)}`', $this->api);
        $this->assertStringContainsString('`/admin/clinic-referrals/${encodeURIComponent(publicId)}/accept`', $this->api);
        $this->assertStringNotContainsString('fetch(', $this->referralComponent);
    }

    #[Test]
    public function clinic_page_has_referral_queue_filters_safe_cards_and_pending_count(): void
    {
        $this->assertStringContainsString('Grooming Referrals', $this->clinicComponent);
        $this->assertStringContainsString('Pending Referrals', $this->clinicPage);
        $this->assertStringContainsString('Emergency referrals are listed first', $this->clinicPage);
        $this->assertStringContainsString('All queue statuses', $this->clinicPage);
        $this->assertStringContainsString('All urgencies', $this->clinicPage);
        $this->assertStringContainsString('clinicReferralCardHtml', $this->clinicPage);
        $this->assertStringContainsString('customer_explanation', $this->referralComponent);
        $this->assertStringContainsString('acceptance_blocked_reasons', $this->referralComponent);
        $this->assertStringContainsString('owner_name', $this->referralComponent);
        $this->assertStringContainsString('booking_reference', $this->referralComponent);
        $this->assertStringContainsString('{ key: "referrals",', $this->clinicComponent);
    }

    #[Test]
    public function detail_and_confirmation_explain_intake_without_authorizing_treatment(): void
    {
        foreach ([
            'Internal referral reason',
            'Customer-friendly explanation',
            'Grooming safety state',
            'Referral consent',
            'Acceptance is currently blocked',
            'Accept Referral',
            'Confirm permanent clinic acceptance',
            'same-day Checked In clinic appointment',
            'does not authorize diagnostics, medication, treatment',
            'Consultation has not started yet',
        ] as $copy) {
            $this->assertStringContainsString($copy, $this->clinicPage);
        }

        $this->assertStringContainsString('clinicReferralModal.saving', $this->clinicPage);
        $this->assertStringContainsString('returnFocus', $this->referralComponent);
        $this->assertStringContainsString('clinicReferralQueueDialog', $this->clinicPage);
        $this->assertStringContainsString('role="dialog"', $this->clinicPage);
        $this->assertStringContainsString('role="alertdialog"', $this->clinicPage);
    }

    #[Test]
    public function accepted_state_updates_clinic_schedules_and_customer_views_safely(): void
    {
        foreach ([
            'clinic_appointment_status_label',
            'clinic_queue_number',
            'clinic_appointment_date',
            'accepted_by_name',
            'accepted_at',
        ] as $field) {
            $this->assertStringContainsString($field, $this->schedulesPage);
        }

        $this->assertStringContainsString('clinic_appointment_status_label', $this->petDetails);
        $this->assertStringContainsString('entered clinic intake', $this->petDetails);
        $this->assertStringContainsString('may still require separate approval', $this->petDetails);
        $this->assertStringNotContainsString('clinic_queue_number', $this->petDetails);
        $this->assertStringContainsString('grooming_clinic_referral_accepted', $this->clientDashboard);
        $this->assertStringContainsString('grooming_clinic_referral_accepted', $this->petDetails);
    }

    #[Test]
    public function clinic_sequence_is_shared_by_walkin_checkin_and_referral_acceptance(): void
    {
        $sequence = file_get_contents(base_path('app/Services/ClinicAppointmentSequence.php'));
        $walkin = file_get_contents(base_path('app/Http/Controllers/ClinicWalkinController.php'));
        $clinic = file_get_contents(base_path('app/Http/Controllers/AdminClinicController.php'));
        $acceptance = file_get_contents(base_path('app/Services/GroomingClinicReferralAcceptanceService.php'));

        $this->assertStringContainsString('ClinicSetting::current(lockForUpdate: true)', $sequence);
        $this->assertStringContainsString('$clinicSequence->reserve', $walkin);
        $this->assertStringContainsString('$clinicSequence->nextQueueNumber', $clinic);
        $this->assertStringContainsString('$this->clinicSequence->reserve', $acceptance);
        $this->assertStringContainsString("whereDate('appointment_date', \$appointmentDate)", $sequence);
    }
}
