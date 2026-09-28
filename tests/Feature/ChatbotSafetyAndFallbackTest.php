<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ChatbotSafetyAndFallbackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('clinic_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('groomers_on_duty')->default(2);
            $table->time('clinic_open_time')->default('08:00:00');
            $table->time('clinic_close_time')->default('17:00:00');
            $table->time('clinic_prereg_cutoff_time')->default('14:00:00');
            $table->time('grooming_open_time')->default('08:00:00');
            $table->time('grooming_close_time')->default('17:00:00');
            $table->time('grooming_prereg_cutoff_time')->default('14:00:00');
            $table->timestamps();
        });
        DB::table('clinic_settings')->insert(['id' => 1]);

        Schema::create('clinic_closures', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('clinic_closures');
        Schema::dropIfExists('clinic_settings');

        parent::tearDown();
    }

    public function test_active_emergencies_bypass_groq_and_informational_questions_do_not(): void
    {
        config()->set('services.groq.key', 'fake-groq-key');
        config()->set('services.groq.model', 'fake-groq-model');
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Emergency warning signs include breathing trouble.']]],
            ]),
        ]);

        $emergency = $this->postJson('/api/chatbot', [
            'message' => 'My dog cannot breathe and is unresponsive.',
        ])->assertOk()->assertJsonPath('source', 'emergency');

        $this->assertStringContainsString('nearest emergency veterinary clinic', $emergency->json('reply'));

        $this->postJson('/api/chatbot', [
            'message' => 'What are the emergency warning signs for pets?',
        ])->assertOk()->assertJsonPath('source', 'groq');

        Http::assertSentCount(1);
    }

    public function test_private_information_is_blocked_and_never_sent_to_groq(): void
    {
        Http::fake();

        $response = $this->postJson('/api/chatbot', [
            'message' => 'My password is Secret123 and my email is owner@example.com for my clinic booking.',
        ])->assertOk()->assertJsonPath('source', 'privacy_guard');

        $this->assertStringNotContainsString('Secret123', $response->json('reply'));
        Http::assertNothingSent();
    }

    public function test_ambiguous_questions_request_clarification_without_using_groq(): void
    {
        Http::fake();

        $this->postJson('/api/chatbot', [
            'message' => 'How much?',
        ])
            ->assertOk()
            ->assertJsonPath('source', 'clarification')
            ->assertJsonFragment([
                'reply' => "Do you mean **grooming prices** or a clinic service?\n\nFor grooming, tell me whether your pet is a dog or cat and include its size or weight.",
            ]);

        Http::assertNothingSent();
    }

    public function test_groq_failure_returns_a_useful_local_answer(): void
    {
        config()->set('services.groq.key', 'fake-groq-key');
        config()->set('services.groq.model', 'fake-groq-model');
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([], 503),
        ]);

        $response = $this->postJson('/api/chatbot', [
            'message' => 'Where is your clinic located?',
        ])->assertOk()->assertJsonPath('source', 'local_fallback');

        $this->assertStringContainsString('maps.app.goo.gl', $response->json('reply'));
    }

    public function test_chatbot_endpoint_is_rate_limited(): void
    {
        Http::fake();

        for ($attempt = 1; $attempt <= 15; $attempt++) {
            $this->postJson('/api/chatbot', [
                'message' => 'Who are you?',
            ])->assertOk();
        }

        $this->postJson('/api/chatbot', [
            'message' => 'Who are you?',
        ])->assertTooManyRequests();
    }
}
