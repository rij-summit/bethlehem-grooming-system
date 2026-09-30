<?php

namespace Tests\Feature;

use Tests\TestCase;

class ChatbotConversationContextInterfaceTest extends TestCase
{
    public function test_chatbot_sends_only_a_small_bounded_recent_history(): void
    {
        $chatbotScript = file_get_contents(
            base_path('scripts/components/home-ai-chatbot.js')
        );
        $apiScript = file_get_contents(base_path('scripts/api.js'));

        $this->assertStringContainsString(
            'const maxContextMessages = 8;',
            $chatbotScript
        );
        $this->assertStringContainsString(
            'const maxContextContentLength = 1200;',
            $chatbotScript
        );
        $this->assertStringContainsString(
            '.slice(-maxContextMessages)',
            $chatbotScript
        );
        $this->assertStringContainsString(
            'rememberConversationMessage("user", message);',
            $chatbotScript
        );
        $this->assertStringContainsString(
            'rememberConversationMessage("assistant", assistantReply);',
            $chatbotScript
        );
        $this->assertStringContainsString(
            'async function sendChatbotMessage(message, history = [])',
            $apiScript
        );
        $this->assertStringContainsString(
            'history: Array.isArray(history) ? history : [],',
            $apiScript
        );
    }

    public function test_public_and_customer_chatbot_pages_load_the_updated_scripts(): void
    {
        $publicPage = file_get_contents(base_path('index.html'));

        $this->assertStringContainsString('scripts/api.js?v=chatbot-20260928', $publicPage);
        $this->assertStringContainsString(
            'scripts/components/home-ai-chatbot.js?v=chatbot-20260930',
            $publicPage
        );

        foreach (glob(base_path('pages/client/*.html')) ?: [] as $pagePath) {
            $page = file_get_contents($pagePath);
            if (! str_contains($page, 'home-ai-chatbot.js')) {
                continue;
            }

            $this->assertStringContainsString(
                '../../scripts/api.js?',
                $page,
                basename($pagePath).' must load the API script.'
            );
            $this->assertStringContainsString(
                '../../scripts/components/home-ai-chatbot.js?v=chatbot-20260930',
                $page,
                basename($pagePath).' must load the updated chatbot script.'
            );
        }
    }
}
