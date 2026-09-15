<?php

namespace Tests\Feature;

use Tests\TestCase;

class ChatbotResponseFormattingInterfaceTest extends TestCase
{
    public function test_existing_assistant_renderer_supports_semantic_response_blocks(): void
    {
        $component = file_get_contents(
            base_path('scripts/components/home-ai-chatbot.js')
        );

        foreach ([
            'renderAssistantResponse(messageEl, text);',
            'document.createElement("h3")',
            'document.createElement(listType)',
            'document.createElement("li")',
            'document.createElement("p")',
            'document.createElement("br")',
            'const bulletItem = line.match(/^[-*]\\s+(.+)$/);',
            'const numberedItem = line.match(/^(\\d{1,2})[.)]\\s+(.+)$/);',
            'const highlightPattern = /\\*\\*([^*]+)\\*\\*/g;',
            'messageEl.textContent = text;',
        ] as $renderingRequirement) {
            $this->assertStringContainsString(
                $renderingRequirement,
                $component
            );
        }
    }

    public function test_response_typography_is_scoped_to_assistant_content(): void
    {
        $stylesheet = file_get_contents(base_path('css/custom.css'));

        foreach ([
            '.ai-chatbot-message--bot > .ai-chatbot-message__block',
            '.ai-chatbot-message--bot .ai-chatbot-message__heading',
            '.ai-chatbot-message--bot .ai-chatbot-message__paragraph',
            '.ai-chatbot-message--bot .ai-chatbot-message__list',
            '.ai-chatbot-message--bot .ai-chatbot-message__list-item',
            'overflow-wrap: anywhere;',
            'line-height: 1.6;',
        ] as $styleRequirement) {
            $this->assertStringContainsString($styleRequirement, $stylesheet);
        }

        $this->assertStringNotContainsString(
            '.ai-chatbot-message--user .ai-chatbot-message__block',
            $stylesheet
        );
    }
}
