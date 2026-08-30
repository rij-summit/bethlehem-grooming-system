<?php

namespace Tests\Unit;

use App\Services\ChatbotLanguageNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ChatbotLanguageNormalizerTest extends TestCase
{
    #[DataProvider('filipinoEnglishForms')]
    public function test_it_recovers_clinic_roots_from_filipino_english_forms(
        string $message,
        string $expectedRoot,
    ): void {
        $normalized = (new ChatbotLanguageNormalizer)
            ->normalizeForIntentMatching($message);

        $this->assertStringContainsString($expectedRoot, $normalized);
    }

    public static function filipinoEnglishForms(): array
    {
        return [
            'repeated first letter' => ['nag rreserve ba kayo?', 'reserve'],
            'repeated first syllable' => ['nagrereserve ba kayo?', 'reserve'],
            'hyphenated repeated syllable' => ['nag-rereserve po ba?', 'reserve'],
            'repeated vowel' => ['aadjust po ba ang schedule?', 'adjust'],
            'prefixed repeated vowel' => ['nag-aadjust ba kayo?', 'adjust'],
            'causative prefix' => ['magpa-book sana ako', 'book'],
            'object focus prefix' => ['irereschedule ko po', 'reschedule'],
            'Filipino suffix' => ['rereservehan ko sana', 'reserve'],
            'mixed with a minor typo' => ['nag-resreve ba kayo?', 'reserve'],
        ];
    }

    public function test_it_does_not_rewrite_an_unrelated_sentence(): void
    {
        $message = 'Write a JavaScript function for sorting numbers.';

        $this->assertSame(
            strtolower($message),
            (new ChatbotLanguageNormalizer)->normalizeForIntentMatching($message),
        );
    }
}
