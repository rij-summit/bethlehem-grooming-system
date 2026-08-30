<?php

namespace App\Services;

use App\Models\ChatbotInsight;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ChatbotInsightService
{
    public function __construct(
        private readonly ChatbotPrivacyService $privacy,
    ) {}

    public function record(string $question, string $reason): void
    {
        if (! Schema::hasTable('chatbot_insights')) {
            return;
        }

        $redacted = $this->privacy->redact($question);
        $normalized = mb_strtolower($redacted);
        $fingerprint = hash('sha256', $reason.'|'.$normalized);

        DB::transaction(function () use ($fingerprint, $question, $redacted, $reason) {
            $insight = ChatbotInsight::query()
                ->where('question_fingerprint', $fingerprint)
                ->lockForUpdate()
                ->first();

            if ($insight) {
                $insight->update([
                    'occurrence_count' => $insight->occurrence_count + 1,
                    'last_seen_at' => now(),
                    'status' => $insight->status === 'resolved'
                        ? 'new'
                        : $insight->status,
                    'resolved_at' => null,
                ]);

                return;
            }

            ChatbotInsight::create([
                'question_fingerprint' => $fingerprint,
                'question_excerpt' => $redacted,
                'language' => $this->detectLanguage($question),
                'failure_reason' => $reason,
                'occurrence_count' => 1,
                'status' => 'new',
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
        });
    }

    private function detectLanguage(string $question): string
    {
        $hasFilipino = preg_match(
            '/\b(?:ano|paano|saan|kailan|bakit|magkano|ba|po|kayo|aso|pusa|alaga|bukas|sarado|oras|pila|gupit|paligo|sakit)\b/iu',
            $question
        ) === 1;
        $hasEnglish = preg_match(
            '/\b(?:what|how|where|when|why|is|are|the|my|clinic|booking|price|hours)\b/iu',
            $question
        ) === 1;

        return match (true) {
            $hasFilipino && $hasEnglish => 'taglish',
            $hasFilipino => 'tagalog',
            $hasEnglish => 'english',
            default => 'unknown',
        };
    }
}
