<?php

namespace App\Http\Controllers;

use App\Models\ChatbotFeedback;
use App\Models\ChatbotInsight;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class AdminChatbotInsightController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(['all', ...ChatbotInsight::STATUSES])],
            'reason' => ['sometimes', 'string', 'max:50'],
            'search' => ['sometimes', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $query = ChatbotInsight::query()->orderByDesc('last_seen_at');
        $status = $filters['status'] ?? 'all';

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if (filled($filters['reason'] ?? null)) {
            $query->where('failure_reason', $filters['reason']);
        }

        if (filled($filters['search'] ?? null)) {
            $search = $filters['search'];
            $query->where('question_excerpt', 'like', "%{$search}%");
        }

        $paginator = $query->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'success' => true,
            'summary' => $this->summary(),
            'reason_options' => ChatbotInsight::query()
                ->distinct()
                ->orderBy('failure_reason')
                ->pluck('failure_reason')
                ->values(),
            'insights' => collect($paginator->items())
                ->map(fn (ChatbotInsight $insight) => $this->formatInsight($insight))
                ->values(),
            'recent_unhelpful' => $this->recentUnhelpfulFeedback(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function updateStatus(
        Request $request,
        ChatbotInsight $chatbotInsight,
    ): JsonResponse {
        $data = $request->validate([
            'status' => ['required', Rule::in(ChatbotInsight::STATUSES)],
        ]);
        $status = $data['status'];

        $chatbotInsight->update([
            'status' => $status,
            'reviewed_by_user_id' => $request->user()->user_id,
            'resolved_at' => $status === 'resolved' ? now() : null,
        ]);

        return response()->json([
            'success' => true,
            'insight' => $this->formatInsight($chatbotInsight->fresh()),
        ]);
    }

    private function summary(): array
    {
        $helpful = 0;
        $unhelpful = 0;

        if (Schema::hasTable('chatbot_feedback')) {
            $helpful = ChatbotFeedback::query()->where('helpful', true)->count();
            $unhelpful = ChatbotFeedback::query()->where('helpful', false)->count();
        }

        $feedbackTotal = $helpful + $unhelpful;

        return [
            'new_questions' => ChatbotInsight::query()->where('status', 'new')->count(),
            'total_occurrences' => (int) ChatbotInsight::query()->sum('occurrence_count'),
            'groq_fallbacks' => (int) ChatbotInsight::query()
                ->whereIn('failure_reason', [
                    'groq_unavailable',
                    'groq_rate_limited',
                    'groq_invalid_response',
                    'groq_not_configured',
                ])
                ->sum('occurrence_count'),
            'helpful_feedback' => $helpful,
            'unhelpful_feedback' => $unhelpful,
            'helpful_percentage' => $feedbackTotal > 0
                ? round(($helpful / $feedbackTotal) * 100, 1)
                : null,
        ];
    }

    private function recentUnhelpfulFeedback(): array
    {
        if (! Schema::hasTable('chatbot_feedback')) {
            return [];
        }

        return ChatbotFeedback::query()
            ->where('helpful', false)
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn (ChatbotFeedback $feedback) => [
                'id' => $feedback->id,
                'question_excerpt' => $feedback->question_excerpt,
                'answer_excerpt' => $feedback->answer_excerpt,
                'answer_source' => $feedback->answer_source,
                'created_at' => $feedback->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function formatInsight(ChatbotInsight $insight): array
    {
        return [
            'id' => $insight->id,
            'question_excerpt' => $insight->question_excerpt,
            'language' => $insight->language,
            'failure_reason' => $insight->failure_reason,
            'occurrence_count' => $insight->occurrence_count,
            'status' => $insight->status,
            'first_seen_at' => $insight->first_seen_at?->toIso8601String(),
            'last_seen_at' => $insight->last_seen_at?->toIso8601String(),
            'resolved_at' => $insight->resolved_at?->toIso8601String(),
        ];
    }
}
