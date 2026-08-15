<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCustomerPetSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('username')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password_hash')->nullable();
            $table->string('role')->default('customer');
            $table->string('customer_tier')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->increments('booking_id');
            $table->unsignedInteger('user_id');
            $table->string('status');
        });

        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id');
            $table->unsignedInteger('user_id');
            $table->string('pet_name');
            $table->string('species');
            $table->string('breed')->nullable();
            $table->boolean('is_archived')->default(false);
        });

        DB::table('users')->insert([
            [
                'user_id' => 1,
                'first_name' => 'Staff',
                'last_name' => 'User',
                'email' => 'staff@example.test',
                'phone' => null,
                'role' => 'staff',
            ],
            [
                'user_id' => 10,
                'first_name' => 'Andrew',
                'last_name' => 'Hermosa',
                'email' => 'andrew@example.test',
                'phone' => '09171234567',
                'role' => 'customer',
            ],
            [
                'user_id' => 20,
                'first_name' => 'Juan',
                'last_name' => 'Dela Cruz',
                'email' => 'juan@example.test',
                'phone' => '09179876543',
                'role' => 'customer',
            ],
            [
                'user_id' => 30,
                'first_name' => 'Max',
                'last_name' => 'Hernandez',
                'email' => 'max@example.test',
                'phone' => null,
                'role' => 'customer',
            ],
        ]);

        DB::table('pets')->insert([
            [
                'pet_id' => 101,
                'user_id' => 10,
                'pet_name' => 'Max',
                'species' => 'Dog',
                'breed' => 'Shih Tzu',
            ],
            [
                'pet_id' => 102,
                'user_id' => 20,
                'pet_name' => 'Max',
                'species' => 'Dog',
                'breed' => 'Golden Retriever',
            ],
            [
                'pet_id' => 103,
                'user_id' => 30,
                'pet_name' => 'Buddy',
                'species' => 'Dog',
                'breed' => 'Beagle',
            ],
        ]);

        Sanctum::actingAs(User::query()->findOrFail(1), ['*']);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('pets');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_search_returns_customer_and_pet_matches_as_separate_results(): void
    {
        $this->getJson('/api/admin/customers?status=active&search=Max')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('customers.0.fullName', 'Max Hernandez')
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('petTotal', 2)
            ->assertJsonCount(2, 'pets')
            ->assertJsonPath('pets.0.petName', 'Max')
            ->assertJsonPath('pets.0.species', 'Dog')
            ->assertJsonPath('pets.0.breed', 'Shih Tzu')
            ->assertJsonPath('pets.0.ownerId', 10)
            ->assertJsonPath('pets.0.ownerName', 'Andrew Hermosa')
            ->assertJsonPath('pets.0.ownerPhone', '09171234567')
            ->assertJsonPath('pets.0.ownerEmail', 'andrew@example.test')
            ->assertJsonPath('pets.1.petName', 'Max')
            ->assertJsonPath('pets.1.breed', 'Golden Retriever')
            ->assertJsonPath('pets.1.ownerId', 20)
            ->assertJsonPath('pets.1.ownerName', 'Juan Dela Cruz')
            ->assertJsonPath('pets.1.ownerPhone', '09179876543')
            ->assertJsonPath('pets.1.ownerEmail', 'juan@example.test');
    }

    public function test_pet_results_are_empty_until_a_search_is_entered(): void
    {
        $this->getJson('/api/admin/customers?status=active')
            ->assertOk()
            ->assertJsonPath('petTotal', 0)
            ->assertJsonCount(0, 'pets');
    }
}
