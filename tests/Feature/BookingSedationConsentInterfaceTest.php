<?php

namespace Tests\Feature;

use Tests\TestCase;

class BookingSedationConsentInterfaceTest extends TestCase
{
    public function test_customer_sedation_consent_omits_the_optional_helper_text(): void
    {
        $page = file_get_contents(base_path('pages/client/grooming-pre-registration.html'));
        $component = file_get_contents(base_path('scripts/components/booking-consent-step.js'));

        $this->assertStringNotContainsString(
            'Staff can explain sedation and record your consent in person if you agree.',
            $page,
        );
        $this->assertStringNotContainsString('id="sedationConsentHelp"', $page);
        $this->assertStringNotContainsString('sedationConsentHelp', $component);
        $shared = file_get_contents(base_path('scripts/components/grooming-consent.js'));
        $this->assertStringContainsString('id="sedationConsentYes"', $shared);
        $this->assertStringContainsString('id="sedationConsentNo"', $shared);
        $this->assertStringContainsString('...consentPayload', $component);
    }

    public function test_both_consent_flows_share_the_decline_confirmation_and_exact_official_text(): void
    {
        $shared = file_get_contents(base_path('scripts/components/grooming-consent.js'));
        $this->assertStringContainsString('Continue without sedation authorization?', $shared);
        $this->assertStringContainsString('Consent choices', $shared);
        foreach (['booking-consent-step', 'walk-in-consent-step'] as $flow) {
            $component = file_get_contents(base_path('scripts/components/'.$flow.'.js'));
            $this->assertStringContainsString('await consent.confirmSubmission()', $component);
        }
        $terms = \App\Services\GroomingConsentCapture::agreement();
        $this->assertSame('I hereby authorize the clinic and its staff to proceed with the sedation as it is deemed necessary for my pet for grooming. I confirm that the procedure was explained and my pet had undergone necessary test / screening prior to sedation and grooming.', $terms['sedation_paragraph']);
    }

    public function test_staff_booking_details_reuses_the_existing_modal_for_consent_capture(): void
    {
        $page = file_get_contents(base_path('pages/admin/appointments.html'));
        $component = file_get_contents(base_path('scripts/components/admin-dashboard.js'));
        $api = file_get_contents(base_path('scripts/api.js'));

        foreach ([
            'Explain sedation before recording the customer\'s agreement.',
            'Customer understands and agrees to sedation.',
            '@submit.prevent="recordDetailsSedationConsent()"',
            'sedationConsentStatusLabel(detailsBooking)',
        ] as $interface) {
            $this->assertStringContainsString($interface, $page);
        }

        $this->assertStringContainsString('async recordDetailsSedationConsent()', $component);
        $this->assertStringContainsString('API.adminRecordSedationConsent(bookingId)', $component);
        $this->assertStringContainsString('async function adminRecordSedationConsent(bookingId)', $api);
        $this->assertStringContainsString('customer_understood_and_agreed: true', $api);
    }
}
