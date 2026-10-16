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
        $this->assertStringContainsString('sedation_consent: sedation.checked', $shared);
        $this->assertStringContainsString('...consentPayload', $component);
    }

    public function test_unchecked_sedation_does_not_require_an_extra_confirmation(): void
    {
        $page = file_get_contents(base_path('pages/client/grooming-pre-registration.html'));
        $component = file_get_contents(base_path('scripts/components/booking-consent-step.js'));
        $this->assertStringNotContainsString('sedationWarningModal', $page);
        $this->assertStringNotContainsString('sedationWarningAcknowledged', $component);
        $terms = \App\Services\GroomingConsentCapture::agreement();
        $this->assertSame('I authorize sedation if deemed necessary for grooming.', $terms['sedation_action']);
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
