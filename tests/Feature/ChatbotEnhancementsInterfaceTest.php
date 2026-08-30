<?php

namespace Tests\Feature;

use Tests\TestCase;

class ChatbotEnhancementsInterfaceTest extends TestCase
{
    public function test_customer_chatbot_persists_safely_and_supports_reset_and_feedback(): void
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
            'function appendFeedbackControls(',
            'window.API.sendChatbotFeedback',
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
            '.ai-chatbot-message__feedback',
            $styles
        );
        $this->assertStringContainsString(
            '.ai-chatbot-message--bot .ai-chatbot-message__heading',
            $styles
        );
    }

    public function test_admin_chatbot_insights_page_and_sidebar_are_consistent_and_admin_only(): void
    {
        $page = file_get_contents(base_path('pages/admin/chatbot-insights.html'));
        $script = file_get_contents(
            base_path('scripts/components/admin-chatbot-insights.js')
        );
        $sidebar = file_get_contents(
            base_path('scripts/components/admin-sidebar.js')
        );
        $api = file_get_contents(base_path('scripts/api.js'));

        foreach ([
            'x-data="adminSidebar()"',
            'x-data="adminChatbotInsights()"',
            'Chatbot Insights',
            'Privacy-filtered',
            'Recent unhelpful responses',
            'admin-sidebar-menu',
            'admin-chatbot-insights.js?v=chatbot-safety-insights-20260830',
        ] as $requirement) {
            $this->assertStringContainsStringIgnoringCase($requirement, $page);
        }

        $this->assertStringNotContainsString('home-ai-chatbot.js', $page);
        $this->assertStringContainsString('API.getChatbotInsights', $script);
        $this->assertStringContainsString('API.updateChatbotInsightStatus', $script);
        $this->assertStringContainsString('Chatbot Insights', $sidebar);
        $this->assertStringContainsString(
            '"chatbot-insights.html"',
            $api
        );
    }
}
