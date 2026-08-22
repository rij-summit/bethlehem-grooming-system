<?php

namespace App\Http\Controllers;

use App\Models\ClinicClosure;
use App\Models\ClinicSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChatbotController extends Controller
{
    private const IDENTITY_REPLY = 'I am the virtual assistant of Bethlehem Animal Clinic.';

    private const OFF_TOPIC_REPLY = 'I can only answer questions related to Bethlehem Animal Clinic.';

    private const CLINIC_RELATED_TERMS = [
        'bethlehem',
        'animal clinic',
        'clinic',
        'groom',
        'grooming',
        'groomer',
        'bath',
        'haircut',
        'nail',
        'ear cleaning',
        'tooth brushing',
        'service',
        'schedule',
        'timeslot',
        'appointment',
        'booking',
        'pre-register',
        'pre register',
        'registration',
        'queue',
        'check in',
        'check-in',
        'drop off',
        'drop-off',
        'pickup',
        'pick up',
        'pet',
        'pets',
        'dog',
        'dogs',
        'cat',
        'cats',
        'puppy',
        'kitten',
        'animal',
        'vet',
        'veterinarian',
        'consultation',
        'emergency',
        'health',
        'sick',
        'injury',
        'vaccine',
        'vaccination',
        'rabies',
        'deworm',
        'contact',
        'phone',
        'address',
        'location',
        'directions',
        'price',
        'cost',
        'fee',
        'charge',
        'payment',
        'gcash',
        'cash',
        'card',
        'walk-in',
        'walk in',
        'visit',
        'parking',
    ];

    private const CLINIC_RELATED_PATTERNS = [
        '/\bwhere\s+(?:are|is)\s+(?:you|the\s+clinic|bethlehem)\b/i',
        '/\b(?:are|is)\s+(?:you|the\s+clinic|bethlehem)\s+(?:open|closed)\b/i',
        '/\b(?:what|when)\s+(?:time|hours?)\b/i',
        '/\b(?:open|opens|opening|close|closes|closing|closed)\b/i',
        '/\b(?:book|reserve|cancel|reschedule)\b/i',
    ];

    private const CLINIC_OPEN_STATUS_PATTERNS = [
        '/\b(?:are|is)\s+(?:you|the\s+clinic|bethlehem)\s+(?:open|closed)\b/i',
        '/\b(?:is\s+)?(?:bethlehem|the\s+clinic|clinic)\s+(?:still\s+)?(?:receiving|accepting)\b/i',
        '/\b(?:open|closed|reopen|reopened|stopped|stop\s+receiving|not\s+receiving)\b/i',
    ];

    private const GROOMERS_ON_DUTY_PATTERNS = [
        '/\b(?:how\s+many|number\s+of|count\s+of)\s+groomers?\b/i',
        '/\bgroomers?\s+(?:on\s+duty|present|available|working|there|in\s+today)\b/i',
        '/\b(?:available|present|current)\s+groomers?\b/i',
    ];

    private const GROOMING_PRICE_PATTERNS = [
        '/\b(?:price|prices|pricing|cost|costs|fee|fees|charge|charges|rate|rates)\b.*\b(?:groom|grooming|bath|haircut|nail|pet|dog|cat|puppy|kitten)\b/i',
        '/\b(?:groom|grooming|bath|haircut|nail|pet|dog|cat|puppy|kitten)\b.*\b(?:price|prices|pricing|cost|costs|fee|fees|charge|charges|rate|rates)\b/i',
    ];

    public function chat(Request $request): JsonResponse
    {
        /*
         * Validate the message received from the frontend.
         */
        $validated = $request->validate([
            'message' => [
                'required',
                'string',
                'max:1000',
            ],
        ]);

        $message = $validated['message'];

        if ($this->asksAboutAssistantIdentity($message)) {
            return response()->json([
                'reply' => self::IDENTITY_REPLY,
            ]);
        }

        if (! $this->isClinicRelatedMessage($message)) {
            return response()->json([
                'reply' => self::OFF_TOPIC_REPLY,
            ]);
        }

        if ($this->asksAboutGroomingPrice($message)) {
            return response()->json([
                'reply' => 'Verified grooming **prices are unavailable** here; please **contact us directly** for current prices.',
            ]);
        }

        $allowedSystemContext = $this->getAllowedSystemContext();

        if ($this->asksAboutAllowedSystemContext($message)) {
            return response()->json([
                'reply' => $this->answerAllowedSystemContextQuestion(
                    $message,
                    $allowedSystemContext
                ),
            ]);
        }

        /*
         * Retrieve the Groq configuration.
         */
        $apiKey = config('services.groq.key');
        $model = config('services.groq.model');
        $groqBaseUrl = rtrim(
            (string) config('services.groq.base_url'),
            '/'
        );

        /*
         * Stop the request if the API key is missing.
         */
        if (blank($apiKey)) {
            return response()->json([
                'message' => 'The AI service is not configured.',
            ], 500);
        }

        try {
            /*
             * Send the customer's message to Groq.
             */
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(30)
                ->post(
                    $groqBaseUrl . '/chat/completions',
                    [
                        'model' => $model,

                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => implode("\n", [
                                    'You are the virtual assistant of Bethlehem Animal Clinic.',
                                    'Identify yourself only as the virtual assistant of Bethlehem Animal Clinic.',
                                    'Do not claim to have a personal name, human identity, gender, personality identity, feelings, preferences, or personal life.',
                                    'If asked about your name, identity, gender, personality, or whether you are human, answer only: "I am the virtual assistant of Bethlehem Animal Clinic."',

                                    'Your purpose is to answer general questions about Bethlehem Animal Clinic, grooming services, schedules, pets, and other clinic-related concerns.',

                                    'You may answer questions about:',
                                    '- General grooming services.',
                                    '- Online pet pre-registration.',
                                    '- The queue-based grooming process.',
                                    '- Pet drop-off and arrival preparation.',
                                    '- General clinic hours and contact procedures.',
                                    '- What customers should expect during grooming.',

                                    'Allowed current system information:',
                                    '- Current clinic status: ' . $allowedSystemContext['clinic_status'] . '.',
                                    '- Normal operating hours: ' . $allowedSystemContext['clinic_operating_hours'] . ' daily.',
                                    '- Groomers currently present/on duty: ' . $allowedSystemContext['groomers_on_duty'] . '.',
                                    'Use only the allowed current system information listed above when answering questions about live clinic status or groomer count.',
                                    'Do not claim access to bookings, customers, pets, queues, payments, inventory, staff records, or other live system information.',
                                    'Bold-text rules:',
                                    '- Use double asterisks only as a spotlight for the most important information.',
                                    '- Limit bold formatting to 1 or 2 short phrases per message.',
                                    '- Bold direct answers such as **open**, **closed**, **yes**, **no**, **available**, or **unavailable**.',
                                    '- Bold important actions as a complete action phrase, such as **contact us directly** or **bring your pet to the clinic**.',
                                    '- Bold important numbers or details only when they directly answer the question, such as configured operating hours, **24-hour notice**, a price, a date, or a queue position.',
                                    '- Do not bold Bethlehem Animal Clinic, the clinic name, words copied from the customer question, or filler words such as please, here, and directly by themselves.',
                                    'Questions about grooming prices, grooming costs, pet grooming rates, or service fees are clinic-related even if the customer does not mention Bethlehem Animal Clinic by name.',
                                    'If verified prices are unavailable, do not refuse as off-topic; say that verified prices are unavailable here and ask the customer to contact Bethlehem Animal Clinic directly.',

                                    'When you give numbered or step-by-step instructions, put each numbered step on its own line.',
                                    'Use this numbered format:',
                                    '1. First step',
                                    '2. Second step',
                                    '3. Third step',
                                    'Never place multiple numbered steps in one paragraph.',

                                    'You must follow these safety rules:',

                                    '- Do not diagnose a pet.',
                                    '- Do not interpret symptoms as a medical diagnosis.',
                                    '- Do not prescribe or recommend medicine.',
                                    '- Do not provide medication dosages.',
                                    '- Do not provide treatment instructions for illnesses or injuries.',
                                    '- Do not claim that a pet is healthy or safe based only on a message.',
                                    '- Do not handle medical emergencies as an AI chatbot.',

                                    'When a customer describes an urgent health concern or emergency, explain that you cannot assess the pet and advise them to contact a veterinarian or emergency clinic immediately.',

                                    'For online pet pre-registration, clearly explain that it submits the pet information in advance only.',
                                    'Online pre-registration does not guarantee a queue position or grooming start time.',
                                    'The pet becomes officially queued only after the customer arrives and checks in at the clinic.',
                                    'Do not say that the clinic will contact the customer to confirm registration or schedule a grooming appointment because of online pre-registration unless the actual system provides that information.',

                                    'Do not claim that a grooming pre-registration, queue position, payment, or schedule has been confirmed unless that information is provided by the actual system.',

                                    'Do not invent clinic prices, schedules, policies, availability, contact details, or services.',

                                    'When verified information is unavailable, politely tell the customer to contact Bethlehem Animal Clinic directly.',

                                    'When a response contains more than one idea, separate the ideas with a blank line.',

                                    'For any unrelated request, reply with exactly one short sentence: "I can only answer questions related to Bethlehem Animal Clinic."',
                                    'Do not add explanations, suggestions, or extra paragraphs to off-topic refusals.',

                                    'Keep responses polite, concise, simple, and easy for customers to understand.',
                                ]),
                            ],
                            [
                                'role' => 'user',
                                'content' => $validated['message'],
                            ],
                        ],

                        'temperature' => 0.2,

                        'max_completion_tokens' => (int) config(
                            'services.groq.max_completion_tokens',
                            1024
                        ),

                        ...(
                            str_starts_with(
                                (string) $model,
                                'openai/gpt-oss-'
                            )
                                ? [
                                    'reasoning_effort' => config(
                                        'services.groq.reasoning_effort',
                                        'low'
                                    ),
                                    'include_reasoning' => (bool) config(
                                        'services.groq.include_reasoning',
                                        false
                                    ),
                                ]
                                : []
                        ),
                    ]
                );

            /*
             * Handle unsuccessful responses from Groq.
             */
            if ($response->failed()) {
                Log::warning('Groq request failed.', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);

                if ($response->status() === 429) {
                    return response()->json([
                        'message' => implode(' ', [
                            'The AI service is receiving too many',
                            'requests. Please try again later.',
                        ]),
                    ], 429);
                }

                return response()->json([
                    'message' => implode(' ', [
                        'The AI service could not process',
                        'your request.',
                    ]),
                ], 502);
            }

            /*
             * Retrieve the assistant's answer.
             */
            $reply = $response->json(
                'choices.0.message.content'
            );

            /*
             * Make sure Groq returned a valid answer.
             */
            if (!is_string($reply) || blank($reply)) {
                Log::warning('Groq returned an empty response.', [
                    'model' => $response->json('model'),
                    'finish_reason' => $response->json(
                        'choices.0.finish_reason'
                    ),
                    'prompt_tokens' => $response->json(
                        'usage.prompt_tokens'
                    ),
                    'completion_tokens' => $response->json(
                        'usage.completion_tokens'
                    ),
                    'total_tokens' => $response->json(
                        'usage.total_tokens'
                    ),
                    'has_reasoning' => filled(
                        $response->json(
                            'choices.0.message.reasoning'
                        )
                    ),
                ]);

                return response()->json([
                    'message' => implode(' ', [
                        'The AI service returned an',
                        'invalid response.',
                    ]),
                ], 502);
            }

            /*
             * Return the answer to the frontend.
             */
            return response()->json([
                'reply' => $this->normalizeBoldFormatting(trim($reply)),
            ]);
        } catch (ConnectionException $exception) {
            /*
             * This usually happens when Laravel cannot
             * connect to Groq.
             */
            Log::error('Could not connect to Groq.', [
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => implode(' ', [
                    'The server could not connect to',
                    'the AI service. Please try again.',
                ]),
            ], 504);
        } catch (Throwable $exception) {
            /*
             * Handle other unexpected errors.
             */
            Log::error('Unexpected chatbot error.', [
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => implode(' ', [
                    'An unexpected error occurred.',
                    'Please try again later.',
                ]),
            ], 500);
        }
    }

    private function asksAboutAssistantIdentity(string $message): bool
    {
        return $this->matchesAny($message, [
            '/\b(?:who|what)\s+are\s+you\b/i',
            '/\bwhat(?:\'s|\s+is)\s+your\s+name\b/i',
            '/\bwhat\s+should\s+i\s+call\s+you\b/i',
            '/\bcan\s+i\s+call\s+you\b/i',
            '/\bdo\s+you\s+have\s+(?:a\s+)?(?:name|gender|personality|identity)\b/i',
            '/\bare\s+you\s+(?:a\s+)?(?:human|person|man|woman|male|female|boy|girl|real|bot|ai|chatbot)\b/i',
            '/\byour\s+(?:gender|sex|pronouns?|personality|identity)\b/i',
        ]);
    }

    /**
     * @return array{
     *     clinic_status: string,
     *     clinic_is_open: bool,
     *     clinic_status_reason: string,
     *     clinic_operating_hours: string,
     *     groomers_on_duty: int
     * }
     */
    private function getAllowedSystemContext(): array
    {
        $now = now();
        $settings = ClinicSetting::current();
        $isStaffClosedToday = $this->isStaffClosedToday();
        $isBlockedToday = $this->isBlockedToday();
        $isWithinOperatingHours = $settings->isWithinOperatingHours('clinic', $now);

        $clinicStatusReason = 'within_operating_hours';

        if (! $isWithinOperatingHours) {
            $clinicStatusReason = 'outside_operating_hours';
        }

        if ($isStaffClosedToday) {
            $clinicStatusReason = 'staff_closed_today';
        }

        if ($isBlockedToday) {
            $clinicStatusReason = 'blocked_date';
        }

        $isOpen = $isWithinOperatingHours && ! $isStaffClosedToday && ! $isBlockedToday;

        return [
            'clinic_status' => $isOpen ? 'open' : 'closed',
            'clinic_is_open' => $isOpen,
            'clinic_status_reason' => $clinicStatusReason,
            'clinic_operating_hours' => $settings->serviceAvailability('clinic')['operating_hours_label'],
            'groomers_on_duty' => (int) (
                $settings->groomers_on_duty
                ?? ClinicSetting::DEFAULT_GROOMERS_ON_DUTY
            ),
        ];
    }

    private function isStaffClosedToday(): bool
    {
        $today = now()->toDateString();

        return ClinicClosure::query()
            ->where('is_active', 1)
            ->where('type', 'stop_today')
            ->whereDate('start_date', $today)
            ->exists();
    }

    private function isBlockedToday(): bool
    {
        $today = now()->toDateString();

        return ClinicClosure::query()
            ->where('is_active', 1)
            ->where('type', 'blocked_date')
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->exists();
    }

    private function asksAboutAllowedSystemContext(string $message): bool
    {
        return $this->asksAboutClinicOpenStatus($message)
            || $this->asksAboutGroomersOnDuty($message);
    }

    /**
     * @param array{
     *     clinic_status: string,
     *     clinic_is_open: bool,
     *     clinic_status_reason: string,
     *     clinic_operating_hours: string,
     *     groomers_on_duty: int
     * } $allowedSystemContext
     */
    private function answerAllowedSystemContextQuestion(
        string $message,
        array $allowedSystemContext
    ): string {
        $answers = [];

        if ($this->asksAboutClinicOpenStatus($message)) {
            $answers[] = $allowedSystemContext['clinic_is_open']
                ? 'Bethlehem Animal Clinic is currently **open**.'
                : $this->clinicClosedReply(
                    $allowedSystemContext['clinic_status_reason'],
                    $allowedSystemContext['clinic_operating_hours'],
                );
        }

        if ($this->asksAboutGroomersOnDuty($message)) {
            $groomersOnDuty = $allowedSystemContext['groomers_on_duty'];
            $answers[] = $groomersOnDuty === 1
                ? 'There is **1 groomer** currently on duty.'
                : "There are **{$groomersOnDuty} groomers** currently on duty.";
        }

        return implode(' ', $answers);
    }

    private function clinicClosedReply(string $reason, string $operatingHours): string
    {
        return match ($reason) {
            'staff_closed_today' => 'Bethlehem Animal Clinic is currently **closed** for today.',
            'blocked_date' => 'Bethlehem Animal Clinic is **closed** today.',
            default => 'Bethlehem Animal Clinic is currently **closed**; normal operating hours are **' . $operatingHours . '** daily.',
        };
    }

    private function asksAboutClinicOpenStatus(string $message): bool
    {
        return $this->matchesAny($message, self::CLINIC_OPEN_STATUS_PATTERNS);
    }

    private function asksAboutGroomersOnDuty(string $message): bool
    {
        return $this->matchesAny($message, self::GROOMERS_ON_DUTY_PATTERNS);
    }

    private function asksAboutGroomingPrice(string $message): bool
    {
        return $this->matchesAny($message, self::GROOMING_PRICE_PATTERNS);
    }

    private function normalizeBoldFormatting(string $reply): string
    {
        $boldPhraseCount = 0;

        return preg_replace_callback(
            '/\*\*([^*]+)\*\*/',
            function (array $matches) use (&$boldPhraseCount): string {
                $phrase = $matches[1];

                if ($this->shouldRemoveBoldFormatting($phrase) || $boldPhraseCount >= 2) {
                    return $phrase;
                }

                $boldPhraseCount++;

                return "**{$phrase}**";
            },
            $reply
        ) ?? $reply;
    }

    private function shouldRemoveBoldFormatting(string $phrase): bool
    {
        $normalizedPhrase = strtolower(
            preg_replace('/\s+/', ' ', trim($phrase)) ?? ''
        );

        return in_array($normalizedPhrase, [
            'bethlehem',
            'bethlehem animal clinic',
            'animal clinic',
            'clinic',
            'the clinic',
            'please',
            'here',
            'directly',
        ], true);
    }

    private function isClinicRelatedMessage(string $message): bool
    {
        $normalizedMessage = strtolower($message);

        foreach (self::CLINIC_RELATED_TERMS as $term) {
            if (str_contains($normalizedMessage, $term)) {
                return true;
            }
        }

        return $this->matchesAny($message, self::CLINIC_RELATED_PATTERNS);
    }

    /**
     * @param array<int, string> $patterns
     */
    private function matchesAny(string $message, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message) === 1) {
                return true;
            }
        }

        return false;
    }
}
