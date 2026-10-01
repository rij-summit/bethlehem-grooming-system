<?php

namespace Tests\Feature;

use Tests\TestCase;

class GroomingSinglePageInterfaceTest extends TestCase
{
    public function test_all_grooming_steps_share_one_page(): void
    {
        $page = file_get_contents(base_path('pages/client/grooming-pre-registration.html'));

        foreach (['schedule', 'pets', 'services', 'review', 'consent', 'confirmed'] as $step) {
            $this->assertStringContainsString('data-grooming-step="'.$step.'"', $page);
        }

        $this->assertSame(1, substr_count($page, 'grooming-pre-registration.js'));
        $this->assertStringContainsString('id="bookingConsentForm"', $page);
        $this->assertStringContainsString('id="printableConfirmation"', $page);

        preg_match_all('/\bid="([^"]+)"/', $page, $matches);
        $this->assertCount(count(array_unique($matches[1])), $matches[1]);
    }

    public function test_grooming_navigation_stays_on_the_combined_page(): void
    {
        $page = file_get_contents(base_path('pages/client/grooming-pre-registration.html'));
        $controller = file_get_contents(base_path('scripts/components/grooming-pre-registration.js'));
        $choice = file_get_contents(base_path('pages/client/pre-register.html'));

        $this->assertStringContainsString('href="./grooming-pre-registration.html"', $choice);
        $this->assertStringContainsString('window.history.pushState', $controller);
        $this->assertStringContainsString('window.addEventListener("popstate"', $controller);
        $this->assertStringContainsString('section.hidden = !visible', $controller);
        $this->assertStringNotContainsString('href="./booking-', $page);
        $this->assertStringNotContainsString('href="./booking.html"', $page);
    }

    public function test_clinic_visit_keeps_its_separate_pet_step(): void
    {
        $clinicDate = file_get_contents(base_path('scripts/components/clinic-visit-date.js'));
        $petStep = file_get_contents(base_path('scripts/components/booking-pet-step.js'));

        $this->assertStringContainsString('./booking-pet-details.html?flow=clinic', $clinicDate);
        $this->assertStringContainsString('IS_CLINIC_VISIT', $petStep);
    }

    public function test_each_step_has_a_top_link_directly_to_client_home(): void
    {
        $page = file_get_contents(base_path('pages/client/grooming-pre-registration.html'));

        foreach (['schedule', 'pets', 'services', 'review', 'consent', 'confirmed'] as $step) {
            $section = explode('data-grooming-step="'.$step.'"', $page, 2)[1];
            $section = explode('data-grooming-step="', $section, 2)[0];
            preg_match('/<a\b[^>]*>.*?<\/a>/s', $section, $matches);
            $topLink = $matches[0] ?? '';

            $this->assertStringContainsString('href="./dashboard.html"', $topLink, $step);
            $this->assertStringNotContainsString('data-grooming-target', $topLink, $step);
            $this->assertSame('← Back to Home', trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($topLink)))), $step);
        }

        $this->assertStringContainsString('id="backBtn"', $page);
        foreach (['pets', 'services', 'review'] as $previousStep) {
            $this->assertStringContainsString('data-grooming-target="'.$previousStep.'"', $page);
        }
    }
}
