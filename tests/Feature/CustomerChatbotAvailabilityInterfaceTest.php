<?php

namespace Tests\Feature;

use Tests\TestCase;

class CustomerChatbotAvailabilityInterfaceTest extends TestCase
{
    public function test_every_public_and_customer_page_loads_the_chatbot_once(): void
    {
        $publicPage = file_get_contents(base_path('index.html'));
        $publicApiPosition = strpos($publicPage, 'scripts/api.js');
        $publicChatbotPosition = strpos($publicPage, 'home-ai-chatbot.js');

        $this->assertSame(1, substr_count($publicPage, 'home-ai-chatbot.js'));
        $this->assertTrue(
            $publicApiPosition !== false
            && $publicChatbotPosition !== false
            && $publicApiPosition < $publicChatbotPosition
        );

        $customerPages = glob(base_path('pages/client/*.html')) ?: [];

        $this->assertNotEmpty($customerPages);

        foreach ($customerPages as $customerPage) {
            $page = file_get_contents($customerPage);
            $apiPosition = strpos($page, 'scripts/api.js');
            $chatbotPosition = strpos($page, 'home-ai-chatbot.js');

            $this->assertSame(
                1,
                substr_count($page, 'home-ai-chatbot.js'),
                basename($customerPage).' must load the chatbot exactly once.'
            );
            $this->assertTrue(
                $apiPosition !== false
                && $chatbotPosition !== false
                && $apiPosition < $chatbotPosition,
                basename($customerPage).' must load the API before the chatbot.'
            );
        }
    }

    public function test_admin_and_staff_pages_never_load_the_customer_chatbot(): void
    {
        $adminPages = glob(base_path('pages/admin/*.html')) ?: [];
        $adminInventoryPages = glob(base_path('pages/admin/inventory/*.html')) ?: [];

        $this->assertNotEmpty($adminPages);

        foreach ([...$adminPages, ...$adminInventoryPages] as $adminPage) {
            $page = file_get_contents($adminPage);

            $this->assertStringNotContainsString(
                'home-ai-chatbot.js',
                $page,
                basename($adminPage).' must not load the customer chatbot.'
            );
            $this->assertStringNotContainsString('id="ai-chat-button"', $page);
        }
    }

    public function test_customer_pre_registration_has_chatbot_but_staff_walk_in_flow_does_not(): void
    {
        foreach ([
            'pre-register.html',
            'booking.html',
            'booking-pet-details.html',
            'booking-services.html',
            'booking-review.html',
            'booking-consent.html',
            'clinic-visit-date.html',
            'clinic-visit-reason.html',
            'clinic-visit-summary.html',
        ] as $customerStep) {
            $page = file_get_contents(base_path('pages/client/'.$customerStep));

            $this->assertStringContainsString(
                'home-ai-chatbot.js',
                $page,
                $customerStep.' must keep the chatbot available.'
            );
        }

        foreach (glob(base_path('pages/admin/walk-in-*.html')) ?: [] as $walkInStep) {
            $page = file_get_contents($walkInStep);

            $this->assertStringNotContainsString(
                'home-ai-chatbot.js',
                $page,
                basename($walkInStep).' must not show the customer chatbot.'
            );
        }
    }

    public function test_component_injects_its_interface_and_has_an_admin_page_guard(): void
    {
        $component = file_get_contents(
            base_path('scripts/components/home-ai-chatbot.js')
        );

        foreach ([
            'ensureAiChatbotStyles();',
            'ensureAiChatbotMarkup();',
            '/\\/pages\\/admin(?:\\/|$)/i',
            'id="ai-chat-button"',
            'id="ai-chat-panel"',
            'window.API?.sendChatbotMessage',
        ] as $requirement) {
            $this->assertStringContainsString($requirement, $component);
        }
    }
}
