<?php

namespace Tests\Feature;

use Tests\TestCase;

class GroomingReviewAndConsentInterfaceTest extends TestCase
{
    public function test_review_pages_use_one_concise_clinic_price_notice(): void
    {
        $customerPage = file_get_contents(base_path('pages/client/booking-review.html'));
        $walkInPage = file_get_contents(base_path('pages/admin/walk-in-booking-review.html'));
        $customerReview = file_get_contents(base_path('scripts/components/booking-review-step.js'));
        $walkInReview = file_get_contents(base_path('scripts/components/walk-in-review-step.js'));
        $pricing = file_get_contents(base_path('scripts/services/grooming-service.js'));

        foreach ([$customerPage, $walkInPage, $customerReview, $walkInReview, $pricing] as $source) {
            $this->assertStringContainsString('The price is finalized at the clinic.', $source);
        }

        foreach ([
            'This booking contains estimated pricing details.',
            'Estimate only: one or more pets do not have a matching saved size yet',
            'Prices with a + are minimum clinic rates.',
            'This pet does not have a saved size yet, so the package total is shown as an estimate.',
        ] as $removedCopy) {
            $this->assertStringNotContainsString($removedCopy, $customerReview.$walkInReview.$pricing);
        }
    }

    public function test_review_pages_only_show_the_matching_package_size_when_available(): void
    {
        $customerReview = file_get_contents(base_path('scripts/components/booking-review-step.js'));
        $walkInReview = file_get_contents(base_path('scripts/components/walk-in-review-step.js'));

        foreach ([$customerReview, $walkInReview] as $review) {
            $this->assertStringContainsString('function getVisiblePackagePriceOptions(selectedPackage, petSize)', $review);
            $this->assertStringContainsString('const normalizedSize = normalizePetSize(petSize);', $review);
            $this->assertStringContainsString('return selectedPackage.priceOptions;', $review);
            $this->assertStringContainsString(
                '(priceOption) => priceOption.sizeKey === normalizedSize',
                $review,
            );
            $this->assertStringContainsString('${visiblePriceOptions', $review);
            $this->assertStringNotContainsString('getPackagePricingNote', $review);
        }
    }

    public function test_review_summary_removes_the_duplicate_pet_summary_card(): void
    {
        $customerPage = file_get_contents(base_path('pages/client/booking-review.html'));
        $walkInPage = file_get_contents(base_path('pages/admin/walk-in-booking-review.html'));
        $customerReview = file_get_contents(base_path('scripts/components/booking-review-step.js'));
        $walkInReview = file_get_contents(base_path('scripts/components/walk-in-review-step.js'));

        foreach ([$customerPage, $walkInPage, $customerReview, $walkInReview] as $source) {
            $this->assertStringNotContainsString('Pet Summary', $source);
            $this->assertStringNotContainsString('petReviewText', $source);
        }

        $this->assertStringContainsString('Schedule Summary', $customerPage);
        $this->assertStringContainsString('Schedule Summary', $walkInPage);
        $this->assertStringContainsString('Schedule Summary', $walkInReview);
    }

    public function test_time_and_consent_helpers_are_removed_and_step_five_heading_is_unique(): void
    {
        $calendar = file_get_contents(base_path('scripts/components/booking-calendar.js'));
        $customerConsent = file_get_contents(base_path('pages/client/booking-consent.html'));
        $walkInConsent = file_get_contents(base_path('pages/admin/walk-in-consent.html'));
        $walkInConsentStep = file_get_contents(base_path('scripts/components/walk-in-consent-step.js'));

        $this->assertStringNotContainsString('Time passed', $calendar);

        foreach ([$customerConsent, $walkInConsent, $walkInConsentStep] as $consent) {
            $this->assertStringContainsString(
                '<h1 class="text-3xl font-bold text-[#2f4b66]">Consent &amp; Agreement</h1>',
                $consent,
            );
            $this->assertStringNotContainsString('Select your Pet Drop-off Date and Time', $consent);
            $this->assertStringNotContainsString(
                '<h2 class="text-2xl font-bold text-[#2f4b66]">Consent &amp; Agreement</h2>',
                $consent,
            );
        }

        $this->assertStringNotContainsString('sedationConsentHelp', $customerConsent);
    }
}
