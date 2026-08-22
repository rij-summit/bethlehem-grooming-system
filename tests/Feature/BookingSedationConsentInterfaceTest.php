<?php

namespace Tests\Feature;

use Tests\TestCase;

class BookingSedationConsentInterfaceTest extends TestCase
{
    public function test_customer_sedation_consent_omits_the_optional_helper_text(): void
    {
        $page = file_get_contents(base_path('pages/client/booking-consent.html'));
        $component = file_get_contents(base_path('scripts/components/booking-consent-step.js'));

        $this->assertStringNotContainsString(
            'Staff can explain sedation and record your consent in person if you agree.',
            $page,
        );
        $this->assertStringNotContainsString('id="sedationConsentHelp"', $page);
        $this->assertStringNotContainsString('sedationConsentHelp', $component);
        $this->assertStringContainsString(
            'sedation_consent: sedationConsentCheckbox.checked',
            $component,
        );
        $this->assertStringContainsString(
            'sedationConsentCheckbox.checked',
            $component,
        );
    }

    public function test_unchecked_customer_sedation_consent_requires_a_warning_acknowledgment(): void
    {
        $page = file_get_contents(base_path('pages/client/booking-consent.html'));
        $component = file_get_contents(base_path('scripts/components/booking-consent-step.js'));

        foreach ([
            'id="sedationWarningModal"',
            'Sedation Consent Not Selected',
            'it will only be given with <strong>your approval</strong>',
            '<strong>stop grooming</strong> and notify you',
            'id="sedationWarningBackButton"',
            'id="sedationWarningUnderstandButton"',
            'I Understand',
        ] as $interface) {
            $this->assertStringContainsString($interface, $page);
        }

        $this->assertStringContainsString(
            'if (!sedationConsentCheckbox.checked && !sedationWarningAcknowledged)',
            $component,
        );
        $this->assertStringContainsString('sedationWarningAcknowledged = true;', $component);
        $this->assertStringContainsString('form.requestSubmit();', $component);
        $this->assertStringNotContainsString('sedationConsentCheckbox.checked = true', $component);
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
