<?php

namespace Tests\Feature;

use App\Models\ClinicSetting;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatbotAuthenticatedStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->string('phone')->unique();
            $table->string('password_hash');
            $table->string('role')->default('customer');
            $table->boolean('is_active')->default(true);
        });
        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id');
            $table->unsignedInteger('user_id');
            $table->string('pet_name');
        });
        Schema::create('time_windows', function (Blueprint $table) {
            $table->increments('window_id');
            $table->string('window_label');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
        });
        Schema::create('bookings', function (Blueprint $table) {
            $table->increments('booking_id');
            $table->string('booking_reference')->unique();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('window_id')->nullable();
            $table->date('booking_date');
            $table->string('status');
        });
        Schema::create('booking_pets', function (Blueprint $table) {
            $table->increments('booking_pet_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('pet_id');
            $table->string('grooming_state')->nullable();
            $table->timestamp('grooming_start_time')->nullable();
            $table->timestamp('grooming_end_time')->nullable();
        });
        Schema::create('clinic_appointments', function (Blueprint $table) {
            $table->id();
            $table->string('appointment_reference')->unique();
            $table->string('appointment_type')->default('pre_registered');
            $table->string('status');
            $table->date('appointment_date');
            $table->unsignedInteger('window_id')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('pet_id')->nullable();
            $table->timestamps();
        });

        DB::table('time_windows')->insert([
            'window_id' => 1,
            'window_label' => '9:00 AM – 10:00 AM',
        ]);
        DB::table('users')->insert([
            [
                'user_id' => 1,
                'first_name' => 'Gerald',
                'last_name' => 'Customer',
                'email' => 'gerald@example.com',
                'phone' => '09171111111',
                'password_hash' => 'hash',
                'role' => 'customer',
            ],
            [
                'user_id' => 2,
                'first_name' => 'Other',
                'last_name' => 'Customer',
                'email' => 'other@example.com',
                'phone' => '09172222222',
                'password_hash' => 'hash',
                'role' => 'customer',
            ],
        ]);
        DB::table('pets')->insert([
            ['pet_id' => 1, 'user_id' => 1, 'pet_name' => 'Rigby'],
            ['pet_id' => 2, 'user_id' => 2, 'pet_name' => 'Private Pet'],
        ]);
        DB::table('bookings')->insert([
            [
                'booking_id' => 1,
                'booking_reference' => 'GR-OWN-001',
                'user_id' => 1,
                'window_id' => 1,
                'booking_date' => '2026-08-31',
                'status' => 'in_progress',
            ],
            [
                'booking_id' => 2,
                'booking_reference' => 'GR-OTHER-999',
                'user_id' => 2,
                'window_id' => 1,
                'booking_date' => '2026-08-31',
                'status' => 'in_progress',
            ],
        ]);
        DB::table('booking_pets')->insert([
            [
                'booking_id' => 1,
                'pet_id' => 1,
                'grooming_state' => 'in_progress',
            ],
            [
                'booking_id' => 2,
                'pet_id' => 2,
                'grooming_state' => 'in_progress',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('clinic_appointments');
        Schema::dropIfExists('booking_pets');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('time_windows');
        Schema::dropIfExists('pets');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_signed_in_customer_receives_only_their_real_booking_status(): void
    {
        $customer = User::query()->findOrFail(1);
        Sanctum::actingAs($customer);
        Http::fake();

        $response = $this->postJson('/api/chatbot', [
            'message' => 'What is my booking status for Rigby?',
        ])->assertOk()->assertJsonPath('source', 'account_status');

        $reply = $response->json('reply');
        $this->assertStringContainsString('GR-OWN-001', $reply);
        $this->assertStringContainsString('Rigby', $reply);
        $this->assertStringNotContainsString('GR-OTHER-999', $reply);
        $this->assertStringNotContainsString('Private Pet', $reply);
        Http::assertNothingSent();
    }

    public function test_public_customer_is_asked_to_sign_in_for_personal_status(): void
    {
        Http::fake();

        $this->postJson('/api/chatbot', [
            'message' => 'What is my booking status?',
        ])
            ->assertOk()
            ->assertJsonPath('source', 'account_status')
            ->assertJsonFragment([
                'reply' => "**Sign in** to your customer account so I can safely check your own schedule.\n\nYou can also open the Dashboard and select \"Schedules\" or \"Grooming Tracker\".",
            ]);

        Http::assertNothingSent();
    }

    public function test_semantic_status_tool_cannot_select_another_customer_or_send_records_to_groq(): void
    {
        Sanctum::actingAs(User::query()->findOrFail(1));
        config()->set('services.groq.key', 'fake-key');
        Schema::create('clinic_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('groomers_on_duty')->default(2);
            foreach (ClinicSetting::availabilityDefaults() as $column => $value) {
                $table->time($column)->default($value);
            }
            $table->timestamps();
        });
        Schema::create('clinic_closures', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_active')->default(true);
        });
        Http::fake(['*' => Http::response(['choices' => [['message' => [
            'content' => null,
            'tool_calls' => [['function' => [
                'name' => 'customer_status',
                'arguments' => '{"user_id":2,"booking_reference":"GR-OTHER-999"}',
            ]]],
        ]]]])]);
        try {
            $reply = $this->postJson('/api/chatbot', ['message' => 'Has the groomer started on mine yet?'])
                ->assertOk()->assertJsonPath('source', 'account_status')->json('reply');
            $this->assertStringContainsString('GR-OWN-001', $reply);
            $this->assertStringNotContainsString('GR-OTHER-999', $reply);
            $this->assertStringNotContainsString('Private Pet', $reply);
            Http::assertSentCount(1);
            Http::assertSent(function ($request) {
                $payload = json_encode($request->data());
                $this->assertStringNotContainsString('GR-OWN-001', $payload);
                $this->assertStringNotContainsString('Rigby', $payload);
                $this->assertStringNotContainsString('gerald@example.com', $payload);

                return true;
            });
        } finally {
            Schema::dropIfExists('clinic_closures');
            Schema::dropIfExists('clinic_settings');
        }
    }
}
