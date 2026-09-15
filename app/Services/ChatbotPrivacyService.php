<?php

namespace App\Services;

use Illuminate\Support\Str;

class ChatbotPrivacyService
{
    private const PRIVATE_PATTERNS = [
        '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/iu' => '[email removed]',
        '/(?<!\d)(?:\+?63|0)?9\d{9}(?!\d)/u' => '[phone removed]',
        '/\b(?:\d[ -]*?){13,19}\b/u' => '[payment card removed]',
        '/\b(?:cvv|cvc|security\s*code)\s*(?:is|:|=)?\s*\d{3,4}\b/iu' => '[payment code removed]',
        '/\b(?:verification|verify|otp|login|reset|security)\s*code\s*(?:is|:|=)?\s*[A-Z0-9-]{4,12}\b/iu' => '[verification code removed]',
        '/\b(?:my\s+)?(?:password|passcode|pin)\s*(?:is|:|=)\s*\S+/iu' => '[password removed]',
    ];

    public function containsPrivateInformation(string $text): bool
    {
        foreach (array_keys(self::PRIVATE_PATTERNS) as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    public function redact(string $text, int $limit = 500): string
    {
        $redacted = $text;

        foreach (self::PRIVATE_PATTERNS as $pattern => $replacement) {
            $redacted = preg_replace($pattern, $replacement, $redacted) ?? $redacted;
        }

        $redacted = preg_replace('/\s+/u', ' ', trim($redacted)) ?? trim($redacted);

        return Str::limit($redacted, $limit, '…');
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array<int, array{role: string, content: string}>
     */
    public function redactHistory(array $history): array
    {
        return array_map(fn (array $entry) => [
            'role' => $entry['role'],
            'content' => $this->redact($entry['content'], 1200),
        ], $history);
    }
}
