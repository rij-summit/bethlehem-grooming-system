<?php

namespace Tests\Feature;

use App\Services\ChatbotGroomingEstimateService;
use App\Services\ChatbotKnowledgeService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChatbotAssistantToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-30 10:00:00');
        config()->set('services.groq.key', 'fake-key');
        config()->set('services.groq.model', 'openai/gpt-oss-20b');
        Schema::create('clinic_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('groomers_on_duty')->default(3);
            foreach (['clinic', 'grooming'] as $service) {
                $table->time($service.'_open_time')->default('08:00:00');
                $table->time($service.'_close_time')->default('17:00:00');
                $table->time($service.'_prereg_cutoff_time')->default('14:00:00');
            }
            $table->timestamps();
        });
        DB::table('clinic_settings')->insert(['id' => 1]);
        Schema::create('clinic_closures', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_active')->default(true);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('clinic_closures');
        Schema::dropIfExists('clinic_settings');
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fakeTool(string $name, array $arguments): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => [
            'content' => null,
            'tool_calls' => [['type' => 'function', 'function' => [
                'name' => $name, 'arguments' => json_encode($arguments),
            ]]],
        ]]]])]);
    }

    public static function groomingCases(): array
    {
        return [
            'huskey needs cut' => ['I have a large huskey, how long will it take?', 'large', 'unknown', false, 'short/summer cut'],
            'large puppy cut' => ['Large husky puppy cut, how long?', 'large', 'trim', false, '**around 2 hours**'],
            'giant trim' => ['How long for a giant poodle trim?', 'giant', 'trim', false, '**around 2 to 3 hours**'],
            'kalbo needs size' => ['How long for kalbo?', 'unknown', 'short', false, 'what size'],
            'large kalbo stays short' => ['How long for a large dog summer cut?', 'large', 'short', false, '**around 30 minutes**'],
            'small puppy cut' => ['Small dog puppy cut duration?', 'small', 'trim', false, '**around 1 hour**'],
            'medium puppy cut' => ['Medium dog regular trim duration?', 'medium', 'trim', false, '**around 1 hour and 30 minutes**'],
            'xl trim' => ['Extra large dog puppy cut?', 'extra_large', 'trim', false, '**around 2 hours**'],
            'explicit difficulty' => ['My large dog is heavily matted and needs a trim', 'large', 'trim', true, '**around 2 to 3 hours**'],
            'no invented styled timing' => ['Large dog teddy cut duration?', 'large', 'styled', false, 'no verified timing'],
            'no invented bath timing' => ['How long to bathe my small dog?', 'small', 'other', false, 'no verified timing'],
        ];
    }

    // These test the server's facts and routing contract; the opt-in live suite tests model interpretation.
    #[DataProvider('groomingCases')]
    public function test_model_extraction_uses_verified_grooming_handler(string $question, string $size, string $cut, bool $difficult, string $expected): void
    {
        $this->fakeTool('estimate_grooming_time', compact('size', 'cut', 'difficult') + ['language' => 'english']);
        $reply = $this->postJson('/api/chatbot', ['message' => $question])
            ->assertOk()->assertJsonPath('source', 'grooming_estimate')->json('reply');
        $this->assertStringContainsString($expected, $reply);
        if (! in_array('unknown', [$size, $cut], true)) {
            $this->assertStringContainsString('Queue waiting time', $reply);
            $this->assertStringNotContainsString('2 to 4 hours', $reply);
        }
        if ($size === 'giant' || ($difficult && $size === 'large')) {
            $this->assertStringContainsString('around 4 hours', $reply);
        }
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['model'] === 'openai/gpt-oss-20b'
            && $request['messages'][1]['content'] === $question
            && $request['parallel_tool_calls'] === false);
    }

    public static function visitQuestions(): array
    {
        return array_map(fn ($question) => [$question], [
            'Do you handle appointments?', 'Do you handle walkin?',
            'Do you handle walk in?', 'Do you handle walk-in?', 'Pwede walk in?',
        ]);
    }

    #[DataProvider('visitQuestions')]
    public function test_visit_policy_is_not_generated_by_the_model(string $question): void
    {
        $this->fakeTool('visit_process', ['language' => 'english']);
        $reply = $this->postJson('/api/chatbot', ['message' => $question])
            ->assertOk()->assertJsonPath('source', 'visit_process')->json('reply');
        foreach (['accepts walk-ins', 'do not use appointments', 'capacity', 'does not reserve a queue number', 'staff successfully completes check-in'] as $fact) {
            $this->assertStringContainsString($fact, $reply);
        }
    }

    public function test_short_follow_up_preserves_details_and_redacts_history(): void
    {
        $this->fakeTool('estimate_grooming_time', ['size' => 'large', 'cut' => 'trim', 'difficult' => false, 'language' => 'english']);
        $history = [
            ['role' => 'user', 'content' => 'How long for Puppy Cut? My email is owner@example.com'],
            ['role' => 'assistant', 'content' => 'What size is your pet?'],
        ];
        $this->postJson('/api/chatbot', ['message' => 'Large', 'history' => $history])
            ->assertOk()->assertJsonPath('source', 'grooming_estimate');
        Http::assertSent(fn ($request) => str_contains($request['messages'][1]['content'], 'Puppy Cut')
            && ! str_contains(json_encode($request['messages']), 'owner@example.com')
            && $request['messages'][3]['content'] === 'Large');
    }

    public function test_tomorrow_hours_use_tomorrow_closure_not_todays_status(): void
    {
        DB::table('clinic_closures')->insert(['type' => 'blocked_date', 'start_date' => '2026-10-01', 'end_date' => '2026-10-01']);
        $this->fakeTool('clinic_hours', ['date' => '2026-10-01', 'language' => 'english']);
        foreach (['How about tomorrow?', 'Are you open tomorrow?'] as $question) {
            $this->postJson('/api/chatbot', [
                'message' => $question,
                'history' => [
                    ['role' => 'user', 'content' => 'What time do you open today?'],
                    ['role' => 'assistant', 'content' => '8:00 AM'],
                ],
            ])->assertOk()->assertJsonPath('source', 'live_availability')
                ->assertJsonPath('reply', 'The clinic is **closed on 2026-10-01** according to current closure information.');
        }
    }

    public function test_opening_time_question_is_not_intercepted_as_open_closed_status(): void
    {
        $this->fakeTool('clinic_hours', ['date' => '2026-09-30', 'language' => 'english']);
        $reply = $this->postJson('/api/chatbot', ['message' => 'What time do you open today?'])
            ->assertOk()->assertJsonPath('source', 'live_availability')->json('reply');
        $this->assertStringContainsString('8:00 AM', $reply);
        Http::assertSentCount(1);
    }

    public function test_groomer_count_mention_does_not_override_a_finishing_time_question(): void
    {
        $this->fakeTool('estimate_grooming_time', ['size' => 'large', 'cut' => 'trim', 'difficult' => false, 'language' => 'english']);
        $reply = $this->postJson('/api/chatbot', [
            'message' => 'With 3 groomers on duty, when will my large dog be finished with a puppy cut? I have not checked in.',
        ])->assertOk()->assertJsonPath('source', 'grooming_estimate')->json('reply');
        $this->assertStringContainsString('Queue waiting time', $reply);
        $this->assertStringContainsString('not a guaranteed pickup time', $reply);
        Http::assertSentCount(1);
    }

    public function test_today_stop_does_not_close_tomorrow_and_hours_are_live(): void
    {
        DB::table('clinic_closures')->insert(['type' => 'stop_today', 'start_date' => '2026-09-30', 'end_date' => '2026-09-30']);
        DB::table('clinic_settings')->update(['clinic_open_time' => '09:30:00']);
        $this->fakeTool('clinic_hours', ['date' => '2026-10-01', 'language' => 'english', 'include_location' => true]);
        $reply = $this->postJson('/api/chatbot', ['message' => 'Clinic hours and location tomorrow?'])
            ->assertOk()->json('reply');
        $this->assertStringContainsString('9:30 AM', $reply);
        $this->assertStringContainsString('No closure is currently listed', $reply);
        $this->assertStringContainsString('Ortigas', $reply);
    }

    public function test_customer_navigation_reaches_ai_instead_of_status_interceptor(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'Customer navigation answer.']]]])]);
        foreach ([
            'How do I create an account?', 'I already signed in, how do I add my pet?',
            'Where can I see if my pet is already being groomed?', 'I don\'t know how to pre register',
            'How do I cancel my pre-registration?',
        ] as $question) {
            $this->postJson('/api/chatbot', ['message' => $question])->assertOk()->assertJsonPath('source', 'groq');
        }
        Http::assertSentCount(5);
        Http::assertSent(function ($request) {
            $prompt = $request['messages'][0]['content'];
            foreach (['ALWAYS use customer_guide', 'clinic-verified size'] as $fact) {
                $this->assertStringContainsString($fact, $prompt);
            }

            return true;
        });
    }

    public static function customerGuideCases(): array
    {
        return [
            ['How do I create an account?', 'account', 'Create Account'],
            ['Where do I sign in?', 'signin', 'Sign In'],
            ['I already signed in, how do I add my pet?', 'pet', 'Save Pet'],
            ['Where can I see if my pet is already being groomed?', 'tracker', 'Grooming Tracker'],
            ['I don\'t know how to pre register', 'preregister', 'Clinic Visit'],
            ['Where is my previous grooming?', 'history', 'Grooming History'],
            ['How do I change account information?', 'settings', 'read-only'],
            ['I forgot my password', 'password', 'emailed verification code'],
            ['How do I cancel or reschedule my pre-registration?', 'changes', 'Confirm Reschedule'],
            ['I-cancel booking ko', 'changes', 'Cancel Pre-registration'],
            ['Where do I see notifications?', 'notifications', 'See all notifications'],
        ];
    }

    #[DataProvider('customerGuideCases')]
    public function test_customer_guide_tool_returns_real_navigation_without_ai_embellishment(string $question, string $topic, string $label): void
    {
        $this->fakeTool('customer_guide', ['topic' => $topic, 'language' => 'english']);
        $reply = $this->postJson('/api/chatbot', ['message' => $question])
            ->assertOk()->assertJsonPath('source', 'customer_guide')->json('reply');
        $this->assertStringContainsString($label, $reply);
        if ($topic === 'tracker') {
            $this->assertStringNotContainsString('Quick Actions', $reply);
        }
        Http::assertSentCount(1);
    }

    public function test_taglish_customer_guide_keeps_real_english_labels(): void
    {
        $this->fakeTool('customer_guide', ['topic' => 'preregister', 'language' => 'filipino']);
        $reply = $this->postJson('/api/chatbot', ['message' => 'Paano mag pre register?'])
            ->assertOk()->assertJsonPath('source', 'customer_guide')->json('reply');
        $this->assertStringContainsString('Piliin', $reply);
        $this->assertStringContainsString('Clinic Visit', $reply);
        $this->assertStringContainsString('Submit Registration', $reply);
        $this->assertStringNotContainsString('\\n', $reply);
    }

    public function test_semantic_status_route_requires_authentication(): void
    {
        $this->fakeTool('customer_status', ['user_id' => 999]);
        $reply = $this->postJson('/api/chatbot', ['message' => 'Has the groomer started on mine yet?'])
            ->assertOk()->assertJsonPath('source', 'account_status')->json('reply');
        $this->assertStringContainsString('Sign in', $reply);
        $this->assertStringNotContainsString('999', $reply);
    }

    public function test_invalid_extraction_never_becomes_a_fabricated_estimate(): void
    {
        $this->fakeTool('estimate_grooming_time', ['size' => 'large', 'cut' => 'trim', 'difficult' => false, 'language' => 'english', 'duration' => '17 minutes']);
        $reply = $this->postJson('/api/chatbot', ['message' => 'Large puppy cut, how long?'])->assertOk()->json('reply');
        $this->assertStringContainsString('around 2 hours', $reply);
        $this->assertStringNotContainsString('17 minutes', $reply);
    }

    public function test_invalid_tool_arguments_fall_back_safely(): void
    {
        $this->fakeTool('estimate_grooming_time', ['size' => 'invented', 'cut' => 'trim']);
        $this->postJson('/api/chatbot', ['message' => 'Grooming duration?'])->assertOk()->assertJsonPath('source', 'local_fallback');
    }

    public function test_text_response_with_null_tool_calls_remains_valid(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => [
            'content' => 'Would you like help with grooming prices or grooming time?',
            'tool_calls' => null,
        ]]]])]);
        $this->postJson('/api/chatbot', ['message' => 'I need grooming'])
            ->assertOk()->assertJsonPath('source', 'groq');
    }

    public function test_missing_groq_keeps_walkin_rules_and_does_not_invent_unknown_answers(): void
    {
        config()->set('services.groq.key', null);
        Http::fake();
        $reply = $this->postJson('/api/chatbot', ['message' => 'Do you handle appointments?'])->assertOk()->json('reply');
        $this->assertStringContainsString('do not use appointments', $reply);
        $reply = $this->postJson('/api/chatbot', ['message' => 'Write my school essay about Philippine history.'])->assertOk()->json('reply');
        $this->assertStringNotContainsString('Grooming History', $reply);
        Http::assertNothingSent();
    }

    public function test_difficult_short_cut_has_no_invented_numeric_adjustment(): void
    {
        $reply = app(ChatbotGroomingEstimateService::class)->answer([
            'size' => 'giant', 'cut' => 'short', 'difficult' => true, 'language' => 'english',
        ]);
        $this->assertStringContainsString('straightforward', $reply);
        $this->assertStringContainsString('may take longer', $reply);
        $this->assertStringNotContainsString('4 hours', $reply);
    }

    public function test_verified_guides_match_actual_customer_controls(): void
    {
        $guides = app(ChatbotKnowledgeService::class)->customerGuides();
        foreach ([
            'signup.html' => ['Sign Up', 'Create Account'],
            'pre-register.html' => ['Grooming', 'Clinic Visit'],
            'grooming-pre-registration.html' => ['Submit Registration'],
            'clinic-visit-summary.html' => ['Submit Pre-registration'],
            'dashboard.html' => ['Grooming Tracker', 'Confirm Reschedule', 'Cancel Pre-registration'],
        ] as $file => $labels) {
            foreach ($labels as $label) {
                $this->assertStringContainsString($label, file_get_contents(base_path('pages/client/'.$file)));
                $this->assertStringContainsString($label, implode(' ', $guides));
            }
        }
        $this->assertStringContainsString('read-only', $guides['settings']);
    }
}
