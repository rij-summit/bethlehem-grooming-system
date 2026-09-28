<?php

namespace Tests\Feature;

use Tests\TestCase;

class ChatbotEnhancementsInterfaceTest extends TestCase
{
    public function test_customer_chatbot_persists_safely_and_supports_reset(): void
    {
        $component = file_get_contents(
            base_path('scripts/components/home-ai-chatbot.js')
        );
        $api = file_get_contents(base_path('scripts/api.js'));
        $styles = file_get_contents(base_path('css/custom.css'));

        foreach ([
            'const conversationStorageKey = "bethlehem.chatbot.conversation.v1";',
            'sessionStorage.setItem(',
            'sessionStorage.removeItem(conversationStorageKey);',
            'function resetConversation()',
            'function containsPrivateInformation(value)',
            'id="ai-chat-reset"',
            'renderAssistantResponse(messageEl, text);',
        ] as $requirement) {
            $this->assertStringContainsString($requirement, $component);
        }

        $this->assertStringContainsString(
            'getCustomerToken(), { suppressAuthRedirect: true }',
            $api
        );
        $this->assertStringContainsString(
            '"bethlehem.chatbot.conversation.v1"',
            $api
        );
        $this->assertStringContainsString(
            '.ai-chatbot-message--bot .ai-chatbot-message__heading',
            $styles
        );
    }

    public function test_insights_management_and_feedback_are_absent(): void
    {
        $sidebar = file_get_contents(base_path('scripts/components/admin-sidebar.js'));
        $api = file_get_contents(base_path('scripts/api.js'));
        $component = file_get_contents(base_path('scripts/components/home-ai-chatbot.js'));
        $routes = file_get_contents(base_path('routes/api.php'));

        $this->assertFileDoesNotExist(base_path('pages/admin/chatbot-insights.html'));
        $this->assertFileDoesNotExist(base_path('scripts/components/admin-chatbot-insights.js'));
        $this->assertStringNotContainsString('chatbot-insights', $sidebar.$api.$routes);
        $this->assertStringNotContainsString('sendChatbotFeedback', $api.$component);
        $this->assertStringNotContainsString('feedback_token', $component.$routes);

        $this->get('/pages/admin/chatbot-insights.html')->assertNotFound();
        $this->getJson('/api/admin/chatbot-insights')->assertNotFound();
        $this->postJson('/api/chatbot/feedback', [])->assertNotFound();
    }
}
