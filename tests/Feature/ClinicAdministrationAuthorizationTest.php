<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminClinicController;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClinicAdministrationAuthorizationTest extends TestCase
{
    private const FORBIDDEN_RESPONSE = [
        'success' => false,
        'message' => 'Forbidden. You do not have permission to access this resource.',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('role');
            $table->string('password_hash')->nullable();
            $table->timestamp('account_deleted_at')->nullable();
        });

        Schema::create('unregistered_customers', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('middle_name')->nullable();
            $table->string('phone');
            $table->string('email')->nullable();
            $table->unsignedInteger('created_by_user_id')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->dateTime('archived_at')->nullable();
            $table->timestamp('account_deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedBigInteger('unregistered_customer_id')->nullable();
            $table->string('pet_name');
            $table->string('species')->nullable();
            $table->string('breed')->nullable();
            $table->string('gender')->nullable();
            $table->date('birthdate')->nullable();
            $table->boolean('is_neutered')->nullable();
            $table->date('neutered_date')->nullable();
            $table->boolean('is_deceased')->default(false);
            $table->date('deceased_date')->nullable();
            $table->decimal('weight', 5, 2)->nullable();
            $table->string('color')->nullable();
            $table->string('size')->nullable();
            $table->string('fur_type')->nullable();
            $table->json('clinic_verified_fields')->nullable();
            $table->text('medical_conditions')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('walkins', function (Blueprint $table) {
            $table->id();
            $table->string('fname');
            $table->string('lname');
            $table->string('mname')->nullable();
            $table->string('email')->nullable();
            $table->string('phone');
            $table->boolean('sedation_consent')->default(false);
            $table->boolean('terms_agreed')->default(false);
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedBigInteger('unregistered_customer_id')->nullable();
            $table->string('appointment_type')->default('grooming');
            $table->text('chief_complaint')->nullable();
            $table->timestamps();
        });

        Schema::create('clinic_appointments', function (Blueprint $table) {
            $table->id();
            $table->string('appointment_reference')->unique();
            $table->string('appointment_type')->default('walk_in');
            $table->string('case_type', 30)->nullable();
            $table->string('status')->default('checked_in');
            $table->unsignedSmallInteger('queue_number')->nullable();
            $table->date('appointment_date');
            $table->unsignedInteger('window_id')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedBigInteger('walkin_id')->nullable();
            $table->unsignedInteger('pet_id')->nullable();
            $table->text('chief_complaint')->nullable();
            $table->json('common_concerns')->nullable();
            $table->decimal('total_amount', 10, 2)->nullable();
            $table->boolean('paid')->default(false);
            $table->text('notes')->nullable();
            $table->dateTime('checked_in_at')->nullable();
            $table->dateTime('consultation_started_at')->nullable();
            $table->dateTime('consultation_finished_at')->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('clinic_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_appointment_id');
            $table->text('chief_complaint')->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('findings')->nullable();
            $table->text('treatment_given')->nullable();
            $table->date('follow_up_date')->nullable();
            $table->text('follow_up_notes')->nullable();
            $table->text('vet_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('clinic_vitals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_appointment_id');
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->decimal('temperature_c', 4, 1)->nullable();
            $table->unsignedSmallInteger('heart_rate_bpm')->nullable();
            $table->unsignedSmallInteger('respiratory_rate_bpm')->nullable();
            $table->unsignedTinyInteger('body_condition_score')->nullable();
            $table->timestamps();
        });

        Schema::create('clinic_medications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_record_id');
            $table->string('drug_name');
            $table->string('dosage')->nullable();
            $table->string('frequency')->nullable();
            $table->string('duration')->nullable();
            $table->text('instructions')->nullable();
            $table->timestamps();
        });

        Schema::create('clinic_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_record_id');
            $table->string('file_name');
            $table->string('file_path');
            $table->string('file_type')->nullable();
            $table->unsignedInteger('file_size_bytes')->nullable();
            $table->string('label')->nullable();
            $table->timestamps();
        });

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

        Schema::create('time_windows', function (Blueprint $table) {
            $table->increments('window_id');
            $table->string('window_label');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedTinyInteger('max_slots')->default(4);
            $table->boolean('is_active')->default(true);
        });
        DB::table('time_windows')->insert([
            'window_id' => 1,
            'window_label' => '1',
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
            'max_slots' => 4,
            'is_active' => true,
        ]);

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

        Schema::create('notifications', function (Blueprint $table) {
            $table->increments('notification_id');
            $table->string('type', 50);
            $table->unsignedInteger('booking_id')->nullable();
            $table->text('message');
            $table->boolean('is_read')->default(false);
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
        Carbon::setTestNow();

        Schema::dropIfExists('booking_services');
        Schema::dropIfExists('services');
        Schema::dropIfExists('booking_pets');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('time_windows');
        Schema::dropIfExists('clinic_settings');
        Schema::dropIfExists('clinic_closures');
        Schema::dropIfExists('clinic_attachments');
        Schema::dropIfExists('clinic_medications');
        Schema::dropIfExists('clinic_vitals');
        Schema::dropIfExists('clinic_records');
        Schema::dropIfExists('clinic_appointments');
        Schema::dropIfExists('walkins');
        Schema::dropIfExists('pets');
        Schema::dropIfExists('unregistered_customers');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    #[DataProvider('unauthenticatedClinicRoutes')]
    public function test_unauthenticated_visitors_cannot_access_clinic_administration(
        string $method,
        string $uri,
        array $payload = [],
    ): void {
        $this->json($method, $uri, $payload)
            ->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public static function unauthenticatedClinicRoutes(): array
    {
        return [
            'appointment listing' => ['GET', '/api/admin/clinic-appointments'],
            'clinic archive listing' => ['GET', '/api/admin/clinic-appointments/archived'],
            'medical record modification' => ['POST', '/api/admin/clinic-appointments/1/record', [
                'diagnosis' => 'Must not be accepted',
            ]],
            'attachment upload' => ['POST', '/api/admin/clinic-appointments/1/attachments'],
            'attachment download' => ['GET', '/api/admin/clinic-appointments/1/attachments/1/download'],
            'attachment deletion' => ['DELETE', '/api/admin/clinic-appointments/1/attachments/1'],
        ];
    }

    #[DataProvider('customerForbiddenClinicRoutes')]
    public function test_customers_receive_the_same_forbidden_response_for_every_sensitive_clinic_action(
        string $method,
        string $uri,
        array $payload = [],
    ): void {
        $this->authenticateAs('customer');

        $this->json($method, $uri, $payload)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_RESPONSE);
    }

    public static function customerForbiddenClinicRoutes(): array
    {
        return [
            'view appointments and detailed records' => ['GET', '/api/admin/clinic-appointments'],
            'view archived clinic records' => ['GET', '/api/admin/clinic-appointments/archived'],
            'create clinic walk-in' => ['POST', '/api/admin/clinic-walk-in'],
            'check in appointment' => ['POST', '/api/admin/clinic-appointments/1/check-in'],
            'start consultation' => ['POST', '/api/admin/clinic-appointments/1/start-consultation'],
            'finish consultation' => ['POST', '/api/admin/clinic-appointments/1/finish-consultation'],
            'mark clinic payment' => ['POST', '/api/admin/clinic-appointments/1/pay'],
            'cancel appointment' => ['POST', '/api/admin/clinic-appointments/1/cancel'],
            'save records, vitals, and medications' => ['POST', '/api/admin/clinic-appointments/1/record', [
                'diagnosis' => 'Must not be accepted',
                'weight_kg' => 8.2,
                'medications' => [[
                    'drug_name' => 'Must not be accepted',
                ]],
            ]],
            'upload medical attachment' => ['POST', '/api/admin/clinic-appointments/1/attachments'],
            'download medical attachment' => ['GET', '/api/admin/clinic-appointments/1/attachments/1/download'],
            'delete medical attachment' => ['DELETE', '/api/admin/clinic-appointments/1/attachments/1'],
            'stop clinic operations' => ['POST', '/api/admin/clinic/stop-today'],
            'change clinic settings' => ['PATCH', '/api/admin/clinic/settings/groomers-on-duty', [
                'groomers_on_duty' => 3,
            ]],
            'view availability settings' => ['GET', '/api/admin/clinic/settings/availability'],
            'change availability settings' => ['PATCH', '/api/admin/clinic/settings/availability', [
                'service' => 'clinic',
                'open_time' => '08:00',
                'close_time' => '17:00',
                'pre_registration_cutoff_time' => '14:00',
            ]],
        ];
    }

    public function test_staff_can_list_clinic_appointments_and_save_a_medical_record(): void
    {
        $this->authenticateAs('staff');
        $this->insertClinicAppointment(status: 'in_consultation');

        $this->getJson('/api/admin/clinic-appointments')
            ->assertOk()
            ->assertJsonPath('in_consultation.0.appointment_reference', 'CL-20260722-001');

        $this->postJson('/api/admin/clinic-appointments/1/record', [
            'diagnosis' => 'Authorized test diagnosis',
            'weight_kg' => 8.2,
            'temperature_c' => 38.4,
            'medications' => [[
                'drug_name' => 'Authorized test medication',
                'dosage' => '1 tablet',
            ]],
        ])
            ->assertOk()
            ->assertJsonPath('record.diagnosis', 'Authorized test diagnosis')
            ->assertJsonPath('record.medications.0.drug_name', 'Authorized test medication');

        $this->assertDatabaseHas('clinic_vitals', [
            'clinic_appointment_id' => 1,
            'weight_kg' => 8.2,
        ]);
    }

    public function test_admin_clinic_archive_lists_only_terminal_visits_with_full_existing_record_data(): void
    {
        $this->authenticateAs('admin');

        DB::table('users')->insert([
            'user_id' => 10,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => 'maria@example.test',
            'phone' => '09171234567',
            'role' => 'customer',
            'account_deleted_at' => now(),
        ]);
        DB::table('pets')->insert([
            'pet_id' => 101,
            'user_id' => 10,
            'pet_name' => 'Mochi',
            'species' => 'cat',
            'breed' => 'Persian',
            'gender' => 'female',
            'weight' => 4.5,
            'medical_conditions' => 'Asthma',
            'is_archived' => false,
        ]);

        $this->insertClinicAppointment(status: 'completed', id: 1);
        DB::table('clinic_appointments')->where('id', 1)->update([
            'appointment_type' => 'pre_registered',
            'window_id' => 1,
            'user_id' => 10,
            'pet_id' => 101,
            'total_amount' => 750,
            'paid' => true,
            'consultation_finished_at' => now(),
            'updated_at' => now()->addHour(),
        ]);
        $this->insertClinicAppointment(status: 'cancelled', id: 2);
        $this->insertClinicAppointment(status: 'no_show', id: 3);
        $this->insertClinicAppointment(status: 'in_consultation', id: 4);

        $recordId = DB::table('clinic_records')->insertGetId([
            'clinic_appointment_id' => 1,
            'chief_complaint' => 'Breathing concern',
            'diagnosis' => 'Mild irritation',
            'findings' => 'Stable on examination',
            'treatment_given' => 'Supportive care',
            'follow_up_date' => now()->addWeek()->toDateString(),
            'follow_up_notes' => 'Return if symptoms worsen',
            'vet_notes' => 'Monitor at home',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('clinic_vitals')->insert([
            'clinic_appointment_id' => 1,
            'weight_kg' => 4.5,
            'temperature_c' => 38.2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('clinic_medications')->insert([
            'clinic_record_id' => $recordId,
            'drug_name' => 'Test medication',
            'dosage' => '1 tablet',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/admin/clinic-appointments/archived')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('total', 3)
            ->assertJsonPath('archived.0.appointment_type_label', 'Pre-Registered')
            ->assertJsonPath('archived.0.time_window.window_label', '8:00 AM - 9:00 AM')
            ->assertJsonPath('archived.0.final_status_label', 'Completed')
            ->assertJsonPath('archived.0.payment_status_label', 'Paid')
            ->assertJsonPath('archived.0.owner.name', 'Maria Santos')
            ->assertJsonPath('archived.0.owner.account_deleted', true)
            ->assertJsonPath('archived.0.ownerAccountDeleted', true)
            ->assertJsonPath('archived.0.contactNumber', '—')
            ->assertJsonPath('archived.0.owner.email', null)
            ->assertJsonPath('archived.0.pet.name', 'Mochi')
            ->assertJsonPath('archived.0.pet.medical_conditions', 'Asthma')
            ->assertJsonPath('archived.0.record.diagnosis', 'Mild irritation')
            ->assertJsonPath('archived.0.record.medications.0.drug_name', 'Test medication')
            ->assertJsonPath('archived.0.vitals.temperature_c', '38.2')
            ->assertJsonPath('archived.0.assigned_veterinarian', null)
            ->assertJsonPath('archived.0.activity.created_by_name', null);

        $this->assertNotContains(4, collect($response->json('archived'))->pluck('id')->all());

        $this->getJson('/api/admin/clinic-appointments/archived?search=Mochi')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('archived.0.id', 1);
    }

    public function test_staff_can_create_a_clinic_walk_in(): void
    {
        $this->authenticateAs('staff');

        $this->postJson('/api/admin/clinic-walk-in', [
            'common_concerns' => ['Routine check-up', 'Vaccination'],
            'fname' => 'Maria',
            'lname' => 'Santos',
            'phone' => '09171234567',
            'pet_name' => 'Bantay',
            'species' => 'dog',
            'weight' => 12,
            'size' => 'medium',
            'chief_complaint' => 'Routine consultation',
            'terms_agreed' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('status', 'in_consultation')
            ->assertJsonPath('queue_number', null)
            ->assertJsonPath('pet.name', 'Bantay')
            ->assertJsonPath('common_concerns', ['Routine check-up', 'Vaccination'])
            ->assertJsonPath('chief_complaint', "Routine check-up, Vaccination\nRoutine consultation");

        $this->assertSame(
            ['Routine check-up', 'Vaccination'],
            json_decode(DB::table('clinic_appointments')->value('common_concerns'), true),
        );
        $this->assertDatabaseHas('pets', ['pet_name' => 'Bantay', 'size' => 'medium', 'weight' => 12]);
        $this->assertSame(['size'], json_decode(DB::table('pets')->value('clinic_verified_fields'), true));
    }

    public function test_staff_weight_update_replaces_a_verified_size(): void
    {
        $this->authenticateAs('staff');
        DB::table('pets')->insert([
            'pet_id' => 501,
            'pet_name' => 'Bantay',
            'species' => 'Dog',
            'weight' => 8,
            'size' => 'small',
            'clinic_verified_fields' => json_encode(['size']),
        ]);

        $this->putJson('/api/admin/pets/501', [
            'pet_name' => 'Bantay',
            'species' => 'Dog',
            'weight' => 20,
            'size' => 'small',
        ])
            ->assertOk()
            ->assertJsonPath('pet.size', 'medium');

        $this->assertDatabaseHas('pets', ['pet_id' => 501, 'weight' => 20, 'size' => 'medium']);
        $this->assertSame(['size', 'weight'], json_decode(DB::table('pets')->where('pet_id', 501)->value('clinic_verified_fields'), true));
    }

    public function test_customer_weight_sets_estimated_size_even_if_a_different_size_is_sent(): void
    {
        $this->authenticateAs('customer', 10);

        $this->postJson('/api/pets', [
            'pet_name' => 'Mochi',
            'species' => 'Dog',
            'weight' => 20,
            'size' => 'small',
        ])
            ->assertCreated()
            ->assertJsonPath('pet.size', 'medium')
            ->assertJsonPath('pet.clinic_verified_fields', null);
    }

    public function test_customer_size_estimate_cannot_replace_a_clinic_verified_size(): void
    {
        $this->authenticateAs('customer', 10);
        DB::table('pets')->insert([
            'pet_id' => 502,
            'user_id' => 10,
            'pet_name' => 'Mochi',
            'species' => 'Dog',
            'weight' => 8,
            'size' => 'small',
            'clinic_verified_fields' => json_encode(['size']),
        ]);

        $this->putJson('/api/pets/502', [
            'pet_name' => 'Mochi',
            'species' => 'Dog',
            'weight' => 20,
            'size' => 'medium',
        ])
            ->assertOk()
            ->assertJsonPath('pet.size', 'small');

        $this->assertSame(['size'], json_decode(DB::table('pets')->where('pet_id', 502)->value('clinic_verified_fields'), true));
    }

    public function test_walk_in_requires_valid_distinct_common_concerns(): void
    {
        $this->authenticateAs('staff');
        $payload = [
            'fname' => 'Maria',
            'lname' => 'Santos',
            'phone' => '09171234567',
            'pet_name' => 'Bantay',
            'species' => 'dog',
            'terms_agreed' => true,
        ];

        $this->postJson('/api/admin/clinic-walk-in', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('common_concerns');
        $this->postJson('/api/admin/clinic-walk-in', $payload + ['common_concerns' => ['Other', 'Other']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('common_concerns.0');
        $this->postJson('/api/admin/clinic-walk-in', $payload + ['common_concerns' => ['Unknown']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('common_concerns.0');

        $this->assertDatabaseCount('clinic_appointments', 0);
    }

    public function test_clinic_cases_reuse_one_open_case_per_pet_and_finish_with_saved_record(): void
    {
        $this->authenticateAs('staff');
        DB::table('clinic_settings')->insert(['created_at' => now(), 'updated_at' => now()]);
        DB::table('unregistered_customers')->insert([
            'id' => 1, 'first_name' => 'Ana', 'last_name' => 'Cruz', 'phone' => '09171234567',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pets')->insert(['pet_id' => 1, 'unregistered_customer_id' => 1, 'pet_name' => 'Mochi', 'species' => 'Dog']);

        $caseId = $this->postJson('/api/admin/clinic-cases', ['pet_id' => 1, 'case_type' => 'consultation'])
            ->assertCreated()
            ->assertJsonPath('case.queue_number', null)
            ->json('case.id');
        $this->postJson('/api/admin/clinic-cases', ['pet_id' => 1, 'case_type' => 'vaccination'])
            ->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('case.id', $caseId);
        $this->assertSame(1, DB::table('clinic_appointments')->count());

        $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonCount(1, 'cases');

        $this->postJson("/api/admin/clinic-appointments/{$caseId}/record", [
            'diagnosis' => 'Healthy', 'finish_case' => true,
        ])->assertOk();

        $this->assertSame('completed', DB::table('clinic_appointments')->where('id', $caseId)->value('status'));
        $this->assertSame('Healthy', DB::table('clinic_records')->where('clinic_appointment_id', $caseId)->value('diagnosis'));
        $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonCount(0, 'cases');
    }

    public function test_online_request_is_an_active_case_that_starts_without_a_queue_number(): void
    {
        $this->authenticateAs('staff');
        $this->insertClinicAppointment('waiting_to_arrive');
        DB::table('clinic_appointments')->where('id', 1)->update(['appointment_type' => 'pre_registered', 'queue_number' => null]);

        $this->getJson('/api/admin/clinic-cases')
            ->assertOk()
            ->assertJsonPath('cases.0.case_type', 'online_request')
            ->assertJsonPath('cases.0.queue_number', null);

        $this->postJson('/api/admin/clinic-cases/1/start')
            ->assertOk()
            ->assertJsonPath('case.status', 'in_consultation')
            ->assertJsonPath('case.queue_number', null);
    }

    public function test_clinic_records_are_paginated_newest_first(): void
    {
        $this->authenticateAs('staff');
        DB::table('unregistered_customers')->insert([
            'id' => 1, 'first_name' => 'Ana', 'last_name' => 'Cruz', 'phone' => '09171234567',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (range(1, 30) as $n) {
            DB::table('pets')->insert(['pet_id' => $n, 'unregistered_customer_id' => 1, 'pet_name' => "Pet{$n}", 'species' => 'Dog']);
        }

        $this->getJson('/api/admin/clinic-records')
            ->assertOk()
            ->assertJsonCount(25, 'rows')
            ->assertJsonPath('rows.0.pet.petName', 'Pet30')
            ->assertJsonPath('has_more', true);
        $this->getJson('/api/admin/clinic-records?page=2')->assertJsonCount(5, 'rows')->assertJsonPath('has_more', false);
    }

    public function test_staff_can_create_a_clinic_walk_in_for_a_registered_customer_and_saved_pet(): void
    {
        $this->authenticateAs('staff');

        DB::table('users')->insert([
            'user_id' => 10,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => 'maria@example.com',
            'phone' => '09171234567',
            'role' => 'customer',
        ]);
        DB::table('pets')->insert([
            'pet_id' => 101,
            'user_id' => 10,
            'pet_name' => 'Bantay',
            'species' => 'Dog',
            'breed' => 'Aspin',
            'is_archived' => false,
        ]);

        $this->postJson('/api/admin/clinic-walk-in', [
            'common_concerns' => ['Routine check-up'],
            'fname' => 'Maria',
            'lname' => 'Santos',
            'email' => 'maria@example.com',
            'phone' => '09171234567',
            'owner_record_type' => 'registered',
            'customer_user_id' => 10,
            'pet_id' => 101,
            'pet_name' => 'Bantay',
            'species' => 'Dog',
            'breed' => 'Aspin',
            'chief_complaint' => 'Routine consultation',
            'terms_agreed' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('returning_customer', true)
            ->assertJsonPath('pet.pet_id', 101);

        $this->assertDatabaseHas('walkins', [
            'user_id' => 10,
            'unregistered_customer_id' => null,
            'appointment_type' => 'clinic',
        ]);
        $this->assertDatabaseHas('clinic_appointments', [
            'user_id' => 10,
            'pet_id' => 101,
            'status' => 'in_consultation',
        ]);
        $this->assertDatabaseCount('pets', 1);
    }

    public function test_staff_can_create_a_clinic_walk_in_for_an_unregistered_customer_and_saved_pet(): void
    {
        $this->authenticateAs('staff');

        $customerId = DB::table('unregistered_customers')->insertGetId([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'phone' => '09181234567',
            'email' => 'ana@example.com',
            'is_archived' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('pets')->insert([
            'pet_id' => 102,
            'unregistered_customer_id' => $customerId,
            'pet_name' => 'Mingming',
            'species' => 'Cat',
            'breed' => 'Domestic Shorthair',
            'is_archived' => false,
        ]);

        $this->postJson('/api/admin/clinic-walk-in', [
            'common_concerns' => ['Routine check-up'],
            'fname' => 'Ana',
            'lname' => 'Reyes',
            'email' => 'ana@example.com',
            'phone' => '09181234567',
            'owner_record_type' => 'unregistered',
            'unregistered_customer_id' => $customerId,
            'pet_id' => 102,
            'pet_name' => 'Mingming',
            'species' => 'Cat',
            'breed' => 'Domestic Shorthair',
            'chief_complaint' => 'Loss of appetite',
            'terms_agreed' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('returning_customer', true)
            ->assertJsonPath('pet.pet_id', 102);

        $this->assertDatabaseHas('walkins', [
            'user_id' => null,
            'unregistered_customer_id' => $customerId,
            'appointment_type' => 'clinic',
        ]);
        $this->assertDatabaseHas('clinic_appointments', [
            'user_id' => null,
            'pet_id' => 102,
            'status' => 'in_consultation',
        ]);
        $this->assertDatabaseCount('pets', 1);
    }

    public function test_staff_can_add_a_new_pet_for_an_existing_clinic_walk_in_customer(): void
    {
        $this->authenticateAs('staff');

        DB::table('users')->insert([
            'user_id' => 11,
            'first_name' => 'Jose',
            'last_name' => 'Cruz',
            'email' => 'jose@example.com',
            'phone' => '09191234567',
            'role' => 'customer',
        ]);

        $this->postJson('/api/admin/clinic-walk-in', [
            'common_concerns' => ['Routine check-up'],
            'fname' => 'Jose',
            'lname' => 'Cruz',
            'email' => 'jose@example.com',
            'phone' => '09191234567',
            'owner_record_type' => 'registered',
            'customer_user_id' => 11,
            'pet_name' => 'brownie',
            'species' => 'Dog',
            'chief_complaint' => 'Skin irritation',
            'clinic_quick_entry' => true,
            'terms_agreed' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['breed']);

        $this->postJson('/api/admin/clinic-walk-in', [
            'common_concerns' => ['Routine check-up'],
            'fname' => 'Jose',
            'lname' => 'Cruz',
            'email' => 'jose@example.com',
            'phone' => '09191234567',
            'owner_record_type' => 'registered',
            'customer_user_id' => 11,
            'pet_name' => 'brownie',
            'species' => 'Dog',
            'breed' => 'Aspin',
            'gender' => 'female',
            'birthdate' => '2021-06-15',
            'is_neutered' => true,
            'neutered_date' => '2022-01-10',
            'color' => 'Brown',
            'chief_complaint' => 'Skin irritation',
            'clinic_quick_entry' => true,
            'terms_agreed' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('returning_customer', true)
            ->assertJsonPath('pet.name', 'Brownie');

        $this->assertDatabaseHas('pets', [
            'user_id' => 11,
            'unregistered_customer_id' => null,
            'pet_name' => 'Brownie',
            'breed' => 'Aspin',
            'gender' => 'female',
            'birthdate' => '2021-06-15',
            'is_neutered' => true,
            'neutered_date' => '2022-01-10',
            'color' => 'Brown',
        ]);
        $this->assertDatabaseHas('clinic_appointments', [
            'user_id' => 11,
            'status' => 'in_consultation',
        ]);
    }

    public function test_customer_can_pre_register_an_owned_pet_without_reentering_owner_information(): void
    {
        $this->authenticateAs('customer', 10);
        $appointmentDate = now()->addDay()->toDateString();
        DB::table('pets')->insert([
            'pet_id' => 101,
            'user_id' => 10,
            'pet_name' => 'Mochi',
            'species' => 'cat',
            'breed' => 'Persian',
            'is_archived' => false,
        ]);

        $response = $this->postJson('/api/clinic/pre-register', [
            'common_concerns' => ['Routine check-up', 'Vomiting'],
            'appointment_date' => $appointmentDate,
            'window_id' => 1,
            'pet_id' => 101,
            'chief_complaint' => 'Routine wellness consultation',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('appointment.appointment_type', 'pre_registered')
            ->assertJsonPath('appointment.status', 'waiting_to_arrive')
            ->assertJsonPath('appointment.appointment_date', $appointmentDate)
            ->assertJsonPath('appointment.time_window.window_id', 1)
            ->assertJsonPath('appointment.time_window.window_label', '8:00 AM - 9:00 AM')
            ->assertJsonPath('appointment.pet.pet_id', 101)
            ->assertJsonPath('appointment.owner.name', 'Customer User')
            ->assertJsonPath('appointment.common_concerns', ['Routine check-up', 'Vomiting'])
            ->assertJsonPath('appointment.chief_complaint', "Routine check-up, Vomiting\nRoutine wellness consultation");

        $this->assertDatabaseHas('clinic_appointments', [
            'appointment_type' => 'pre_registered',
            'status' => 'waiting_to_arrive',
            'queue_number' => null,
            'window_id' => 1,
            'user_id' => 10,
            'walkin_id' => null,
            'pet_id' => 101,
            'chief_complaint' => "Routine check-up, Vomiting\nRoutine wellness consultation",
        ]);
        $this->assertSame(
            ['Routine check-up', 'Vomiting'],
            json_decode(DB::table('clinic_appointments')->value('common_concerns'), true),
        );
        $this->assertDatabaseCount('walkins', 0);
    }

    public function test_pre_registration_requires_common_concerns_and_allows_optional_details(): void
    {
        $this->authenticateAs('customer', 10);
        DB::table('pets')->insert([
            'pet_id' => 101,
            'user_id' => 10,
            'pet_name' => 'Mochi',
            'species' => 'cat',
            'is_archived' => false,
        ]);
        $payload = [
            'appointment_date' => now()->addDay()->toDateString(),
            'window_id' => 1,
            'pet_id' => 101,
        ];

        $this->postJson('/api/clinic/pre-register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('common_concerns');
        $this->postJson('/api/clinic/pre-register', $payload + ['common_concerns' => ['Unknown']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('common_concerns.0');
        $this->postJson('/api/clinic/pre-register', $payload + [
            'common_concerns' => ['Routine check-up', 'Vaccination'],
        ])
            ->assertCreated()
            ->assertJsonPath('appointment.common_concerns', ['Routine check-up', 'Vaccination'])
            ->assertJsonPath('appointment.chief_complaint', 'Routine check-up, Vaccination');
    }

    public function test_ongoing_grooming_blocks_a_new_clinic_pre_registration(): void
    {
        $this->authenticateAs('customer', 10);
        DB::table('pets')->insert([
            'pet_id' => 101,
            'user_id' => 10,
            'pet_name' => 'Mochi',
            'species' => 'cat',
            'is_archived' => false,
        ]);
        DB::table('bookings')->insert([
            'booking_reference' => 'BAC-ONGOING-1',
            'user_id' => 10,
            'window_id' => 1,
            'booking_date' => now()->addDay()->toDateString(),
            'number_of_pets' => 1,
            'status' => 'waiting_to_arrive',
        ]);

        $this->postJson('/api/clinic/pre-register', [
            'common_concerns' => ['Routine check-up'],
            'appointment_date' => now()->addDay()->toDateString(),
            'window_id' => 1,
            'pet_id' => 101,
            'chief_complaint' => 'Routine wellness consultation',
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ongoing_pre_registration')
            ->assertJsonPath('ongoing.type', 'grooming');

        $this->assertDatabaseCount('clinic_appointments', 0);
    }

    public function test_ongoing_clinic_visit_blocks_a_new_grooming_pre_registration(): void
    {
        $this->authenticateAs('customer', 10);
        DB::table('pets')->insert([
            'pet_id' => 101,
            'user_id' => 10,
            'pet_name' => 'Mochi',
            'species' => 'cat',
            'is_archived' => false,
        ]);
        DB::table('clinic_appointments')->insert([
            'appointment_reference' => 'CL-ONGOING-1',
            'appointment_type' => 'pre_registered',
            'status' => 'waiting_to_arrive',
            'appointment_date' => now()->addDay()->toDateString(),
            'window_id' => 1,
            'user_id' => 10,
            'pet_id' => 101,
            'paid' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/booking/store', [
            'booking_date' => now()->addDay()->toDateString(),
            'window_id' => 1,
            'number_of_pets' => 1,
            'pets' => [[
                'pet_id' => 101,
                'pet_name' => 'Mochi',
                'species' => 'cat',
            ]],
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ongoing_pre_registration')
            ->assertJsonPath('ongoing.type', 'clinic');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_customer_cannot_pre_register_another_customers_pet(): void
    {
        $this->authenticateAs('customer', 10);
        DB::table('pets')->insert([
            'pet_id' => 202,
            'user_id' => 20,
            'pet_name' => 'Not My Pet',
            'species' => 'dog',
            'is_archived' => false,
        ]);

        $this->postJson('/api/clinic/pre-register', [
            'common_concerns' => ['Routine check-up'],
            'appointment_date' => now()->addDay()->toDateString(),
            'window_id' => 1,
            'pet_id' => 202,
            'chief_complaint' => 'Routine wellness consultation',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pet_id');

        $this->assertDatabaseCount('clinic_appointments', 0);
    }

    public function test_customer_pre_registration_rechecks_clinic_closures_on_submission(): void
    {
        $this->authenticateAs('customer', 10);
        $appointmentDate = now()->addDay()->toDateString();
        DB::table('pets')->insert([
            'pet_id' => 101,
            'user_id' => 10,
            'pet_name' => 'Mochi',
            'species' => 'cat',
            'is_archived' => false,
        ]);
        DB::table('clinic_closures')->insert([
            'type' => 'blocked_date',
            'start_date' => $appointmentDate,
            'end_date' => $appointmentDate,
            'reason' => 'Veterinary team training',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/clinic/pre-register', [
            'common_concerns' => ['Routine check-up'],
            'appointment_date' => $appointmentDate,
            'window_id' => 1,
            'pet_id' => 101,
            'chief_complaint' => 'Routine wellness consultation',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Veterinary team training');

        $this->assertDatabaseCount('clinic_appointments', 0);
    }

    public function test_customer_clinic_timeslots_return_active_window_availability(): void
    {
        $appointmentDate = now()->addDay()->toDateString();

        $this->getJson("/api/clinic/timeslots?date={$appointmentDate}")
            ->assertOk()
            ->assertJsonPath('date', $appointmentDate)
            ->assertJsonPath('windows.0.window_id', 1)
            ->assertJsonPath('windows.0.window_label', '8:00 AM - 9:00 AM')
            ->assertJsonPath('windows.0.remaining', 4)
            ->assertJsonPath('windows.0.is_full', false)
            ->assertJsonPath('windows.0.is_past', false);
    }

    public function test_customer_cannot_submit_a_clinic_window_after_it_reaches_capacity(): void
    {
        $this->authenticateAs('customer', 10);
        $appointmentDate = now()->addDay()->toDateString();
        DB::table('pets')->insert([
            'pet_id' => 101,
            'user_id' => 10,
            'pet_name' => 'Mochi',
            'species' => 'cat',
            'is_archived' => false,
        ]);

        foreach (range(1, 4) as $number) {
            DB::table('clinic_appointments')->insert([
                'appointment_reference' => 'CL-FULL-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
                'appointment_type' => 'pre_registered',
                'status' => 'waiting_to_arrive',
                'appointment_date' => $appointmentDate,
                'window_id' => 1,
                'paid' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->getJson("/api/clinic/timeslots?date={$appointmentDate}")
            ->assertOk()
            ->assertJsonPath('windows.0.remaining', 0)
            ->assertJsonPath('windows.0.is_full', true);

        $this->postJson('/api/clinic/pre-register', [
            'common_concerns' => ['Routine check-up'],
            'appointment_date' => $appointmentDate,
            'window_id' => 1,
            'pet_id' => 101,
            'chief_complaint' => 'Routine wellness consultation',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The selected clinic visit time is no longer available.');

        $this->assertDatabaseCount('clinic_appointments', 4);
    }

    public function test_admin_can_list_appointments_and_update_clinic_status(): void
    {
        $this->authenticateAs('admin');
        $this->insertClinicAppointment(status: 'waiting_to_arrive');

        $this->getJson('/api/admin/clinic-appointments')
            ->assertOk()
            ->assertJsonPath('incoming.0.appointment_reference', 'CL-20260722-001');

        $this->postJson('/api/admin/clinic-appointments/1/check-in')
            ->assertOk()
            ->assertJsonPath('appointment.status', 'checked_in');

        $this->assertDatabaseHas('clinic_appointments', [
            'id' => 1,
            'status' => 'checked_in',
        ]);
    }

    public function test_check_in_assigns_the_next_queue_number_to_a_pre_registration(): void
    {
        $this->authenticateAs('staff');
        $this->insertClinicAppointment(status: 'waiting_to_arrive', id: 1);
        $this->insertClinicAppointment(status: 'checked_in', id: 2);
        DB::table('clinic_appointments')->where('id', 1)->update(['queue_number' => null]);

        $this->postJson('/api/admin/clinic-appointments/1/check-in')
            ->assertOk()
            ->assertJsonPath('appointment.status', 'checked_in')
            ->assertJsonPath('appointment.queue_number', 3);

        $this->assertDatabaseHas('clinic_appointments', [
            'id' => 1,
            'status' => 'checked_in',
            'queue_number' => 3,
        ]);
    }

    public function test_staff_can_view_but_cannot_modify_administrator_managed_availability(): void
    {
        $this->authenticateAs('staff');

        $this->getJson('/api/admin/clinic/blocked-dates')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->getJson('/api/admin/clinic/settings/availability')
            ->assertOk()
            ->assertJsonPath('availability.clinic.open_time', '08:00')
            ->assertJsonPath('groomers_on_duty', 2);

        $this->postJson('/api/admin/clinic/blocked-dates', [
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
        ])
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_RESPONSE);

        $this->deleteJson('/api/admin/clinic/blocked-dates/1')
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_RESPONSE);

        $this->patchJson('/api/admin/clinic/settings/availability', [
            'service' => 'clinic',
            'open_time' => '09:00',
            'close_time' => '18:00',
            'pre_registration_cutoff_time' => '15:00',
        ])
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_RESPONSE);
    }

    public function test_public_clinic_status_includes_configured_service_availability(): void
    {
        DB::table('clinic_settings')->insert([
            'id' => 1,
            'groomers_on_duty' => 2,
            'clinic_open_time' => '09:00:00',
            'clinic_close_time' => '18:00:00',
            'clinic_prereg_cutoff_time' => '15:00:00',
            'grooming_open_time' => '08:00:00',
            'grooming_close_time' => '16:00:00',
            'grooming_prereg_cutoff_time' => '13:00:00',
        ]);

        $this->getJson('/api/clinic/status')
            ->assertOk()
            ->assertJsonPath('availability.clinic.operating_hours_label', '9:00 AM – 6:00 PM')
            ->assertJsonPath('availability.clinic.pre_registration_cutoff_label', '3:00 PM')
            ->assertJsonPath('availability.grooming.operating_hours_label', '8:00 AM – 4:00 PM')
            ->assertJsonPath('availability.grooming.pre_registration_cutoff_label', '1:00 PM');
    }

    public function test_admin_can_add_list_and_remove_blocked_dates(): void
    {
        $this->authenticateAs('admin');
        $startDate = now()->addDay()->toDateString();
        $endDate = now()->addDays(2)->toDateString();

        $this->postJson('/api/admin/clinic/blocked-dates', [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'reason' => 'Team training',
        ])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $blockedDateId = DB::table('clinic_closures')->value('id');

        $this->getJson('/api/admin/clinic/blocked-dates')
            ->assertOk()
            ->assertJsonPath('blocked_dates.0.id', $blockedDateId)
            ->assertJsonPath('blocked_dates.0.reason', 'Team training');

        $this->deleteJson("/api/admin/clinic/blocked-dates/{$blockedDateId}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('clinic_closures', [
            'id' => $blockedDateId,
            'is_active' => false,
        ]);
    }

    public function test_admin_can_view_and_update_each_service_availability(): void
    {
        $this->authenticateAs('admin');

        $this->getJson('/api/admin/clinic/settings/availability')
            ->assertOk()
            ->assertJsonPath('availability.clinic.open_time', '08:00')
            ->assertJsonPath('availability.clinic.close_time', '17:00')
            ->assertJsonPath('availability.clinic.pre_registration_cutoff_time', '14:00')
            ->assertJsonPath('availability.grooming.operating_hours_label', '8:00 AM – 5:00 PM');

        $this->patchJson('/api/admin/clinic/settings/availability', [
            'service' => 'clinic',
            'open_time' => '09:00',
            'close_time' => '18:00',
            'pre_registration_cutoff_time' => '15:30',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Clinic availability updated.')
            ->assertJsonPath('availability.clinic.operating_hours_label', '9:00 AM – 6:00 PM')
            ->assertJsonPath('availability.clinic.pre_registration_cutoff_label', '3:30 PM')
            ->assertJsonPath('availability.grooming.open_time', '08:00');

        $storedSettings = DB::table('clinic_settings')->where('id', 1)->first();
        $this->assertSame('09:00', substr($storedSettings->clinic_open_time, 0, 5));
        $this->assertSame('18:00', substr($storedSettings->clinic_close_time, 0, 5));
        $this->assertSame('15:30', substr($storedSettings->clinic_prereg_cutoff_time, 0, 5));
        $this->assertSame('08:00', substr($storedSettings->grooming_open_time, 0, 5));
    }

    public function test_availability_rejects_invalid_time_order(): void
    {
        $this->authenticateAs('admin');

        $this->patchJson('/api/admin/clinic/settings/availability', [
            'service' => 'grooming',
            'open_time' => '17:00',
            'close_time' => '08:00',
            'pre_registration_cutoff_time' => '14:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('close_time');

        $this->patchJson('/api/admin/clinic/settings/availability', [
            'service' => 'grooming',
            'open_time' => '08:00',
            'close_time' => '17:00',
            'pre_registration_cutoff_time' => '18:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pre_registration_cutoff_time');
    }

    public function test_extending_availability_generates_hourly_windows_and_skips_lunch_break(): void
    {
        $this->authenticateAs('admin');
        DB::table('time_windows')->insert([
            'window_id' => 5,
            'window_label' => '12:00 PM - 1:00 PM',
            'start_time' => '12:00:00',
            'end_time' => '13:00:00',
            'max_slots' => 4,
            'is_active' => true,
        ]);

        $this->patchJson('/api/admin/clinic/settings/availability', [
            'service' => 'clinic',
            'open_time' => '08:00',
            'close_time' => '17:00',
            'pre_registration_cutoff_time' => '17:00',
        ])->assertOk();

        $appointmentDate = now()->addDay()->toDateString();
        $clinicResponse = $this->getJson("/api/clinic/timeslots?date={$appointmentDate}");

        $clinicResponse
            ->assertOk()
            ->assertJsonCount(8, 'windows')
            ->assertJsonPath('windows.6.window_label', '3:00 PM - 4:00 PM')
            ->assertJsonPath('windows.7.window_label', '4:00 PM - 5:00 PM');

        $clinicLabels = collect($clinicResponse->json('windows'))
            ->pluck('window_label');
        $this->assertFalse($clinicLabels->contains('12:00 PM - 1:00 PM'));
        $this->assertSame(0, DB::table('time_windows')
            ->where('start_time', '12:00:00')
            ->where('end_time', '13:00:00')
            ->value('is_active'));

        $groomingResponse = $this->getJson("/api/timeslots?date={$appointmentDate}");
        $groomingResponse
            ->assertOk()
            ->assertJsonCount(5, 'windows')
            ->assertJsonPath('windows.4.window_label', '1:00 PM - 2:00 PM');

        $groomingLabels = collect($groomingResponse->json('windows'))
            ->pluck('window_label');
        $this->assertFalse($groomingLabels->contains('2:00 PM - 3:00 PM'));
        $this->assertFalse($groomingLabels->contains('3:00 PM - 4:00 PM'));
    }

    public function test_clinic_timeslots_follow_configured_hours_and_same_day_cutoff(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-23 13:59:00'));
        DB::table('clinic_settings')->insert([
            'id' => 1,
            'groomers_on_duty' => 2,
            'clinic_open_time' => '09:00:00',
            'clinic_close_time' => '11:00:00',
            'clinic_prereg_cutoff_time' => '14:00:00',
            'grooming_open_time' => '08:00:00',
            'grooming_close_time' => '17:00:00',
            'grooming_prereg_cutoff_time' => '14:00:00',
        ]);
        DB::table('time_windows')->insert([
            'window_id' => 2,
            'window_label' => '2',
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'max_slots' => 4,
            'is_active' => true,
        ]);

        $this->getJson('/api/clinic/timeslots?date=2026-07-23')
            ->assertOk()
            ->assertJsonCount(1, 'windows')
            ->assertJsonPath('windows.0.window_id', 2)
            ->assertJsonPath('windows.0.is_cutoff', false)
            ->assertJsonPath('availability.operating_hours_label', '9:00 AM – 11:00 AM');

        Carbon::setTestNow(Carbon::parse('2026-07-23 14:01:00'));

        $this->getJson('/api/clinic/timeslots?date=2026-07-23')
            ->assertOk()
            ->assertJsonPath('cutoff_passed', true)
            ->assertJsonPath('windows.0.is_cutoff', true);
    }

    public function test_customer_cannot_submit_same_day_clinic_pre_registration_after_cutoff(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-23 14:01:00'));
        $this->authenticateAs('customer', 10);
        DB::table('pets')->insert([
            'pet_id' => 101,
            'user_id' => 10,
            'pet_name' => 'Mochi',
            'species' => 'cat',
            'is_archived' => false,
        ]);

        $this->postJson('/api/clinic/pre-register', [
            'common_concerns' => ['Routine check-up'],
            'appointment_date' => '2026-07-23',
            'window_id' => 1,
            'pet_id' => 101,
            'chief_complaint' => 'Routine wellness consultation',
        ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Same-day clinic pre-registration closes at 2:00 PM. Please choose another date.',
            );

        $this->assertDatabaseCount('clinic_appointments', 0);
    }

    public function test_grooming_timeslots_and_submission_use_grooming_availability(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-23 12:01:00'));
        DB::table('clinic_settings')->insert([
            'id' => 1,
            'groomers_on_duty' => 2,
            'clinic_open_time' => '08:00:00',
            'clinic_close_time' => '17:00:00',
            'clinic_prereg_cutoff_time' => '14:00:00',
            'grooming_open_time' => '09:00:00',
            'grooming_close_time' => '11:00:00',
            'grooming_prereg_cutoff_time' => '12:00:00',
        ]);
        DB::table('time_windows')->insert([
            'window_id' => 2,
            'window_label' => '9:00 AM - 10:00 AM',
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'max_slots' => 4,
            'is_active' => true,
        ]);

        $this->getJson('/api/timeslots?date=2026-07-23')
            ->assertOk()
            ->assertJsonCount(1, 'windows')
            ->assertJsonPath('windows.0.window_id', 2)
            ->assertJsonPath('windows.0.is_past', true)
            ->assertJsonPath('windows.0.is_cutoff', true)
            ->assertJsonPath('availability.pre_registration_cutoff_label', '12:00 PM');

        $this->authenticateAs('customer', 10);
        DB::table('pets')->insert([
            'pet_id' => 101,
            'user_id' => 10,
            'pet_name' => 'Mochi',
            'species' => 'cat',
            'is_archived' => false,
        ]);

        $this->postJson('/api/booking/store', [
            'booking_date' => '2026-07-23',
            'window_id' => 2,
            'number_of_pets' => 1,
            'pets' => [[
                'pet_id' => 101,
                'pet_name' => 'Mochi',
                'species' => 'cat',
            ]],
        ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Same-day grooming pre-registration closes at 12:00 PM. Please choose another date.',
            );
    }

    public function test_customer_cannot_reschedule_to_the_current_date_and_time_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-23 07:00:00'));
        $this->authenticateAs('customer', 10);
        $bookingDate = now()->addDay()->toDateString();

        DB::table('bookings')->insert([
            'booking_id' => 1,
            'booking_reference' => 'BAC-20260724-0001',
            'user_id' => 10,
            'window_id' => 1,
            'booking_date' => $bookingDate,
            'number_of_pets' => 1,
            'status' => 'waiting_to_arrive',
            'reschedule_count' => 0,
            'cancel_count' => 0,
        ]);

        $this->postJson('/api/booking/reschedule', [
            'booking_id' => 1,
            'new_date' => $bookingDate,
            'new_window_id' => 1,
        ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Choose a different date or time slot from the current schedule.',
            );

        $this->assertDatabaseHas('bookings', [
            'booking_id' => 1,
            'booking_date' => $bookingDate,
            'window_id' => 1,
            'reschedule_count' => 0,
        ]);
    }

    public function test_customer_reschedule_notification_includes_previous_and_new_schedules(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-22 08:00:00'));
        $this->authenticateAs('customer', 10);
        $bookingDate = now()->toDateString();

        DB::table('clinic_settings')->insert([
            'id' => 1,
            'groomers_on_duty' => 2,
            'clinic_open_time' => '08:00:00',
            'clinic_close_time' => '23:00:00',
            'clinic_prereg_cutoff_time' => '23:00:00',
            'grooming_open_time' => '08:00:00',
            'grooming_close_time' => '23:00:00',
            'grooming_prereg_cutoff_time' => '23:00:00',
        ]);
        DB::table('time_windows')->insert([
            [
                'window_id' => 2,
                'window_label' => '2',
                'start_time' => '14:00:00',
                'end_time' => '15:00:00',
                'max_slots' => 4,
                'is_active' => true,
            ],
            [
                'window_id' => 3,
                'window_label' => '3',
                'start_time' => '22:00:00',
                'end_time' => '23:00:00',
                'max_slots' => 4,
                'is_active' => true,
            ],
        ]);
        DB::table('bookings')->insert([
            'booking_id' => 1,
            'booking_reference' => 'BAC-20260822-0001',
            'user_id' => 10,
            'window_id' => 2,
            'booking_date' => $bookingDate,
            'number_of_pets' => 1,
            'status' => 'waiting_to_arrive',
            'reschedule_count' => 0,
            'cancel_count' => 0,
        ]);

        $this->postJson('/api/booking/reschedule', [
            'booking_id' => 1,
            'new_date' => $bookingDate,
            'new_window_id' => 3,
        ])
            ->assertOk()
            ->assertJsonPath('booking.window', '10:00 PM - 11:00 PM');

        $this->assertDatabaseHas('bookings', [
            'booking_id' => 1,
            'booking_date' => $bookingDate,
            'window_id' => 3,
            'reschedule_count' => 1,
        ]);
        $this->assertDatabaseHas('notifications', [
            'booking_id' => 1,
            'type' => 'rescheduled',
            'message' => 'Pre-registration BAC-20260822-0001 was rescheduled by Customer User from Aug 22 at 2:00 PM - 3:00 PM to Aug 22 at 10:00 PM - 11:00 PM.',
            'is_read' => false,
        ]);
    }

    public function test_customer_reschedule_uses_the_three_day_pre_registration_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-23 07:00:00'));
        $this->authenticateAs('customer', 10);

        DB::table('bookings')->insert([
            'booking_id' => 1,
            'booking_reference' => 'BAC-20260724-0001',
            'user_id' => 10,
            'window_id' => 1,
            'booking_date' => now()->addDay()->toDateString(),
            'number_of_pets' => 1,
            'status' => 'waiting_to_arrive',
            'reschedule_count' => 0,
            'cancel_count' => 0,
        ]);

        $this->postJson('/api/booking/reschedule', [
            'booking_id' => 1,
            'new_date' => now()->addDays(3)->toDateString(),
            'new_window_id' => 1,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('new_date')
            ->assertJsonPath(
                'errors.new_date.0',
                'Grooming can be pre-registered up to three days in advance.',
            );
    }

    public function test_customer_reschedule_rechecks_clinic_closures(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-23 07:00:00'));
        $this->authenticateAs('customer', 10);
        $currentDate = now()->toDateString();
        $blockedDate = now()->addDay()->toDateString();

        DB::table('bookings')->insert([
            'booking_id' => 1,
            'booking_reference' => 'BAC-20260723-0001',
            'user_id' => 10,
            'window_id' => 1,
            'booking_date' => $currentDate,
            'number_of_pets' => 1,
            'status' => 'waiting_to_arrive',
            'reschedule_count' => 0,
            'cancel_count' => 0,
        ]);
        DB::table('clinic_closures')->insert([
            'type' => 'blocked_date',
            'start_date' => $blockedDate,
            'end_date' => $blockedDate,
            'reason' => 'Grooming team training',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/booking/reschedule', [
            'booking_id' => 1,
            'new_date' => $blockedDate,
            'new_window_id' => 1,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Grooming team training');

        $this->assertDatabaseHas('bookings', [
            'booking_id' => 1,
            'booking_date' => $currentDate,
            'window_id' => 1,
            'reschedule_count' => 0,
        ]);
    }

    #[DataProvider('authorizedClinicRoles')]
    public function test_authorized_roles_upload_new_attachments_to_private_storage(string $role): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->authenticateAs($role);
        $this->insertClinicAppointment(status: 'in_consultation');

        $response = $this->post('/api/admin/clinic-appointments/1/attachments', [
            'file' => UploadedFile::fake()->create('laboratory-result.pdf', 200, 'application/pdf'),
            'label' => 'Laboratory result',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('attachment.file_name', 'laboratory-result.pdf')
            ->assertJsonPath('attachment.file_type', 'application/pdf')
            ->assertJsonPath(
                'attachment.download_endpoint',
                '/api/admin/clinic-appointments/1/attachments/1/download',
            );

        $metadata = $response->json('attachment');
        $this->assertArrayNotHasKey('file_path', $metadata);
        $this->assertArrayNotHasKey('file_url', $metadata);
        $this->assertStringNotContainsString(storage_path(), json_encode($metadata));
        $this->assertStringNotContainsString('/storage/', json_encode($metadata));

        $storedPath = DB::table('clinic_attachments')->value('file_path');
        $this->assertNotNull($storedPath);
        Storage::disk('local')->assertExists($storedPath);
        Storage::disk('public')->assertMissing($storedPath);
    }

    public static function authorizedClinicRoles(): array
    {
        return [
            'staff' => ['staff'],
            'administrator' => ['admin'],
        ];
    }

    #[DataProvider('authorizedClinicRoles')]
    public function test_authorized_roles_can_download_the_scoped_private_attachment(string $role): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->authenticateAs($role);
        $this->insertClinicAppointment(status: 'in_consultation');
        $attachmentId = $this->createAttachment(
            1,
            'clinic/attachments/1/scoped-document.pdf',
            'private clinic document',
        );

        $response = $this->get("/api/admin/clinic-appointments/1/attachments/{$attachmentId}/download");

        $response
            ->assertOk()
            ->assertDownload('scoped-document.pdf')
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertSame('private clinic document', $response->streamedContent());
        $this->assertStringNotContainsString('clinic/attachments/1', (string) $response->headers->get('content-disposition'));
    }

    public function test_download_returns_the_same_not_found_response_for_wrong_or_missing_attachments(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->authenticateAs('staff');
        $this->insertClinicAppointment(status: 'in_consultation', id: 1);
        $this->insertClinicAppointment(status: 'in_consultation', id: 2);
        $attachmentId = $this->createAttachment(
            2,
            'clinic/attachments/2/appointment-two.pdf',
            'appointment two',
        );

        $expected = [
            'success' => false,
            'message' => 'Attachment not found.',
        ];

        $this->getJson("/api/admin/clinic-appointments/1/attachments/{$attachmentId}/download")
            ->assertNotFound()
            ->assertExactJson($expected);
        $this->getJson('/api/admin/clinic-appointments/1/attachments/999/download')
            ->assertNotFound()
            ->assertExactJson($expected);
        $this->getJson("/api/admin/clinic-appointments/999/attachments/{$attachmentId}/download")
            ->assertNotFound()
            ->assertExactJson($expected);

        $this->assertDatabaseHas('clinic_attachments', ['id' => $attachmentId]);
        Storage::disk('local')->assertExists('clinic/attachments/2/appointment-two.pdf');
    }

    public function test_deletion_is_scoped_to_the_appointment_and_preserves_the_other_attachment(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->authenticateAs('staff');
        $this->insertClinicAppointment(status: 'in_consultation', id: 1);
        $this->insertClinicAppointment(status: 'in_consultation', id: 2);
        $attachmentA = $this->createAttachment(
            1,
            'clinic/attachments/1/appointment-a.pdf',
            'appointment A',
        );
        $attachmentB = $this->createAttachment(
            2,
            'clinic/attachments/2/appointment-b.pdf',
            'appointment B',
        );

        $this->deleteJson("/api/admin/clinic-appointments/1/attachments/{$attachmentB}")
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Attachment not found.',
            ]);

        $this->assertDatabaseHas('clinic_attachments', ['id' => $attachmentA]);
        $this->assertDatabaseHas('clinic_attachments', ['id' => $attachmentB]);
        Storage::disk('local')->assertExists('clinic/attachments/1/appointment-a.pdf');
        Storage::disk('local')->assertExists('clinic/attachments/2/appointment-b.pdf');

        $this->deleteJson("/api/admin/clinic-appointments/1/attachments/{$attachmentA}")
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $this->assertDatabaseMissing('clinic_attachments', ['id' => $attachmentA]);
        $this->assertDatabaseHas('clinic_attachments', ['id' => $attachmentB]);
        Storage::disk('local')->assertMissing('clinic/attachments/1/appointment-a.pdf');
        Storage::disk('local')->assertExists('clinic/attachments/2/appointment-b.pdf');
    }

    public function test_correctly_scoped_attachment_record_can_be_deleted_when_its_file_is_already_missing(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->authenticateAs('admin');
        $this->insertClinicAppointment(status: 'in_consultation');
        $attachmentId = $this->createAttachment(
            1,
            'clinic/attachments/1/already-missing.pdf',
            'not stored',
            storeFile: false,
        );

        $this->deleteJson("/api/admin/clinic-appointments/1/attachments/{$attachmentId}")
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $this->assertDatabaseMissing('clinic_attachments', ['id' => $attachmentId]);
    }

    public function test_legacy_public_attachment_is_streamed_without_exposing_its_public_path(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->authenticateAs('staff');
        $this->insertClinicAppointment(status: 'in_consultation');
        $legacyPath = 'clinic/attachments/1/legacy-public.pdf';
        $attachmentId = $this->createAttachment(
            1,
            $legacyPath,
            'legacy public contents',
            disk: 'public',
        );

        $listing = $this->getJson('/api/admin/clinic-appointments')->assertOk();
        $metadata = $listing->json('in_consultation.0.record.attachments.0');

        $this->assertSame($attachmentId, $metadata['id']);
        $this->assertArrayNotHasKey('file_path', $metadata);
        $this->assertArrayNotHasKey('file_url', $metadata);
        $this->assertStringNotContainsString($legacyPath, json_encode($listing->json()));
        $this->assertStringNotContainsString('/storage/', json_encode($listing->json()));

        $download = $this->get("/api/admin/clinic-appointments/1/attachments/{$attachmentId}/download");
        $download->assertOk()->assertDownload('legacy-public.pdf');
        $this->assertSame('legacy public contents', $download->streamedContent());
    }

    #[DataProvider('lockedClinicalHistoryStatuses')]
    public function test_terminal_and_pre_arrival_appointments_reject_all_ordinary_clinical_mutations(
        string $status,
    ): void {
        Storage::fake('local');
        Storage::fake('public');
        $this->authenticateAs('staff');
        $this->insertClinicAppointment(status: $status);
        $attachmentId = $this->createAttachment(
            1,
            'clinic/attachments/1/locked-history.pdf',
            'preserved clinical attachment',
        );
        $recordId = DB::table('clinic_records')->where('clinic_appointment_id', 1)->value('id');
        DB::table('clinic_vitals')->insert([
            'clinic_appointment_id' => 1,
            'weight_kg' => 5.25,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('clinic_medications')->insert([
            'clinic_record_id' => $recordId,
            'drug_name' => 'Preserved medicine',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $message = 'Clinical records can only be modified while the appointment is Checked In, In Consultation, or For Payment.';
        $this->postJson('/api/admin/clinic-appointments/1/record', [
            'diagnosis' => 'Forbidden replacement',
            'weight_kg' => 9.99,
            'medications' => [['drug_name' => 'Forbidden medicine']],
        ])->assertConflict()->assertJsonPath('message', $message);
        $this->post('/api/admin/clinic-appointments/1/attachments', [
            'file' => UploadedFile::fake()->create('forbidden.pdf', 20, 'application/pdf'),
        ])->assertConflict()->assertJsonPath('message', $message);
        $this->deleteJson("/api/admin/clinic-appointments/1/attachments/{$attachmentId}")
            ->assertConflict()
            ->assertJsonPath('message', $message);

        $this->assertDatabaseHas('clinic_records', [
            'id' => $recordId,
            'chief_complaint' => 'Attachment test',
            'diagnosis' => null,
        ]);
        $this->assertDatabaseHas('clinic_vitals', [
            'clinic_appointment_id' => 1,
            'weight_kg' => 5.25,
        ]);
        $this->assertDatabaseHas('clinic_medications', [
            'clinic_record_id' => $recordId,
            'drug_name' => 'Preserved medicine',
        ]);
        $this->assertDatabaseHas('clinic_attachments', ['id' => $attachmentId]);
        Storage::disk('local')->assertExists('clinic/attachments/1/locked-history.pdf');
        Storage::disk('local')->assertMissing('clinic/attachments/1/forbidden.pdf');
    }

    public static function lockedClinicalHistoryStatuses(): array
    {
        return [
            'waiting to arrive' => ['waiting_to_arrive'],
            'completed' => ['completed'],
            'cancelled' => ['cancelled'],
            'no-show' => ['no_show'],
        ];
    }

    #[DataProvider('editableClinicalHistoryStatuses')]
    public function test_valid_clinical_stages_keep_record_vitals_medication_and_attachment_operations_available(
        string $status,
    ): void {
        Storage::fake('local');
        Storage::fake('public');
        $this->authenticateAs('admin');
        $this->insertClinicAppointment(status: $status);

        $this->postJson('/api/admin/clinic-appointments/1/record', [
            'diagnosis' => 'Permitted diagnosis',
            'weight_kg' => 6.75,
            'medications' => [['drug_name' => 'Permitted medicine']],
        ])
            ->assertOk()
            ->assertJsonPath('record.diagnosis', 'Permitted diagnosis')
            ->assertJsonPath('record.medications.0.drug_name', 'Permitted medicine');

        $upload = $this->post('/api/admin/clinic-appointments/1/attachments', [
            'file' => UploadedFile::fake()->create('permitted.pdf', 20, 'application/pdf'),
            'label' => 'Permitted attachment',
        ])->assertOk();
        $attachmentId = $upload->json('attachment.id');

        $this->deleteJson("/api/admin/clinic-appointments/1/attachments/{$attachmentId}")
            ->assertOk();
        $this->assertDatabaseCount('clinic_records', 1);
        $this->assertDatabaseCount('clinic_vitals', 1);
        $this->assertDatabaseCount('clinic_medications', 1);
        $this->assertDatabaseCount('clinic_attachments', 0);
    }

    public static function editableClinicalHistoryStatuses(): array
    {
        return [
            'checked in' => ['checked_in'],
            'in consultation' => ['in_consultation'],
            'for payment' => ['for_payment'],
        ];
    }

    public function test_every_clinic_administration_route_has_the_expected_role_middleware(): void
    {
        $expected = [
            ['POST', 'api/admin/clinic-walk-in', 'role:admin,staff'],
            ['GET', 'api/admin/clinic-records', 'role:admin,staff'],
            ['GET', 'api/admin/clinic-cases', 'role:admin,staff'],
            ['POST', 'api/admin/clinic-cases', 'role:admin,staff'],
            ['POST', 'api/admin/clinic-cases/{id}/start', 'role:admin,staff'],
            ['POST', 'api/admin/clinic-cases/{id}/finish', 'role:admin,staff'],
            ['GET', 'api/admin/clinic-appointments', 'role:admin,staff'],
            ['GET', 'api/admin/clinic-appointments/archived', 'role:admin,staff'],
            ['POST', 'api/admin/clinic-appointments/{id}/check-in', 'role:admin,staff'],
            ['POST', 'api/admin/clinic-appointments/{id}/start-consultation', 'role:admin,staff'],
            ['POST', 'api/admin/clinic-appointments/{id}/finish-consultation', 'role:admin,staff'],
            ['POST', 'api/admin/clinic-appointments/{id}/pay', 'role:admin,staff'],
            ['POST', 'api/admin/clinic-appointments/{id}/cancel', 'role:admin,staff'],
            ['POST', 'api/admin/clinic-appointments/{id}/record', 'role:admin,staff'],
            ['POST', 'api/admin/clinic-appointments/{id}/attachments', 'role:admin,staff'],
            ['GET', 'api/admin/clinic-appointments/{id}/attachments/{attachmentId}/download', 'role:admin,staff'],
            ['DELETE', 'api/admin/clinic-appointments/{id}/attachments/{attachmentId}', 'role:admin,staff'],
            ['POST', 'api/admin/clinic/stop-today', 'role:admin'],
            ['POST', 'api/admin/clinic/reopen-today', 'role:admin'],
            ['GET', 'api/admin/clinic/blocked-dates', 'role:admin,staff'],
            ['POST', 'api/admin/clinic/blocked-dates', 'role:admin'],
            ['DELETE', 'api/admin/clinic/blocked-dates/{id}', 'role:admin'],
            ['GET', 'api/admin/clinic/settings/availability', 'role:admin,staff'],
            ['PATCH', 'api/admin/clinic/settings/availability', 'role:admin'],
            ['PATCH', 'api/admin/clinic/settings/groomers-on-duty', 'role:admin,staff'],
        ];

        $routes = collect(Route::getRoutes()->getRoutes());

        foreach ($expected as [$method, $uri, $roleMiddleware]) {
            $route = $routes->first(
                fn (RoutingRoute $route) => $route->uri() === $uri && in_array($method, $route->methods(), true),
            );

            $this->assertNotNull($route, "Expected clinic route [{$method} {$uri}] is not registered.");
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
            $this->assertContains($roleMiddleware, $route->gatherMiddleware());
        }

        $allClinicAdministrationRoutes = $routes->filter(
            fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/admin/clinic'),
        );

        $this->assertCount(count($expected), $allClinicAdministrationRoutes);

        $adminClinicRoutes = $routes->filter(
            fn (RoutingRoute $route) => str_starts_with($route->getActionName(), AdminClinicController::class.'@'),
        );

        $this->assertCount(16, $adminClinicRoutes);
        $adminClinicRoutes->each(function (RoutingRoute $route): void {
            $this->assertContains('role:admin,staff', $route->gatherMiddleware());
        });
    }

    public function test_customer_pet_profile_and_grooming_history_apis_remain_available(): void
    {
        $this->authenticateAs('customer', 10);
        DB::table('pets')->insert([
            'pet_id' => 101,
            'user_id' => 10,
            'pet_name' => 'Mochi',
            'species' => 'cat',
            'is_archived' => false,
            'created_at' => now(),
        ]);

        $this->getJson('/api/pets')->assertOk();
        $this->getJson('/api/pets/101')
            ->assertOk()
            ->assertJsonPath('pet.pet_name', 'Mochi');
        $this->getJson('/api/booking/history?pet_id=101')
            ->assertOk()
            ->assertJsonCount(0, 'bookings')
            ->assertJsonCount(0, 'history');
    }

    private function authenticateAs(string $role, int $userId = 1): void
    {
        $user = new User;
        $user->forceFill([
            'user_id' => $userId,
            'first_name' => ucfirst($role),
            'last_name' => 'User',
            'role' => $role,
        ]);
        $user->exists = true;

        Sanctum::actingAs($user, ['*']);
    }

    private function insertClinicAppointment(
        string $status,
        int $id = 1,
        ?string $reference = null,
    ): void {
        DB::table('clinic_appointments')->insert([
            'id' => $id,
            'appointment_reference' => $reference ?? 'CL-20260722-'.str_pad((string) $id, 3, '0', STR_PAD_LEFT),
            'appointment_type' => 'walk_in',
            'status' => $status,
            'queue_number' => $id,
            'appointment_date' => now()->toDateString(),
            'chief_complaint' => 'Routine consultation',
            'paid' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createAttachment(
        int $appointmentId,
        string $path,
        string $contents,
        string $disk = 'local',
        bool $storeFile = true,
    ): int {
        $recordId = DB::table('clinic_records')
            ->where('clinic_appointment_id', $appointmentId)
            ->value('id');

        if (! $recordId) {
            $recordId = DB::table('clinic_records')->insertGetId([
                'clinic_appointment_id' => $appointmentId,
                'chief_complaint' => 'Attachment test',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($storeFile) {
            Storage::disk($disk)->put($path, $contents);
        }

        return DB::table('clinic_attachments')->insertGetId([
            'clinic_record_id' => $recordId,
            'file_name' => basename($path),
            'file_path' => $path,
            'file_type' => 'application/pdf',
            'file_size_bytes' => strlen($contents),
            'label' => 'Test attachment',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
