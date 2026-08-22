<?php

namespace Tests\Feature;

use Tests\TestCase;

class BookingSedationConsentInterfaceTest extends TestCase
{
    public function test_customer_unchecked_sedation_consent_has_concise_in_person_guidance(): void
    {
        $page = file_get_contents(base_path('pages/client/booking-consent.html'));
        $component = file_get_contents(base_path('scripts/components/booking-consent-step.js'));

        $this->assertStringContainsString(
            'Staff can explain sedation and record your consent in person if you agree.',
            $page,
        );
        $this->assertStringContainsString('id="sedationConsentHelp"', $page);
        $this->assertStringContainsString(
            'sedation_consent: sedationConsentCheckbox.checked',
            $component,
        );
        $this->assertStringContainsString(
            'sedationConsentCheckbox.checked',
            $component,
        );
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
