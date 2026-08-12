<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPetProfileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedBigInteger('unregistered_customer_id')->nullable();
            $table->string('pet_name');
            $table->string('species')->nullable();
        });
        Schema::create('time_windows', function (Blueprint $table) {
            $table->increments('window_id');
            $table->string('window_label');
        });
        Schema::create('bookings', function (Blueprint $table) {
            $table->increments('booking_id');
            $table->string('booking_reference');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('window_id')->nullable();
            $table->date('booking_date');
            $table->string('status');
            $table->boolean('paid')->default(false);
            $table->unsignedTinyInteger('cancel_count')->default(0);
            $table->text('cancellation_reason')->nullable();
        });
        Schema::create('booking_pets', function (Blueprint $table) {
            $table->increments('booking_pet_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('pet_id');
            $table->string('grooming_state')->nullable();
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
        Schema::create('clinic_appointments', function (Blueprint $table) {
            $table->id();
            $table->string('appointment_reference');
            $table->date('appointment_date');
            $table->string('status');
            $table->unsignedInteger('pet_id');
            $table->text('chief_complaint')->nullable();
        });
        Schema::create('clinic_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_appointment_id');
            $table->text('chief_complaint')->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('treatment_given')->nullable();
            $table->date('follow_up_date')->nullable();
            $table->text('follow_up_notes')->nullable();
        });
        Schema::create('clinic_vitals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_appointment_id');
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->decimal('temperature_c', 4, 1)->nullable();
            $table->unsignedInteger('heart_rate_bpm')->nullable();
            $table->unsignedInteger('respiratory_rate_bpm')->nullable();
            $table->unsignedTinyInteger('body_condition_score')->nullable();
        });
        Schema::create('clinic_medications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_record_id');
            $table->string('drug_name');
            $table->string('dosage')->nullable();
            $table->string('frequency')->nullable();
            $table->string('duration')->nullable();
            $table->text('instructions')->nullable();
        });
        Schema::create('vaccination_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('pet_id');
            $table->unsignedBigInteger('clinic_appointment_id')->nullable();
            $table->string('vaccine_name');
            $table->string('product_name')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('batch_number')->nullable();
            $table->date('administered_date');
            $table->date('next_due_date')->nullable();
            $table->date('product_expiry_date')->nullable();
            $table->decimal('dose_amount', 8, 2)->nullable();
            $table->string('dose_unit')->nullable();
            $table->string('route')->nullable();
            $table->string('administration_site')->nullable();
            $table->unsignedInteger('administered_by_user_id')->nullable();
            $table->string('administered_by_name')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        foreach ([
            'vaccination_records',
            'clinic_medications',
            'clinic_vitals',
            'clinic_records',
            'clinic_appointments',
            'booking_services',
            'services',
            'booking_pets',
            'bookings',
            'time_windows',
            'pets',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_admin_pet_profile_requires_staff_or_admin_role(): void
    {
        $this->insertPet(101, 'Mochi');
        $this->authenticate(10, 'customer');

        $this->getJson('/api/admin/pets/101/profile')
            ->assertForbidden()
            ->assertJsonPath('message', 'Unauthorized. Admin or staff access required.');
    }

    public function test_staff_can_retrieve_pet_scoped_grooming_medical_and_vaccination_records(): void
    {
        $this->insertPet(101, 'Mochi');
        $this->insertPet(202, 'Other Pet');
        $this->authenticate(20, 'staff');

        DB::table('time_windows')->insert(['window_id' => 1, 'window_label' => '8:00 AM - 10:00 AM']);
        DB::table('bookings')->insert([
            'booking_id' => 301,
            'booking_reference' => 'BAC-301',
            'user_id' => 10,
            'window_id' => 1,
            'booking_date' => '2026-08-01',
            'status' => 'archived',
            'paid' => true,
        ]);
        DB::table('booking_pets')->insert([
            'booking_pet_id' => 401,
            'booking_id' => 301,
            'pet_id' => 101,
            'grooming_state' => 'finished',
            'grooming_start_time' => '2026-08-01 08:30:00',
            'grooming_end_time' => '2026-08-01 09:30:00',
        ]);
        DB::table('services')->insert(['service_id' => 501, 'service_name' => 'Full Groom']);
        DB::table('booking_services')->insert([
            'booking_service_id' => 601,
            'booking_id' => 301,
            'booking_pet_id' => 401,
            'service_id' => 501,
        ]);

        DB::table('clinic_appointments')->insert([
            'id' => 701,
            'appointment_reference' => 'CL-701',
            'appointment_date' => '2026-08-02',
            'status' => 'completed',
            'pet_id' => 101,
            'chief_complaint' => 'Itchy skin',
        ]);
        $clinicRecordId = DB::table('clinic_records')->insertGetId([
            'clinic_appointment_id' => 701,
            'chief_complaint' => 'Itchy skin',
            'diagnosis' => 'Dermatitis',
            'treatment_given' => 'Medicated bath',
        ]);
        DB::table('clinic_medications')->insert([
            'clinic_record_id' => $clinicRecordId,
            'drug_name' => 'Skin Care Medicine',
        ]);
        DB::table('vaccination_records')->insert([
            'pet_id' => 101,
            'clinic_appointment_id' => 701,
            'vaccine_name' => 'Rabies',
            'administered_date' => '2026-08-02',
            'administered_by_name' => 'Dr. Reyes',
            'published_at' => '2026-08-02 12:00:00',
            'created_at' => '2026-08-02 12:00:00',
            'updated_at' => '2026-08-02 12:00:00',
        ]);

        $this->getJson('/api/admin/pets/101/profile')
            ->assertOk()
            ->assertJsonPath('grooming_records.0.booking_reference', 'BAC-301')
            ->assertJsonPath('grooming_records.0.services.0', 'Full Groom')
            ->assertJsonPath('medical_records.0.appointment_reference', 'CL-701')
            ->assertJsonPath('medical_records.0.diagnosis', 'Dermatitis')
            ->assertJsonPath('medical_records.0.medications.0.drug_name', 'Skin Care Medicine')
            ->assertJsonPath('vaccinations.0.vaccine_name', 'Rabies')
            ->assertJsonPath('vaccinations.0.administering_provider', 'Dr. Reyes');

        $this->getJson('/api/admin/pets/999/profile')
            ->assertNotFound()
            ->assertJsonPath('message', 'Pet not found.');
    }

    private function insertPet(int $petId, string $name): void
    {
        DB::table('pets')->insert([
            'pet_id' => $petId,
            'user_id' => 10,
            'pet_name' => $name,
            'species' => 'Cat',
        ]);
    }

    private function authenticate(int $userId, string $role): void
    {
        $user = new User;
        $user->forceFill([
            'user_id' => $userId,
            'first_name' => 'Jamie',
            'last_name' => 'Santos',
            'role' => $role,
        ]);
        $user->exists = true;

        Sanctum::actingAs($user, ['*']);
    }
}
