<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminClinicController;
use App\Models\User;
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
        });

        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id');
            $table->unsignedInteger('user_id')->nullable();
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
            $table->string('appointment_type')->default('grooming');
            $table->text('chief_complaint')->nullable();
            $table->timestamps();
        });

        Schema::create('clinic_appointments', function (Blueprint $table) {
            $table->id();
            $table->string('appointment_reference')->unique();
            $table->string('appointment_type')->default('walk_in');
            $table->string('status')->default('checked_in');
            $table->unsignedSmallInteger('queue_number')->nullable();
            $table->date('appointment_date');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedBigInteger('walkin_id')->nullable();
            $table->unsignedInteger('pet_id')->nullable();
            $table->text('chief_complaint')->nullable();
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
            $table->timestamps();
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
        Schema::dropIfExists('clinic_settings');
        Schema::dropIfExists('clinic_closures');
        Schema::dropIfExists('clinic_attachments');
        Schema::dropIfExists('clinic_medications');
        Schema::dropIfExists('clinic_vitals');
        Schema::dropIfExists('clinic_records');
        Schema::dropIfExists('clinic_appointments');
        Schema::dropIfExists('walkins');
        Schema::dropIfExists('pets');
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

    public function test_staff_can_create_a_clinic_walk_in(): void
    {
        $this->authenticateAs('staff');

        $this->postJson('/api/admin/clinic-walk-in', [
            'fname' => 'Maria',
            'lname' => 'Santos',
            'phone' => '09171234567',
            'pet_name' => 'Bantay',
            'species' => 'dog',
            'chief_complaint' => 'Routine consultation',
            'terms_agreed' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('status', 'checked_in')
            ->assertJsonPath('pet.name', 'Bantay');
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

    public function test_administrator_only_clinic_settings_remain_restricted_to_admin(): void
    {
        $this->authenticateAs('staff');

        $this->getJson('/api/admin/clinic/blocked-dates')
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_RESPONSE);

        $this->authenticateAs('admin');

        $this->getJson('/api/admin/clinic/blocked-dates')
            ->assertOk()
            ->assertJsonPath('success', true);
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

    public function test_every_clinic_administration_route_has_the_expected_role_middleware(): void
    {
        $expected = [
            ['POST', 'api/admin/clinic-walk-in', 'role:admin,staff'],
            ['GET', 'api/admin/clinic-appointments', 'role:admin,staff'],
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
            ['GET', 'api/admin/clinic/blocked-dates', 'role:admin'],
            ['POST', 'api/admin/clinic/blocked-dates', 'role:admin'],
            ['DELETE', 'api/admin/clinic/blocked-dates/{id}', 'role:admin'],
            ['PATCH', 'api/admin/clinic/settings/groomers-on-duty', 'role:admin'],
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

        $this->assertCount(10, $adminClinicRoutes);
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
