<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ChatbotControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-16 09:00:00'));

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

        DB::table('clinic_settings')->insert([
            'id' => 1,
            'groomers_on_duty' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::create('clinic_closures', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('reason')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('clinic_closures');
        Schema::dropIfExists('clinic_settings');

        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_off_topic_questions_return_a_short_clinic_only_reply_without_calling_groq(): void
    {
        Http::fake();

        $response = $this->postJson('/api/chatbot', [
            'message' => 'Write a JavaScript function for sorting numbers.',
        ]);

        $response
            ->assertOk()
            ->assertJson([
                'reply' => 'I can only answer questions related to Bethlehem Animal Clinic.',
            ]);

        Http::assertNothingSent();
    }

    public function test_identity_questions_return_only_the_virtual_assistant_identity_without_calling_groq(): void
    {
        Http::fake();

        $response = $this->postJson('/api/chatbot', [
            'message' => 'What is your name and are you male or female?',
        ]);

        $response
            ->assertOk()
            ->assertJson([
                'reply' => 'I am the virtual assistant of Bethlehem Animal Clinic.',
            ]);

        Http::assertNothingSent();
    }

    public function test_current_clinic_status_questions_use_operating_hours_when_no_special_closure_exists(): void
    {
        Http::fake();

        $response = $this->postJson('/api/chatbot', [
            'message' => 'Is the clinic open today?',
        ]);

        $response
            ->assertOk()
            ->assertJson([
                'reply' => 'Bethlehem Animal Clinic is currently **open**.',
            ]);

        Http::assertNothingSent();
    }

    public function test_current_clinic_status_questions_report_closed_outside_operating_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-16 17:00:00'));

        Http::fake();

        $response = $this->postJson('/api/chatbot', [
            'message' => 'Is the clinic open today?',
        ]);

        $response
            ->assertOk()
            ->assertJson([
                'reply' => 'Bethlehem Animal Clinic is currently **closed**; normal operating hours are **8:00 AM – 5:00 PM** daily.',
            ]);

        Http::assertNothingSent();
    }

    public function test_current_clinic_status_uses_configured_operating_hours(): void
    {
        DB::table('clinic_settings')->where('id', 1)->update([
            'clinic_open_time' => '10:00:00',
            'clinic_close_time' => '12:30:00',
        ]);

        Http::fake();

        $this->postJson('/api/chatbot', [
            'message' => 'Is the clinic open today?',
        ])
            ->assertOk()
            ->assertJson([
                'reply' => 'Bethlehem Animal Clinic is currently **closed**; normal operating hours are **10:00 AM – 12:30 PM** daily.',
            ]);

        Carbon::setTestNow(Carbon::parse('2026-07-16 10:00:00'));

        $this->postJson('/api/chatbot', [
            'message' => 'Is the clinic open today?',
        ])
            ->assertOk()
            ->assertJson([
                'reply' => 'Bethlehem Animal Clinic is currently **open**.',
            ]);

        Http::assertNothingSent();
    }

    public function test_current_clinic_status_questions_use_staff_closed_today_without_calling_groq(): void
    {
        DB::table('clinic_closures')->insert([
            'type' => 'stop_today',
            'start_date' => '2026-07-16',
            'end_date' => '2026-07-16',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake();

        $response = $this->postJson('/api/chatbot', [
            'message' => 'Is the clinic open right now?',
        ]);

        $response
            ->assertOk()
            ->assertJson([
                'reply' => 'Bethlehem Animal Clinic is currently **closed** for today.',
            ]);

        Http::assertNothingSent();
    }

    public function test_current_clinic_status_questions_use_blocked_date_without_calling_groq(): void
    {
        DB::table('clinic_closures')->insert([
            'type' => 'blocked_date',
            'start_date' => '2026-07-15',
            'end_date' => '2026-07-17',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake();

        $response = $this->postJson('/api/chatbot', [
            'message' => 'Is the clinic open today?',
        ]);

        $response
            ->assertOk()
            ->assertJson([
                'reply' => 'Bethlehem Animal Clinic is **closed** today.',
            ]);

        Http::assertNothingSent();
    }

    public function test_groomer_count_questions_use_the_existing_groomers_on_duty_setting_without_calling_groq(): void
    {
        DB::table('clinic_settings')->where('id', 1)->update([
            'groomers_on_duty' => 4,
            'updated_at' => now(),
        ]);

        Http::fake();

        $response = $this->postJson('/api/chatbot', [
            'message' => 'How many groomers are present today?',
        ]);

        $response
            ->assertOk()
            ->assertJson([
                'reply' => 'There are **4 groomers** currently on duty.',
            ]);

        Http::assertNothingSent();
    }

    public function test_grooming_price_questions_are_treated_as_clinic_related_without_calling_groq(): void
    {
        Http::fake();

        $response = $this->postJson('/api/chatbot', [
            'message' => 'What are the general price for grooming my deutchhund?',
        ]);

        $response
            ->assertOk()
            ->assertJson([
                'reply' => 'Verified grooming **prices are unavailable** here; please **contact us directly** for current prices.',
            ]);

        Http::assertNothingSent();
    }

    public function test_ai_generated_replies_keep_only_allowed_limited_bold_formatting(): void
    {
        config()->set('services.groq.key', 'fake-groq-key');
        config()->set('services.groq.model', 'fake-groq-model');

        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => '**Bethlehem Animal Clinic** is **open** from **8:00 AM to 5:00 PM**. **Please** ask staff for exact service details.',
                        ],
                    ],
                ],
            ]),
        ]);

        $response = $this->postJson('/api/chatbot', [
            'message' => 'What grooming services do you offer for dogs?',
        ]);

        $response
            ->assertOk()
            ->assertJson([
                'reply' => 'Bethlehem Animal Clinic is **open** from **8:00 AM to 5:00 PM**. Please ask staff for exact service details.',
            ]);
    }

    public function test_clinic_related_questions_send_the_stricter_chatbot_prompt_to_groq(): void
    {
        config()->set('services.groq.key', 'fake-groq-key');
        config()->set('services.groq.model', 'fake-groq-model');

        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Please contact Bethlehem Animal Clinic directly for verified grooming prices.',
                        ],
                    ],
                ],
            ]),
        ]);

        $response = $this->postJson('/api/chatbot', [
            'message' => 'What grooming services do you offer for dogs?',
        ]);

        $response
            ->assertOk()
            ->assertJson([
                'reply' => 'Please contact Bethlehem Animal Clinic directly for verified grooming prices.',
            ]);

        Http::assertSent(function ($request) {
            $payload = $request->data();
            $systemPrompt = $payload['messages'][0]['content'] ?? '';

            return ($payload['model'] ?? null) === 'fake-groq-model'
                && str_contains(
                    $systemPrompt,
                    'Identify yourself only as the virtual assistant of Bethlehem Animal Clinic.'
                )
                && str_contains(
                    $systemPrompt,
                    'Do not claim to have a personal name, human identity, gender, personality identity'
                )
                && str_contains(
                    $systemPrompt,
                    'For any unrelated request, reply with exactly one short sentence: "I can only answer questions related to Bethlehem Animal Clinic."'
                )
                && str_contains(
                    $systemPrompt,
                    'Current clinic status: open.'
                )
                && str_contains(
                    $systemPrompt,
                    'Normal operating hours: 8:00 AM – 5:00 PM daily.'
                )
                && str_contains(
                    $systemPrompt,
                    'Groomers currently present/on duty: 2.'
                )
                && str_contains(
                    $systemPrompt,
                    'Do not claim access to bookings, customers, pets, queues, payments, inventory, staff records, or other live system information.'
                )
                && str_contains(
                    $systemPrompt,
                    'Limit bold formatting to 1 or 2 short phrases per message.'
                )
                && str_contains(
                    $systemPrompt,
                    'Do not bold Bethlehem Animal Clinic, the clinic name, words copied from the customer question'
                )
                && str_contains(
                    $systemPrompt,
                    'Questions about grooming prices, grooming costs, pet grooming rates, or service fees are clinic-related'
                );
        });
    }
}
