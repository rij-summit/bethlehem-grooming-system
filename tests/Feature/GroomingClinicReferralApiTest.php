<?php

namespace Tests\Feature;

use App\Http\Controllers\GroomingClinicReferralController;
use App\Models\BookingPet;
use App\Models\CustomerNotification;
use App\Models\GroomingClinicReferral;
use App\Models\GroomingMedicalConcern;
use App\Models\GroomingMedicalConcernResponse;
use App\Models\User;
use App\Services\GroomingClinicReferralStatement;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GroomingClinicReferralApiTest extends TestCase
{
    private const STAFF_URI = '/api/admin/bookings/10/pets/100/medical-concerns/401/clinic-referral';

    private object $referralMigration;

    private object $notificationMigration;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-04 15:30:00'));
        DB::statement('PRAGMA foreign_keys = ON');
        $this->createSchema();
        $this->seedData();

        $this->referralMigration = require database_path(
            'migrations/2026_08_04_000004_create_grooming_clinic_referrals_table.php',
        );
        $this->notificationMigration = require database_path(
            'migrations/2026_08_04_000005_link_customer_notifications_to_grooming_clinic_referrals.php',
        );
        $this->referralMigration->up();
        $this->notificationMigration->up();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Schema::disableForeignKeyConstraints();

        foreach ([
            'customer_notifications',
            'grooming_clinic_referrals',
            'grooming_medical_concern_responses',
            'grooming_medical_concerns',
            'clinic_appointments',
            'booking_pets',
            'bookings',
            'walkins',
            'pets',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
        parent::tearDown();
    }

    public function test_routes_use_expected_authorization_and_controller(): void
    {
        $staffRoutes = [
            ['GET', 'api/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns/{concernId}/clinic-referral'],
            ['POST', 'api/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns/{concernId}/clinic-referral'],
            ['POST', 'api/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns/{concernId}/clinic-referral/in-person-consent'],
        ];
        $routes = collect(Route::getRoutes()->getRoutes());

        foreach ($staffRoutes as [$method, $uri]) {
            $route = $routes->first(fn (RoutingRoute $route) => $route->uri() === $uri
                && in_array($method, $route->methods(), true));
            $this->assertNotNull($route);
            $this->assertSame(GroomingClinicReferralController::class, $route->getControllerClass());
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
            $this->assertContains('role:admin,staff', $route->gatherMiddleware());
        }

        foreach ([
            ['GET', 'api/pets/{petId}/grooming-clinic-referrals/{publicId}'],
            ['POST', 'api/pets/{petId}/grooming-clinic-referrals/{publicId}/consent'],
        ] as [$method, $uri]) {
            $route = $routes->first(fn (RoutingRoute $route) => $route->uri() === $uri
                && in_array($method, $route->methods(), true));
            $this->assertNotNull($route);
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        }

        $this->postJson(self::STAFF_URI)->assertUnauthorized();
        $this->authenticateAs(4);
        $this->postJson(self::STAFF_URI)->assertForbidden();
    }

    public function test_staff_and_admin_create_routine_referral_with_snapshots_notification_and_no_workflow_side_effects(): void
    {
        foreach ([2, 1] as $staffId) {
            $this->authenticateAs($staffId);
            $concernId = $staffId === 2 ? 401 : 403;
            $bookingPetId = $staffId === 2 ? 100 : 101;
            $uri = "/api/admin/bookings/10/pets/{$bookingPetId}/medical-concerns/{$concernId}/clinic-referral";
            $before = DB::table('booking_pets')->where('booking_pet_id', $bookingPetId)->first();

            $response = $this->postJson($uri, $this->payload([
                'request_token' => '10000000-0000-4000-8000-'.str_pad(
                    (string) $staffId,
                    12,
                    '0',
                    STR_PAD_LEFT,
                ),
            ]))
                ->assertCreated()
                ->assertJsonPath('already_exists', false)
                ->assertJsonPath('referral.status', GroomingClinicReferral::STATUS_PENDING_CONSENT)
                ->assertJsonPath('referral.urgency', GroomingClinicReferral::URGENCY_ROUTINE)
                ->assertJsonPath('referral.consent_required', true)
                ->assertJsonPath('referral.future_clinic_acceptance_eligible', false)
                ->assertJsonPath('referral.financial_correction_review_required', false)
                ->assertJsonPath(
                    'notification.type',
                    CustomerNotification::TYPE_GROOMING_CLINIC_REFERRAL_REQUESTED,
                );

            $referral = GroomingClinicReferral::where('grooming_medical_concern_id', $concernId)->firstOrFail();
            $this->assertSame(4, $referral->owner_user_id_at_referral);
            $this->assertSame('Pet Owner', $referral->owner_name_at_referral);
            $this->assertSame($staffId, $referral->referred_by_user_id);
            $this->assertNotNull($referral->customer_notified_at);
            $this->assertNull($referral->clinic_appointment_id);
            $this->assertDatabaseHas('customer_notifications', [
                'user_id' => 4,
                'booking_id' => 10,
                'grooming_clinic_referral_id' => $referral->id,
                'type' => CustomerNotification::TYPE_GROOMING_CLINIC_REFERRAL_REQUESTED,
            ]);
            $notificationText = $response->json('notification.message');
            $this->assertStringContainsString(
                $bookingPetId === 100 ? 'Zeus' : 'Luna',
                $notificationText,
            );
            $this->assertStringNotContainsString('PRIVATE INTERNAL', $notificationText);
            $this->assertStringNotContainsString('override', strtolower($notificationText));

            $after = DB::table('booking_pets')->where('booking_pet_id', $bookingPetId)->first();
            $this->assertSame($before->grooming_state, $after->grooming_state);
            $this->assertSame($before->grooming_start_time, $after->grooming_start_time);
            $this->assertSame($before->grooming_end_time, $after->grooming_end_time);
        }

        $this->assertDatabaseCount('pets', 3);
        $this->assertDatabaseCount('users', 5);
        $this->assertDatabaseCount('clinic_appointments', 0);
    }

    public function test_urgency_initial_status_and_emergency_reason_validation(): void
    {
        $this->authenticateAs(2);

        $this->postJson(self::STAFF_URI, $this->payload([
            'urgency' => 'emergency',
            'emergency_without_consent_reason' => '   ',
        ]))->assertUnprocessable()->assertJsonValidationErrors(
            'emergency_without_consent_reason',
        );

        $urgent = $this->postJson(self::STAFF_URI, $this->payload([
            'urgency' => 'urgent',
        ]))->assertCreated()->json('referral');
        $this->assertSame('pending_consent', $urgent['status']);

        GroomingClinicReferral::query()->firstOrFail()->forceFill([
            'status' => GroomingClinicReferral::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ])->save();
        $concern = $this->createConcern([
            'id' => 404,
            'booking_pet_id' => 100,
            'public_id' => '44444444-4444-4444-8444-444444444444',
        ]);
        $urgentOverride = $this->postJson(
            "/api/admin/bookings/10/pets/100/medical-concerns/{$concern->id}/clinic-referral",
            $this->payload([
                'urgency' => 'urgent',
                'request_token' => '40000000-0000-4000-8000-000000000004',
                'emergency_without_consent_reason' => 'Immediate intake is needed for safety.',
            ]),
        )->assertCreated()->json('referral');
        $this->assertSame('pending_clinic_acceptance', $urgentOverride['status']);
        $this->assertTrue($urgentOverride['emergency_without_consent']);
    }

    public function test_nested_scoping_terminal_and_finished_context_are_rejected_generically_or_safely(): void
    {
        $this->authenticateAs(2);
        $payload = $this->payload();

        foreach ([
            '/api/admin/bookings/999/pets/100/medical-concerns/401/clinic-referral',
            '/api/admin/bookings/10/pets/999/medical-concerns/401/clinic-referral',
            '/api/admin/bookings/10/pets/101/medical-concerns/401/clinic-referral',
            '/api/admin/bookings/10/pets/100/medical-concerns/999/clinic-referral',
        ] as $uri) {
            $this->postJson($uri, $payload)
                ->assertNotFound()
                ->assertExactJson([
                    'success' => false,
                    'message' => 'Clinic referral context not found.',
                ]);
        }

        DB::table('grooming_medical_concerns')->where('id', 401)->update(['status' => 'resolved']);
        $this->postJson(self::STAFF_URI, $payload)->assertUnprocessable();

        DB::table('grooming_medical_concerns')->where('id', 401)->update(['status' => 'open']);
        DB::table('booking_pets')->where('booking_pet_id', 100)->update([
            'grooming_state' => 'finished',
            'grooming_end_time' => now(),
        ]);
        $this->postJson(self::STAFF_URI, $payload)->assertUnprocessable();
    }

    public function test_action_eligibility_is_exact_and_creation_never_applies_an_action(): void
    {
        $this->authenticateAs(2);

        $inProgress = $this->postJson(self::STAFF_URI, $this->payload())
            ->assertCreated()
            ->assertJsonPath('referral.exact_pause_or_stop_applied', false)
            ->assertJsonPath('referral.future_clinic_acceptance_eligible', false);
        $this->assertSame(
            'in_progress',
            DB::table('booking_pets')->where('booking_pet_id', 100)->first()->grooming_state,
        );

        $referral = GroomingClinicReferral::firstOrFail();
        $referral->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
        $concern = $this->createConcern([
            'id' => 404,
            'public_id' => '44444444-4444-4444-8444-444444444444',
            'applied_grooming_action' => GroomingMedicalConcern::ACTION_PAUSE_GROOMING,
            'action_applied_at' => now(),
            'action_applied_by_user_id' => 2,
        ]);
        DB::table('booking_pets')->where('booking_pet_id', 100)->update([
            'grooming_state' => BookingPet::GROOMING_STATE_PAUSED,
        ]);

        $this->postJson(
            "/api/admin/bookings/10/pets/100/medical-concerns/{$concern->id}/clinic-referral",
            $this->payload([
                'urgency' => 'emergency',
                'request_token' => '40000000-0000-4000-8000-000000000004',
                'emergency_without_consent_reason' => 'Safety intake required.',
            ]),
        )
            ->assertCreated()
            ->assertJsonPath('referral.exact_pause_or_stop_applied', true)
            ->assertJsonPath('referral.future_clinic_acceptance_eligible', true);

        $this->assertSame(
            GroomingMedicalConcern::ACTION_PAUSE_GROOMING,
            $concern->fresh()->applied_grooming_action,
        );
    }

    public function test_request_idempotency_and_one_active_referral_are_enforced(): void
    {
        $this->authenticateAs(2);
        $payload = $this->payload();

        $first = $this->postJson(self::STAFF_URI, $payload)
            ->assertCreated()
            ->json('referral.public_id');
        $this->postJson(self::STAFF_URI, $payload)
            ->assertOk()
            ->assertJsonPath('already_exists', true)
            ->assertJsonPath('referral.public_id', $first);
        $this->assertDatabaseCount('grooming_clinic_referrals', 1);
        $this->assertDatabaseCount('customer_notifications', 1);

        $this->postJson(self::STAFF_URI, array_merge($payload, [
            'customer_explanation' => 'Changed safe explanation.',
        ]))->assertConflict();

        $otherConcern = $this->createConcern([
            'id' => 404,
            'public_id' => '44444444-4444-4444-8444-444444444444',
        ]);
        $this->postJson(
            "/api/admin/bookings/10/pets/100/medical-concerns/{$otherConcern->id}/clinic-referral",
            $this->payload(['request_token' => '40000000-0000-4000-8000-000000000004']),
        )->assertConflict();
    }

    public function test_registered_owner_status_and_portal_approval_use_snapshot_not_current_pet_owner(): void
    {
        $referral = $this->createRoutineReferral();
        DB::table('pets')->where('pet_id', 1000)->update(['user_id' => 5]);

        $this->authenticateAs(4);
        $uri = "/api/pets/1000/grooming-clinic-referrals/{$referral->public_id}";
        $status = $this->getJson($uri)
            ->assertOk()
            ->assertJsonPath('referral.pet_name', 'Zeus')
            ->assertJsonPath('referral.consent_state', 'pending')
            ->assertJsonPath(
                'referral.consent_statement_version',
                GroomingClinicReferralStatement::CONSENT_VERSION,
            );
        $this->assertStringContainsString('initial veterinary examination or assessment', $status->json('referral.consent_statement'));
        $this->assertStringContainsString('does not automatically authorize diagnostic procedures', $status->json('referral.consent_statement'));

        $response = $this->postJson("{$uri}/consent", [
            'decision' => 'approved',
            'signature_name' => 'Pet Owner',
        ])
            ->assertCreated()
            ->assertJsonPath('already_recorded', false)
            ->assertJsonPath('referral.status', 'pending_clinic_acceptance')
            ->assertJsonPath('response.response_channel', 'portal');

        $record = GroomingMedicalConcernResponse::query()
            ->where('response_kind', 'clinic_referral_consent')
            ->firstOrFail();
        $this->assertSame(4, $record->responded_by_user_id);
        $this->assertNull($record->captured_by_user_id);
        $this->assertNull($record->captured_by_name);
        $this->assertSame($record->id, $referral->fresh()->consent_response_id);

        foreach ([
            'referral_reason', 'emergency_without_consent_reason',
            'referred_by_user_id', 'referred_by_name', 'signature_name',
            'captured_by_name', 'internal_resolution_notes',
        ] as $privateField) {
            $this->assertStringNotContainsString($privateField, $response->getContent());
        }

        $this->postJson("{$uri}/consent", [
            'decision' => 'approved',
            'signature_name' => 'Pet Owner',
        ])->assertOk()->assertJsonPath('already_recorded', true);
        $this->postJson("{$uri}/consent", [
            'decision' => 'declined',
            'signature_name' => 'Pet Owner',
        ])->assertConflict();
        $this->assertDatabaseCount('grooming_medical_concern_responses', 1);

        $this->authenticateAs(5);
        $this->getJson($uri)->assertNotFound();
        $this->postJson("{$uri}/consent", [
            'decision' => 'approved',
            'signature_name' => 'Other Customer',
        ])->assertNotFound();
    }

    public function test_declined_routine_cancels_but_declined_emergency_override_remains_pending_acceptance(): void
    {
        $routine = $this->createRoutineReferral();
        $this->authenticateAs(4);
        $this->postJson(
            "/api/pets/1000/grooming-clinic-referrals/{$routine->public_id}/consent",
            ['decision' => 'declined', 'signature_name' => 'Pet Owner'],
        )
            ->assertCreated()
            ->assertJsonPath('referral.status', 'cancelled')
            ->assertJsonPath(
                'referral.customer_cancellation_summary',
                'The clinic referral was cancelled because consent was declined.',
            );
        $this->assertSame(
            'Customer declined clinic-referral consent.',
            $routine->fresh()->cancellation_reason,
        );

        $this->authenticateAs(2);
        $emergency = $this->postJson(
            '/api/admin/bookings/10/pets/101/medical-concerns/403/clinic-referral',
            $this->payload([
                'urgency' => 'emergency',
                'request_token' => '30000000-0000-4000-8000-000000000003',
                'emergency_without_consent_reason' => 'Immediate safety intake.',
            ]),
        )->assertCreated()->json('referral.public_id');

        $this->authenticateAs(4);
        $this->postJson(
            "/api/pets/1001/grooming-clinic-referrals/{$emergency}/consent",
            ['decision' => 'declined', 'signature_name' => 'Pet Owner'],
        )
            ->assertCreated()
            ->assertJsonPath('referral.status', 'pending_clinic_acceptance')
            ->assertJsonPath('referral.consent_decision', 'declined');
    }

    public function test_unregistered_walkin_uses_in_person_consent_and_has_no_portal_access(): void
    {
        $this->authenticateAs(2);
        $uri = '/api/admin/bookings/20/pets/200/medical-concerns/402/clinic-referral';
        $created = $this->postJson($uri, $this->payload([
            'request_token' => '20000000-0000-4000-8000-000000000002',
        ]))->assertCreated();
        $publicId = $created->json('referral.public_id');
        $this->assertNull($created->json('notification'));

        $referral = GroomingClinicReferral::where('public_id', $publicId)->firstOrFail();
        $this->assertNull($referral->owner_user_id_at_referral);
        $this->assertSame('Maria Santos', $referral->owner_name_at_referral);
        $this->assertNull($referral->customer_notified_at);

        $this->postJson("{$uri}/in-person-consent", [
            'decision' => 'approved',
            'decision_maker_name' => 'Maria Santos',
            'signature_name' => 'Maria Santos',
        ])
            ->assertCreated()
            ->assertJsonPath('response.response_channel', 'in_person_staff_captured')
            ->assertJsonPath('response.responded_by_name', 'Maria Santos')
            ->assertJsonPath('response.captured_by_name', 'Staff Member')
            ->assertJsonPath('referral.status', 'pending_clinic_acceptance');

        $record = GroomingMedicalConcernResponse::where(
            'response_kind',
            'clinic_referral_consent',
        )->firstOrFail();
        $this->assertNull($record->responded_by_user_id);
        $this->assertSame(2, $record->captured_by_user_id);

        $this->authenticateAs(4);
        $this->getJson("/api/pets/1002/grooming-clinic-referrals/{$publicId}")
            ->assertNotFound();
    }

    public function test_staff_cannot_capture_registered_owner_consent_and_browser_cannot_set_statement(): void
    {
        $referral = $this->createRoutineReferral();
        $this->authenticateAs(2);
        $this->postJson(self::STAFF_URI.'/in-person-consent', [
            'decision' => 'approved',
            'decision_maker_name' => 'Pet Owner',
            'signature_name' => 'Pet Owner',
        ])->assertConflict();

        $this->authenticateAs(4);
        $this->postJson(
            "/api/pets/1000/grooming-clinic-referrals/{$referral->public_id}/consent",
            [
                'decision' => 'approved',
                'signature_name' => 'Pet Owner',
                'statement_text' => 'Browser controlled statement.',
                'statement_version' => 'browser-v1',
            ],
        )->assertUnprocessable()->assertJsonValidationErrors([
            'statement_text',
            'statement_version',
        ]);
        $this->assertDatabaseCount('grooming_medical_concern_responses', 0);
    }

    public function test_notification_api_uses_linked_referral_metadata_and_safe_destination(): void
    {
        $referral = $this->createRoutineReferral();
        $this->authenticateAs(4);

        $response = $this->getJson('/api/customer/notifications')
            ->assertOk()
            ->assertJsonPath('notifications.0.referral_public_id', $referral->public_id)
            ->assertJsonPath('notifications.0.pet_id', 1000)
            ->assertJsonPath('notifications.0.pet_name', 'Zeus');
        $this->assertStringContainsString('referral='.$referral->public_id, $response->json('notifications.0.destination'));
        $this->assertStringNotContainsString('PRIVATE INTERNAL', $response->getContent());
    }

    private function createRoutineReferral(): GroomingClinicReferral
    {
        $this->authenticateAs(2);
        $this->postJson(self::STAFF_URI, $this->payload())->assertCreated();

        return GroomingClinicReferral::query()->firstOrFail();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'urgency' => 'routine',
            'referral_reason' => 'PRIVATE INTERNAL grooming observation.',
            'customer_explanation' => 'We recommend an initial clinic assessment.',
            'request_token' => '10000000-0000-4000-8000-000000000001',
        ], $overrides);
    }

    private function createConcern(array $overrides = []): GroomingMedicalConcern
    {
        $concern = new GroomingMedicalConcern;
        $concern->forceFill(array_merge([
            'booking_id' => 10,
            'booking_pet_id' => 100,
            'pet_id' => 1000,
            'reported_by_user_id' => 2,
            'reported_by_name' => 'Staff Member',
            'reported_at' => now(),
            'category' => 'skin',
            'severity' => 'moderate',
            'internal_description' => 'Internal observation.',
            'customer_message' => 'Customer-safe concern message.',
            'recommended_grooming_action' => 'pause_grooming',
            'status' => 'open',
            'customer_response_status' => 'not_required',
        ], $overrides));
        $concern->save();

        return $concern;
    }

    private function authenticateAs(int $userId): void
    {
        Sanctum::actingAs(User::query()->findOrFail($userId), ['*']);
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('role');
            $table->string('password_hash')->nullable();
        });
        Schema::create('walkins', function (Blueprint $table) {
            $table->id();
            $table->string('fname')->nullable();
            $table->string('mname')->nullable();
            $table->string('lname')->nullable();
            $table->unsignedInteger('user_id')->nullable();
        });
        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('pet_name');
            $table->string('species')->nullable();
            $table->foreign('user_id')->references('user_id')->on('users')->nullOnDelete();
        });
        Schema::create('bookings', function (Blueprint $table) {
            $table->increments('booking_id');
            $table->string('booking_reference')->unique();
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedBigInteger('walkin_id')->nullable();
            $table->string('status');
            $table->boolean('paid')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->foreign('user_id')->references('user_id')->on('users')->nullOnDelete();
            $table->foreign('walkin_id')->references('id')->on('walkins')->nullOnDelete();
        });
        Schema::create('booking_pets', function (Blueprint $table) {
            $table->increments('booking_pet_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('pet_id')->nullable();
            $table->string('grooming_state', 30)->default('not_started');
            $table->timestamp('grooming_start_time')->nullable();
            $table->timestamp('grooming_end_time')->nullable();
            $table->unique(['booking_pet_id', 'booking_id', 'pet_id'], 'bp_concern_identity_uq');
            $table->foreign('booking_id')->references('booking_id')->on('bookings')->restrictOnDelete();
            $table->foreign('pet_id')->references('pet_id')->on('pets')->restrictOnDelete();
        });
        Schema::create('clinic_appointments', function (Blueprint $table) {
            $table->id();
            $table->string('appointment_reference')->unique();
        });
        Schema::create('grooming_medical_concerns', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('booking_pet_id');
            $table->unsignedInteger('pet_id');
            $table->unsignedInteger('reported_by_user_id')->nullable();
            $table->string('reported_by_name');
            $table->timestamp('reported_at');
            $table->string('category');
            $table->string('severity');
            $table->text('internal_description');
            $table->text('customer_message');
            $table->string('recommended_grooming_action');
            $table->string('applied_grooming_action')->nullable();
            $table->timestamp('action_applied_at')->nullable();
            $table->unsignedInteger('action_applied_by_user_id')->nullable();
            $table->string('status')->default('open');
            $table->boolean('acknowledgment_required')->default(false);
            $table->boolean('consent_required')->default(false);
            $table->string('customer_response_status')->default('not_required');
            $table->timestamps();
            $table->unique(['id', 'booking_id', 'booking_pet_id', 'pet_id'], 'gmc_review_context_uq');
            $table->foreign(['booking_pet_id', 'booking_id', 'pet_id'], 'gmc_booking_pet_fk')
                ->references(['booking_pet_id', 'booking_id', 'pet_id'])
                ->on('booking_pets')->restrictOnDelete();
        });
        Schema::create('grooming_medical_concern_responses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('concern_id');
            $table->unsignedInteger('responded_by_user_id')->nullable();
            $table->string('responded_by_name', 200);
            $table->string('response_channel', 30)->default('portal');
            $table->unsignedInteger('captured_by_user_id')->nullable();
            $table->string('captured_by_name', 200)->nullable();
            $table->string('response_kind', 30);
            $table->string('decision', 30);
            $table->text('statement_text');
            $table->string('statement_version', 100);
            $table->string('signature_name', 200)->nullable();
            $table->timestamp('responded_at');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['id', 'concern_id'], 'gmcr_ref_context_uq');
            $table->unique(['concern_id', 'response_kind'], 'gmcr_concern_kind_uq');
            $table->foreign('concern_id')->references('id')->on('grooming_medical_concerns')->restrictOnDelete();
            $table->foreign('responded_by_user_id')->references('user_id')->on('users')->nullOnDelete();
            $table->foreign('captured_by_user_id')->references('user_id')->on('users')->nullOnDelete();
        });
        Schema::create('customer_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('booking_id')->nullable();
            $table->unsignedBigInteger('grooming_medical_concern_id')->nullable();
            $table->string('type', 100);
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('user_id')->references('user_id')->on('users')->restrictOnDelete();
            $table->foreign('booking_id')->references('booking_id')->on('bookings')->restrictOnDelete();
        });
    }

    private function seedData(): void
    {
        DB::table('users')->insert([
            ['user_id' => 1, 'first_name' => 'Admin', 'last_name' => 'User', 'role' => 'admin'],
            ['user_id' => 2, 'first_name' => 'Staff', 'last_name' => 'Member', 'role' => 'staff'],
            ['user_id' => 3, 'first_name' => 'Customer', 'last_name' => 'Blocked', 'role' => 'customer'],
            ['user_id' => 4, 'first_name' => 'Pet', 'last_name' => 'Owner', 'role' => 'customer'],
            ['user_id' => 5, 'first_name' => 'Other', 'last_name' => 'Customer', 'role' => 'customer'],
        ]);
        DB::table('walkins')->insert([
            'id' => 1,
            'fname' => 'Maria',
            'lname' => 'Santos',
            'user_id' => null,
        ]);
        DB::table('pets')->insert([
            ['pet_id' => 1000, 'user_id' => 4, 'pet_name' => 'Zeus', 'species' => 'Dog'],
            ['pet_id' => 1001, 'user_id' => 4, 'pet_name' => 'Luna', 'species' => 'Cat'],
            ['pet_id' => 1002, 'user_id' => null, 'pet_name' => 'Buddy', 'species' => 'Dog'],
        ]);
        DB::table('bookings')->insert([
            ['booking_id' => 10, 'booking_reference' => 'BOOKING-10', 'user_id' => 4, 'walkin_id' => null, 'status' => 'in_progress'],
            ['booking_id' => 20, 'booking_reference' => 'BOOKING-20', 'user_id' => null, 'walkin_id' => 1, 'status' => 'in_progress'],
        ]);
        DB::table('booking_pets')->insert([
            ['booking_pet_id' => 100, 'booking_id' => 10, 'pet_id' => 1000, 'grooming_state' => 'in_progress', 'grooming_start_time' => now()],
            ['booking_pet_id' => 101, 'booking_id' => 10, 'pet_id' => 1001, 'grooming_state' => 'in_progress', 'grooming_start_time' => now()],
            ['booking_pet_id' => 200, 'booking_id' => 20, 'pet_id' => 1002, 'grooming_state' => 'in_progress', 'grooming_start_time' => now()],
        ]);
        $this->createConcern(['id' => 401]);
        $this->createConcern([
            'id' => 402,
            'booking_id' => 20,
            'booking_pet_id' => 200,
            'pet_id' => 1002,
            'public_id' => '22222222-2222-4222-8222-222222222222',
        ]);
        $this->createConcern([
            'id' => 403,
            'booking_pet_id' => 101,
            'pet_id' => 1001,
            'public_id' => '33333333-3333-4333-8333-333333333333',
        ]);
    }
}
