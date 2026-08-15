<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerPreRegistrationAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('role')->default('customer');
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->increments('booking_id');
            $table->string('booking_reference');
            $table->unsignedInteger('user_id');
            $table->string('status');
        });

        Schema::create('clinic_appointments', function (Blueprint $table) {
            $table->id();
            $table->string('appointment_reference');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('status');
            $table->timestamps();
        });

        DB::table('users')->insert([
            'user_id' => 22,
            'role' => 'customer',
        ]);
        $customer = User::query()->findOrFail(22);
        Sanctum::actingAs($customer, ['*']);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('clinic_appointments');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_completed_and_terminal_visits_never_limit_future_pre_registration(): void
    {
        foreach (range(1, 12) as $number) {
            DB::table('bookings')->insert([
                'booking_reference' => "BAC-HISTORY-{$number}",
                'user_id' => 22,
                'status' => $number % 3 === 0 ? 'cancelled' : 'archived',
            ]);

            DB::table('clinic_appointments')->insert([
                'appointment_reference' => "CL-HISTORY-{$number}",
                'user_id' => 22,
                'status' => $number % 3 === 0
                    ? 'cancelled'
                    : ($number % 2 === 0 ? 'no_show' : 'completed'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->getJson('/api/pre-registration/access')
            ->assertOk()
            ->assertJsonPath('allowed', true)
            ->assertJsonPath('has_ongoing_registration', false)
            ->assertJsonPath('ongoing', null);
    }

    public function test_ongoing_grooming_blocks_both_pre_registration_choices(): void
    {
        DB::table('bookings')->insert([
            'booking_reference' => 'BAC-ONGOING-1',
            'user_id' => 22,
            'status' => 'for_payment',
        ]);

        $this->getJson('/api/pre-registration/access')
            ->assertOk()
            ->assertJsonPath('allowed', false)
            ->assertJsonPath('has_ongoing_registration', true)
            ->assertJsonPath('ongoing.type', 'grooming')
            ->assertJsonPath('ongoing.reference', 'BAC-ONGOING-1');
    }

    public function test_ongoing_clinic_visit_blocks_both_pre_registration_choices(): void
    {
        DB::table('bookings')->insert([
            'booking_reference' => 'BAC-COMPLETE-1',
            'user_id' => 22,
            'status' => 'archived',
        ]);
        DB::table('clinic_appointments')->insert([
            'appointment_reference' => 'CL-ONGOING-1',
            'user_id' => 22,
            'status' => 'in_consultation',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/pre-registration/access')
            ->assertOk()
            ->assertJsonPath('allowed', false)
            ->assertJsonPath('has_ongoing_registration', true)
            ->assertJsonPath('ongoing.type', 'clinic')
            ->assertJsonPath('ongoing.reference', 'CL-ONGOING-1');
    }
}
