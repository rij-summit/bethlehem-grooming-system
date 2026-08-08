<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientPetProfileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id');
            $table->unsignedInteger('user_id');
            $table->string('pet_name');
            $table->string('species')->nullable();
            $table->string('breed')->nullable();
            $table->string('gender')->nullable();
            $table->date('birthdate')->nullable();
            $table->boolean('is_neutered')->nullable();
            $table->decimal('weight', 5, 2)->nullable();
            $table->string('color')->nullable();
            $table->string('size')->nullable();
            $table->string('fur_type')->nullable();
            $table->text('medical_conditions')->nullable();
            $table->boolean('is_archived')->default(false);
        });

        Schema::create('time_windows', function (Blueprint $table) {
            $table->increments('window_id');
            $table->string('window_label');
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->increments('booking_id');
            $table->string('booking_reference');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('window_id')->nullable();
            $table->date('booking_date');
            $table->unsignedTinyInteger('number_of_pets')->default(1);
            $table->string('status');
            $table->boolean('paid')->default(false);
            $table->unsignedTinyInteger('reschedule_count')->default(0);
            $table->unsignedTinyInteger('cancel_count')->default(0);
            $table->text('special_notes')->nullable();
            $table->dateTime('dropped_off_at')->nullable();
            $table->dateTime('grooming_started_at')->nullable();
            $table->dateTime('grooming_finished_at')->nullable();
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('booking_pets', function (Blueprint $table) {
            $table->increments('booking_pet_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('pet_id');
            $table->dateTime('grooming_start_time')->nullable();
            $table->dateTime('grooming_end_time')->nullable();
        });

        Schema::create('services', function (Blueprint $table) {
            $table->increments('service_id');
            $table->string('service_name');
        });

        Schema::create('booking_services', function (Blueprint $table) {
            $table->increments('booking_service_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('booking_pet_id')->nullable();
            $table->unsignedInteger('service_id')->nullable();
            $table->decimal('price_at_booking', 8, 2)->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('booking_services');
        Schema::dropIfExists('services');
        Schema::dropIfExists('booking_pets');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('time_windows');
        Schema::dropIfExists('pets');

        parent::tearDown();
    }

    public function test_pet_profile_requires_authentication(): void
    {
        $this->getJson('/api/pets/101')->assertUnauthorized();
    }

    public function test_customer_can_open_only_a_pet_they_own(): void
    {
        $this->authenticateCustomer(10);

        DB::table('pets')->insert([
            'pet_id' => 101,
            'user_id' => 10,
            'pet_name' => 'Mochi',
            'species' => 'cat',
            'breed' => 'Persian',
            'gender' => 'female',
            'birthdate' => '2022-03-14',
            'is_neutered' => true,
            'weight' => 4.25,
            'color' => 'White',
            'size' => 'small',
            'fur_type' => 'long',
            'medical_conditions' => 'Sensitive skin',
            'is_archived' => false,
        ]);
        DB::table('pets')->insert([
            'pet_id' => 202,
            'user_id' => 20,
            'pet_name' => 'Not My Pet',
            'species' => 'dog',
        ]);

        $this->getJson('/api/pets/101')
            ->assertOk()
            ->assertJsonPath('pet.pet_id', 101)
            ->assertJsonPath('pet.pet_name', 'Mochi')
            ->assertJsonPath('pet.medical_conditions', 'Sensitive skin');

        $this->getJson('/api/pets/202')
            ->assertNotFound()
            ->assertJsonPath('message', 'Pet not found.');
    }

    public function test_customer_can_add_gender_birthdate_and_connected_pet_fields(): void
    {
        $this->authenticateCustomer(10);

        $this->postJson('/api/pets', [
            'pet_name' => 'Bruno',
            'species' => 'Dog',
            'breed' => 'Beagle',
            'gender' => 'male',
            'birthdate' => '2023-05-12',
            'fur_type' => 'Smooth Short Coat',
            'weight' => 8.5,
        ])
            ->assertCreated()
            ->assertJsonPath('pet.gender', 'male')
            ->assertJsonPath('pet.birthdate', '2023-05-12')
            ->assertJsonPath('pet.fur_type', 'Smooth Short Coat')
            ->assertJsonPath('pet.size', 'small');

        $this->assertDatabaseHas('pets', [
            'user_id' => 10,
            'pet_name' => 'Bruno',
            'gender' => 'male',
            'birthdate' => '2023-05-12',
            'size' => 'small',
        ]);
    }

    public function test_customer_can_edit_gender_birthdate_and_connected_pet_fields(): void
    {
        $this->authenticateCustomer(10);

        DB::table('pets')->insert([
            'pet_id' => 101,
            'user_id' => 10,
            'pet_name' => 'Mochi',
            'species' => 'Dog',
            'breed' => 'Beagle',
            'fur_type' => 'Smooth Short Coat',
            'weight' => 8.5,
            'size' => 'small',
        ]);

        $this->putJson('/api/pets/101', [
            'pet_name' => 'Mochi',
            'species' => 'Cat',
            'breed' => 'Persian',
            'gender' => 'female',
            'birthdate' => '2022-03-14',
            'fur_type' => 'Long Dense Coat',
            'weight' => 6,
        ])
            ->assertOk()
            ->assertJsonPath('pet.gender', 'female')
            ->assertJsonPath('pet.birthdate', '2022-03-14')
            ->assertJsonPath('pet.fur_type', 'Long Dense Coat')
            ->assertJsonPath('pet.size', 'medium');

        $this->assertDatabaseHas('pets', [
            'pet_id' => 101,
            'gender' => 'female',
            'birthdate' => '2022-03-14',
            'size' => 'medium',
        ]);
    }

    public function test_pet_filtered_history_excludes_sibling_pets_and_their_services(): void
    {
        $this->authenticateCustomer(10);

        DB::table('pets')->insert([
            ['pet_id' => 101, 'user_id' => 10, 'pet_name' => 'Mochi', 'species' => 'cat'],
            ['pet_id' => 102, 'user_id' => 10, 'pet_name' => 'Bruno', 'species' => 'dog'],
            ['pet_id' => 202, 'user_id' => 20, 'pet_name' => 'Not My Pet', 'species' => 'dog'],
        ]);
        DB::table('time_windows')->insert([
            'window_id' => 1,
            'window_label' => '8:00 AM - 10:00 AM',
        ]);
        DB::table('bookings')->insert([
            [
                'booking_id' => 301,
                'booking_reference' => 'BAC-20260722-0001',
                'user_id' => 10,
                'window_id' => 1,
                'booking_date' => '2026-07-22',
                'number_of_pets' => 2,
                'status' => 'archived',
                'paid' => true,
                'created_at' => '2026-07-20 09:00:00',
            ],
            [
                'booking_id' => 302,
                'booking_reference' => 'BAC-20260723-0002',
                'user_id' => 10,
                'window_id' => 1,
                'booking_date' => '2026-07-23',
                'number_of_pets' => 1,
                'status' => 'archived',
                'paid' => false,
                'created_at' => '2026-07-20 10:00:00',
            ],
        ]);
        DB::table('booking_pets')->insert([
            [
                'booking_pet_id' => 401,
                'booking_id' => 301,
                'pet_id' => 101,
                'grooming_start_time' => '2026-07-22 09:05:00',
                'grooming_end_time' => '2026-07-22 10:15:00',
            ],
            [
                'booking_pet_id' => 402,
                'booking_id' => 301,
                'pet_id' => 102,
                'grooming_start_time' => '2026-07-22 09:20:00',
                'grooming_end_time' => null,
            ],
            [
                'booking_pet_id' => 403,
                'booking_id' => 302,
                'pet_id' => 102,
                'grooming_start_time' => null,
                'grooming_end_time' => null,
            ],
        ]);
        DB::table('services')->insert([
            ['service_id' => 501, 'service_name' => 'Full Groom'],
            ['service_id' => 502, 'service_name' => 'Nail Trim'],
        ]);
        DB::table('booking_services')->insert([
            [
                'booking_service_id' => 601,
                'booking_id' => 301,
                'booking_pet_id' => 401,
                'service_id' => 501,
                'price_at_booking' => 800,
            ],
            [
                'booking_service_id' => 602,
                'booking_id' => 301,
                'booking_pet_id' => 402,
                'service_id' => 502,
                'price_at_booking' => 150,
            ],
        ]);

        $this->getJson('/api/booking/history?pet_id=101')
            ->assertOk()
            ->assertJsonCount(0, 'bookings')
            ->assertJsonCount(1, 'history')
            ->assertJsonCount(1, 'history.0.pets')
            ->assertJsonPath('history.0.booking_id', 301)
            ->assertJsonPath('history.0.pets.0.pet_id', 101)
            ->assertJsonPath('history.0.pets.0.grooming_status', 'grooming_finished')
            ->assertJsonPath('history.0.pets.0.grooming_started_at', '9:05 AM')
            ->assertJsonPath('history.0.pets.0.grooming_finished_at', '10:15 AM')
            ->assertJsonPath('history.0.pets.0.services.0.service_name', 'Full Groom')
            ->assertJsonMissing(['pet_id' => 102])
            ->assertJsonMissing(['service_name' => 'Nail Trim']);

        $this->getJson('/api/booking/history?pet_id=202')
            ->assertNotFound()
            ->assertJsonPath('message', 'Pet not found.');
    }

    private function authenticateCustomer(int $userId): void
    {
        $user = new User;
        $user->forceFill([
            'user_id' => $userId,
            'first_name' => 'Jamie',
            'last_name' => 'Santos',
            'role' => 'customer',
        ]);
        $user->exists = true;

        Sanctum::actingAs($user, ['*']);
    }
}
