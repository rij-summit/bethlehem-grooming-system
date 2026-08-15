<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminGroomingClinicReferralController;
use App\Http\Controllers\GroomingClinicReferralController;
use App\Models\Booking;
use App\Models\BookingPet;
use App\Models\CustomerNotification;
use App\Models\GroomingClinicReferral;
use App\Models\GroomingMedicalConcern;
use App\Models\GroomingMedicalConcernResponse;
use App\Models\User;
use App\Services\GroomingClinicReferralAssessmentService;
use App\Services\GroomingClinicReferralStatement;
use App\Services\GroomingPaymentReadinessService;
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
            'clinic_closures',
            'clinic_settings',
            'booking_services',
            'booking_pets',
            'bookings',
            'time_windows',
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
            ['GET', 'api/admin/clinic-referrals'],
            ['GET', 'api/admin/clinic-referrals/{publicId}'],
            ['POST', 'api/admin/clinic-referrals/{publicId}/accept'],
        ] as [$method, $uri]) {
            $route = $routes->first(fn (RoutingRoute $route) => $route->uri() === $uri
                && in_array($method, $route->methods(), true));
            $this->assertNotNull($route);
            $this->assertSame(AdminGroomingClinicReferralController::class, $route->getControllerClass());
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
            $this->assertContains('role:admin,staff', $route->gatherMiddleware());
        }

        $this->getJson('/api/admin/clinic-referrals')->assertUnauthorized();
        $this->postJson(self::STAFF_URI)->assertUnauthorized();
        $this->authenticateAs(4);
        $this->getJson('/api/admin/clinic-referrals')->assertForbidden();

        foreach ([
            ['GET', 'api/pets/{petId}/grooming-clinic-referrals/{publicId}'],
            ['POST', 'api/pets/{petId}/grooming-clinic-referrals/{publicId}/consent'],
        ] as [$method, $uri]) {
            $route = $routes->first(fn (RoutingRoute $route) => $route->uri() === $uri
                && in_array($method, $route->methods(), true));
            $this->assertNotNull($route);
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        }

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
            ->assertJsonPath('referral.consent_responded_by_name', null)
            ->assertJsonPath('referral.consent_responded_at', null)
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

        $completedStatus = $this->getJson($uri)
            ->assertOk()
            ->assertJsonPath('referral.consent_responded_by_name', 'Pet Owner')
            ->assertJsonPath(
                'referral.consent_responded_at',
                $record->responded_at?->toIso8601String(),
            );

        foreach ([
            'referral_reason', 'emergency_without_consent_reason',
            'referred_by_user_id', 'referred_by_name', 'signature_name',
            'signature_hash', 'statement_hash', 'captured_by_name',
            'captured_by_user_id', 'internal_resolution_notes',
        ] as $privateField) {
            $this->assertStringNotContainsString($privateField, $response->getContent());
            $this->assertStringNotContainsString($privateField, $completedStatus->getContent());
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

    public function test_customer_consent_audit_fields_ignore_a_linked_non_referral_response_kind(): void
    {
        $referral = $this->createRoutineReferral();
        $response = GroomingMedicalConcernResponse::create([
            'concern_id' => $referral->grooming_medical_concern_id,
            'responded_by_user_id' => 4,
            'responded_by_name' => 'Pet Owner',
            'response_channel' => GroomingMedicalConcernResponse::CHANNEL_PORTAL,
            'response_kind' => GroomingMedicalConcernResponse::KIND_CONSENT,
            'decision' => GroomingMedicalConcernResponse::DECISION_APPROVED,
            'statement_text' => 'Unrelated grooming-action consent statement.',
            'statement_version' => 'grooming-action-v1',
            'signature_name' => 'Private Stored Signature',
            'responded_at' => now(),
        ]);
        $referral->forceFill(['consent_response_id' => $response->id])->save();

        $this->authenticateAs(4);
        $result = $this->getJson(
            "/api/pets/1000/grooming-clinic-referrals/{$referral->public_id}",
        )
            ->assertOk()
            ->assertJsonPath('referral.consent_state', 'pending')
            ->assertJsonPath('referral.consent_decision', null)
            ->assertJsonPath('referral.consent_responded_by_name', null)
            ->assertJsonPath('referral.consent_responded_at', null);

        $this->assertStringNotContainsString('Private Stored Signature', $result->getContent());
        $this->assertStringNotContainsString('grooming-action-v1', $result->getContent());
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

    public function test_owner_tracker_hides_only_after_no_unfinished_nonreferred_pet_remains(): void
    {
        DB::table('bookings')->where('booking_id', 10)->update([
            'booking_date' => now()->toDateString(),
        ]);
        $referral = $this->createRoutineReferral();

        $this->authenticateAs(4);
        $this->getJson('/api/booking/history')
            ->assertOk()
            ->assertJsonPath('bookings.0.booking_id', 10)
            ->assertJsonPath('bookings.0.show_grooming_tracker', true)
            ->assertJsonPath('bookings.0.pets.0.pet_name', 'Zeus')
            ->assertJsonPath('bookings.0.pets.0.grooming_status', 'referred_to_clinic')
            ->assertJsonPath('bookings.0.pets.0.clinic_referred', true)
            ->assertJsonPath('bookings.0.pets.0.active_in_grooming', false)
            ->assertJsonPath('bookings.0.pets.1.pet_name', 'Luna')
            ->assertJsonPath('bookings.0.pets.1.clinic_referred', false)
            ->assertJsonPath('bookings.0.pets.1.active_in_grooming', true);

        $this->getJson('/api/booking/grooming-capacity')
            ->assertOk()
            ->assertJsonPath('queue.active', 1)
            ->assertJsonPath('queue.queued', 0)
            ->assertJsonPath('queue.in_progress', 1);

        $notification = $this->getJson('/api/customer/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath(
                'notifications.0.type',
                CustomerNotification::TYPE_GROOMING_CLINIC_REFERRAL_REQUESTED,
            )
            ->assertJsonPath('notifications.0.pet_id', 1000)
            ->assertJsonPath('notifications.0.pet_name', 'Zeus');
        $this->assertStringContainsString('Zeus', $notification->json('notifications.0.message'));
        $this->assertStringNotContainsString('Luna', $notification->json('notifications.0.message'));

        DB::table('booking_pets')->where('booking_pet_id', 101)->update([
            'grooming_state' => BookingPet::GROOMING_STATE_FINISHED,
            'grooming_end_time' => now(),
        ]);

        $this->getJson('/api/booking/history')
            ->assertOk()
            ->assertJsonPath('bookings.0.show_grooming_tracker', false);

        $this->getJson('/api/booking/grooming-capacity')
            ->assertOk()
            ->assertJsonPath('queue.active', 0)
            ->assertJsonPath('queue.queued', 0)
            ->assertJsonPath('queue.in_progress', 0);

        $this->assertSame(1, DB::table('customer_notifications')
            ->where('grooming_clinic_referral_id', $referral->id)
            ->where('type', CustomerNotification::TYPE_GROOMING_CLINIC_REFERRAL_REQUESTED)
            ->count());
        $this->assertSame(0, DB::table('customer_notifications')
            ->where('type', 'grooming_tracker_removed')
            ->count());

        $this->authenticateAs(5);
        $this->getJson('/api/customer/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'notifications');
    }

    public function test_queue_sorts_by_urgency_and_whitelists_staff_safe_fields(): void
    {
        foreach ([
            [401, 100, 'routine', '10000000-0000-4000-8000-000000000001'],
            [403, 101, 'emergency', '30000000-0000-4000-8000-000000000003'],
        ] as [$concernId, $bookingPetId, $urgency, $token]) {
            DB::table('booking_pets')->where('booking_pet_id', $bookingPetId)->update([
                'grooming_state' => $urgency === 'routine' ? 'paused' : 'stopped',
            ]);
            DB::table('grooming_medical_concerns')->where('id', $concernId)->update([
                'applied_grooming_action' => $urgency === 'routine' ? 'pause_grooming' : 'stop_grooming',
                'action_applied_at' => now(),
                'action_applied_by_user_id' => 2,
            ]);
            $this->authenticateAs(2);
            $created = $this->postJson(
                "/api/admin/bookings/10/pets/{$bookingPetId}/medical-concerns/{$concernId}/clinic-referral",
                $this->payload([
                    'urgency' => $urgency,
                    'request_token' => $token,
                    'emergency_without_consent_reason' => $urgency === 'emergency'
                        ? 'Immediate safety intake is required.'
                        : null,
                ]),
            )->assertCreated();

            if ($urgency === 'routine') {
                $this->authenticateAs(4);
                $this->postJson(
                    "/api/pets/1000/grooming-clinic-referrals/{$created->json('referral.public_id')}/consent",
                    ['decision' => 'approved', 'signature_name' => 'Pet Owner'],
                )->assertCreated();
            }
        }

        $this->authenticateAs(2);
        $response = $this->getJson('/api/admin/clinic-referrals')
            ->assertOk()
            ->assertJsonPath('referrals.0.urgency', 'emergency')
            ->assertJsonPath('referrals.1.urgency', 'routine');

        foreach ([
            'signature_name', 'statement_text', 'statement_hash', 'password_hash',
            'emergency_without_consent_reason',
        ] as $privateField) {
            $this->assertStringNotContainsString($privateField, $response->getContent());
        }

        $this->getJson('/api/admin/clinic-referrals?urgency=emergency')
            ->assertOk()
            ->assertJsonCount(1, 'referrals')
            ->assertJsonPath('referrals.0.pet.name', 'Luna');
        $this->getJson('/api/admin/clinic-referrals?search=BOOKING-10')
            ->assertOk()
            ->assertJsonCount(2, 'referrals');
        $this->getJson('/api/admin/clinic-referrals/not-a-real-referral')
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Clinic referral not found.',
            ]);
    }

    public function test_approved_routine_acceptance_creates_one_same_day_appointment_and_safe_notification(): void
    {
        DB::table('booking_pets')->where('booking_pet_id', 100)->update([
            'grooming_state' => BookingPet::GROOMING_STATE_PAUSED,
        ]);
        DB::table('grooming_medical_concerns')->where('id', 401)->update([
            'applied_grooming_action' => GroomingMedicalConcern::ACTION_PAUSE_GROOMING,
            'action_applied_at' => now(),
            'action_applied_by_user_id' => 2,
        ]);
        $referral = $this->createRoutineReferral();

        $this->authenticateAs(4);
        $this->postJson(
            "/api/pets/1000/grooming-clinic-referrals/{$referral->public_id}/consent",
            ['decision' => 'approved', 'signature_name' => 'Pet Owner'],
        )->assertCreated();

        $beforePetCount = DB::table('pets')->count();
        $beforeUserCount = DB::table('users')->count();
        $beforeBookingPet = DB::table('booking_pets')->where('booking_pet_id', 100)->first();
        $this->authenticateAs(2);
        $accepted = $this->postJson(
            "/api/admin/clinic-referrals/{$referral->public_id}/accept",
        )
            ->assertCreated()
            ->assertJsonPath('already_accepted', false)
            ->assertJsonPath('referral.status', GroomingClinicReferral::STATUS_ACCEPTED)
            ->assertJsonPath('referral.clinic_appointment.status', 'checked_in')
            ->assertJsonPath('referral.clinic_appointment.queue_number', 1)
            ->assertJsonPath('referral.clinic_appointment.appointment_date', '2026-08-04')
            ->assertJsonPath('referral.accepted_by_name', 'Staff Member');

        $appointment = DB::table('clinic_appointments')->first();
        $this->assertSame('walk_in', $appointment->appointment_type);
        $this->assertSame('checked_in', $appointment->status);
        $this->assertSame(1000, $appointment->pet_id);
        $this->assertSame(4, $appointment->user_id);
        $this->assertNull($appointment->walkin_id);
        $this->assertSame('We recommend an initial clinic assessment.', $appointment->chief_complaint);
        $this->assertStringStartsWith('CL-20260804-', $appointment->appointment_reference);
        $this->assertSame($beforePetCount, DB::table('pets')->count());
        $this->assertSame($beforeUserCount, DB::table('users')->count());
        $this->assertSame('referred_to_clinic', DB::table('grooming_medical_concerns')->where('id', 401)->value('status'));
        $this->assertNull(DB::table('grooming_medical_concerns')->where('id', 401)->value('clinic_appointment_id'));
        $this->assertSame($beforeBookingPet->grooming_state, DB::table('booking_pets')->where('booking_pet_id', 100)->value('grooming_state'));
        $this->assertSame($beforeBookingPet->grooming_end_time, DB::table('booking_pets')->where('booking_pet_id', 100)->value('grooming_end_time'));

        $this->assertDatabaseHas('customer_notifications', [
            'user_id' => 4,
            'grooming_clinic_referral_id' => $referral->id,
            'type' => CustomerNotification::TYPE_GROOMING_CLINIC_REFERRAL_ACCEPTED,
        ]);
        $this->assertDatabaseCount('customer_notifications', 2);
        $notification = DB::table('customer_notifications')
            ->where('type', CustomerNotification::TYPE_GROOMING_CLINIC_REFERRAL_ACCEPTED)
            ->first();
        $this->assertSame(4, $notification->user_id);
        $this->assertStringContainsString('Zeus', $notification->message);
        $this->assertStringNotContainsString('Luna', $notification->message);
        $this->assertStringContainsString($appointment->appointment_reference, $notification->message);
        $this->assertStringNotContainsString('PRIVATE INTERNAL', $notification->message);

        $this->postJson("/api/admin/clinic-referrals/{$referral->public_id}/accept")
            ->assertOk()
            ->assertJsonPath('already_accepted', true)
            ->assertJsonPath('referral.clinic_appointment.reference', $appointment->appointment_reference);
        $this->assertDatabaseCount('clinic_appointments', 1);
        $this->assertDatabaseCount('customer_notifications', 2);

        $this->authenticateAs(4);
        $customer = $this->getJson(
            "/api/pets/1000/grooming-clinic-referrals/{$referral->public_id}",
        )
            ->assertOk()
            ->assertJsonPath('referral.clinic_accepted', true)
            ->assertJsonPath('referral.clinic_appointment_reference', $appointment->appointment_reference)
            ->assertJsonPath('referral.clinic_appointment_status', 'checked_in');
        $this->assertStringNotContainsString('queue_number', $customer->getContent());
        $this->assertStringNotContainsString('PRIVATE INTERNAL', $customer->getContent());

        $notifications = $this->getJson('/api/customer/notifications')
            ->assertOk();
        $this->assertSame(
            $referral->public_id,
            $notifications->json('notifications.0.referral_public_id'),
        );
        $this->assertStringContainsString(
            'referral='.$referral->public_id,
            $notifications->json('notifications.0.destination'),
        );
    }

    public function test_consent_and_applied_action_rules_block_acceptance_without_side_effects(): void
    {
        $referral = $this->createRoutineReferral();
        $this->authenticateAs(2);
        $this->postJson("/api/admin/clinic-referrals/{$referral->public_id}/accept")
            ->assertConflict();
        $this->assertDatabaseCount('clinic_appointments', 0);

        DB::table('booking_pets')->where('booking_pet_id', 100)->update([
            'grooming_state' => BookingPet::GROOMING_STATE_PAUSED,
        ]);
        DB::table('grooming_medical_concerns')->where('id', 401)->update([
            'applied_grooming_action' => GroomingMedicalConcern::ACTION_PAUSE_GROOMING,
            'action_applied_at' => now(),
            'action_applied_by_user_id' => 2,
        ]);
        $this->postJson("/api/admin/clinic-referrals/{$referral->public_id}/accept")
            ->assertConflict()
            ->assertJsonPath('message', 'Clinic-referral consent is still pending.');

        DB::table('clinic_appointments')->insert([
            'appointment_reference' => 'CL-20260804-999',
            'appointment_type' => 'walk_in',
            'status' => 'checked_in',
            'queue_number' => 99,
            'appointment_date' => '2026-08-04',
            'pet_id' => 1000,
            'total_amount' => 0,
            'paid' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $referral->forceFill([
            'status' => GroomingClinicReferral::STATUS_PENDING_CLINIC_ACCEPTANCE,
        ])->save();
        $this->postJson("/api/admin/clinic-referrals/{$referral->public_id}/accept")
            ->assertConflict();
        $this->assertDatabaseCount('clinic_appointments', 1);
    }

    public function test_emergency_unregistered_acceptance_reuses_walkin_and_exact_pet(): void
    {
        DB::table('booking_pets')->where('booking_pet_id', 200)->update([
            'grooming_state' => BookingPet::GROOMING_STATE_STOPPED,
        ]);
        DB::table('grooming_medical_concerns')->where('id', 402)->update([
            'applied_grooming_action' => GroomingMedicalConcern::ACTION_STOP_GROOMING,
            'action_applied_at' => now(),
            'action_applied_by_user_id' => 2,
        ]);
        $this->authenticateAs(2);
        $publicId = $this->postJson(
            '/api/admin/bookings/20/pets/200/medical-concerns/402/clinic-referral',
            $this->payload([
                'urgency' => 'emergency',
                'request_token' => '20000000-0000-4000-8000-000000000002',
                'emergency_without_consent_reason' => 'Immediate safety intake.',
            ]),
        )->assertCreated()->json('referral.public_id');

        $this->postJson("/api/admin/clinic-referrals/{$publicId}/accept")
            ->assertCreated()
            ->assertJsonPath('referral.pet.pet_id', 1002)
            ->assertJsonPath('referral.clinic_appointment.status', 'checked_in');

        $appointment = DB::table('clinic_appointments')->first();
        $this->assertSame(1002, $appointment->pet_id);
        $this->assertSame(1, $appointment->walkin_id);
        $this->assertNull($appointment->user_id);
        $this->assertDatabaseCount('walkins', 1);
        $this->assertDatabaseCount('pets', 3);
        $this->assertDatabaseCount('users', 5);
        $this->assertDatabaseCount('customer_notifications', 0);

        $this->postJson("/api/admin/clinic-referrals/{$publicId}/accept")
            ->assertOk()
            ->assertJsonPath('already_accepted', true);
        $this->assertDatabaseCount('walkins', 1);
        $this->assertDatabaseCount('clinic_appointments', 1);
    }

    public function test_urgent_override_preserves_decline_and_admin_can_accept(): void
    {
        DB::table('booking_pets')->where('booking_pet_id', 101)->update([
            'grooming_state' => BookingPet::GROOMING_STATE_STOPPED,
        ]);
        DB::table('grooming_medical_concerns')->where('id', 403)->update([
            'applied_grooming_action' => GroomingMedicalConcern::ACTION_STOP_GROOMING,
            'action_applied_at' => now(),
            'action_applied_by_user_id' => 2,
        ]);
        $this->authenticateAs(2);
        $publicId = $this->postJson(
            '/api/admin/bookings/10/pets/101/medical-concerns/403/clinic-referral',
            $this->payload([
                'urgency' => 'urgent',
                'request_token' => '30000000-0000-4000-8000-000000000003',
                'emergency_without_consent_reason' => 'Documented urgent intake is required.',
            ]),
        )->assertCreated()->json('referral.public_id');

        $this->authenticateAs(4);
        $this->postJson(
            "/api/pets/1001/grooming-clinic-referrals/{$publicId}/consent",
            ['decision' => 'declined', 'signature_name' => 'Pet Owner'],
        )
            ->assertCreated()
            ->assertJsonPath('referral.status', 'pending_clinic_acceptance')
            ->assertJsonPath('referral.consent_decision', 'declined');

        $this->authenticateAs(1);
        DB::table('bookings')->where('booking_id', 10)->update(['paid' => true]);
        $this->getJson('/api/admin/clinic-referrals')->assertOk();
        $this->postJson("/api/admin/clinic-referrals/{$publicId}/accept")
            ->assertCreated()
            ->assertJsonPath('referral.consent_decision', 'declined')
            ->assertJsonPath('referral.status', 'accepted')
            ->assertJsonPath('referral.accepted_by_name', 'Admin User')
            ->assertJsonPath('referral.financial_correction_review_required', true);

        $this->assertSame(
            GroomingMedicalConcern::CUSTOMER_RESPONSE_NOT_REQUIRED,
            DB::table('grooming_medical_concerns')->where('id', 403)->value('customer_response_status'),
        );
        $this->assertTrue((bool) DB::table('bookings')->where('booking_id', 10)->value('paid'));
    }

    public function test_routine_closure_blocks_acceptance_but_does_not_mutate_workflow(): void
    {
        DB::table('booking_pets')->where('booking_pet_id', 100)->update([
            'grooming_state' => BookingPet::GROOMING_STATE_PAUSED,
        ]);
        DB::table('grooming_medical_concerns')->where('id', 401)->update([
            'applied_grooming_action' => GroomingMedicalConcern::ACTION_PAUSE_GROOMING,
            'action_applied_at' => now(),
            'action_applied_by_user_id' => 2,
        ]);
        $referral = $this->createRoutineReferral();
        $this->authenticateAs(4);
        $this->postJson(
            "/api/pets/1000/grooming-clinic-referrals/{$referral->public_id}/consent",
            ['decision' => 'approved', 'signature_name' => 'Pet Owner'],
        )->assertCreated();
        DB::table('clinic_closures')->insert([
            'type' => 'blocked_date',
            'start_date' => '2026-08-04',
            'end_date' => '2026-08-04',
            'reason' => 'Closed for maintenance.',
            'is_active' => true,
        ]);

        $this->authenticateAs(2);
        $this->postJson("/api/admin/clinic-referrals/{$referral->public_id}/accept")
            ->assertConflict()
            ->assertJsonPath('message', 'The clinic is closed or blocked for the effective clinic date.');

        $this->assertDatabaseCount('clinic_appointments', 0);
        $this->assertSame('pending_clinic_acceptance', $referral->fresh()->status);
        $this->assertSame('open', DB::table('grooming_medical_concerns')->where('id', 401)->value('status'));
        $this->assertSame('paused', DB::table('booking_pets')->where('booking_pet_id', 100)->value('grooming_state'));
        $this->assertDatabaseCount('customer_notifications', 1);
    }

    public function test_closed_concern_inactive_booking_and_finished_pet_each_block_acceptance(): void
    {
        DB::table('booking_pets')->where('booking_pet_id', 100)->update([
            'grooming_state' => BookingPet::GROOMING_STATE_PAUSED,
        ]);
        DB::table('grooming_medical_concerns')->where('id', 401)->update([
            'applied_grooming_action' => GroomingMedicalConcern::ACTION_PAUSE_GROOMING,
            'action_applied_at' => now(),
            'action_applied_by_user_id' => 2,
        ]);
        $referral = $this->createRoutineReferral();
        $this->authenticateAs(4);
        $this->postJson(
            "/api/pets/1000/grooming-clinic-referrals/{$referral->public_id}/consent",
            ['decision' => 'approved', 'signature_name' => 'Pet Owner'],
        )->assertCreated();
        $this->authenticateAs(2);

        DB::table('grooming_medical_concerns')->where('id', 401)->update(['status' => 'resolved']);
        $this->postJson("/api/admin/clinic-referrals/{$referral->public_id}/accept")
            ->assertConflict();
        DB::table('grooming_medical_concerns')->where('id', 401)->update(['status' => 'open']);

        DB::table('bookings')->where('booking_id', 10)->update(['status' => 'released']);
        $this->postJson("/api/admin/clinic-referrals/{$referral->public_id}/accept")
            ->assertConflict();
        DB::table('bookings')->where('booking_id', 10)->update(['status' => 'in_progress']);

        DB::table('booking_pets')->where('booking_pet_id', 100)->update([
            'grooming_state' => 'finished',
            'grooming_end_time' => now(),
        ]);
        $this->postJson("/api/admin/clinic-referrals/{$referral->public_id}/accept")
            ->assertConflict();

        $this->assertDatabaseCount('clinic_appointments', 0);
        $this->assertDatabaseCount('customer_notifications', 1);
        $this->assertSame('pending_clinic_acceptance', $referral->fresh()->status);
    }

    public function test_ordinary_consultation_start_and_finish_remain_unchanged(): void
    {
        DB::table('clinic_appointments')->insert([
            'id' => 90,
            'appointment_reference' => 'CL-20260804-090',
            'appointment_type' => 'walk_in',
            'status' => 'checked_in',
            'queue_number' => 90,
            'appointment_date' => '2026-08-04',
            'pet_id' => 1000,
            'paid' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->authenticateAs(2);

        $this->postJson('/api/admin/clinic-appointments/90/start-consultation')
            ->assertOk()
            ->assertJsonPath('referral_linked', false)
            ->assertJsonPath('appointment.status', 'in_consultation');

        $this->postJson('/api/admin/clinic-appointments/90/finish-consultation')
            ->assertOk()
            ->assertJsonPath('referral_linked', false)
            ->assertJsonPath('appointment.status', 'for_payment');
    }

    public function test_referral_consultation_requires_explicit_exact_stop_and_start_is_idempotent(): void
    {
        [$referral, $appointmentId] = $this->acceptRoutineReferralForAssessment(
            GroomingMedicalConcern::ACTION_PAUSE_GROOMING,
        );
        $siblingBefore = DB::table('booking_pets')->where('booking_pet_id', 101)->first();
        $this->authenticateAs(2);

        $this->postJson("/api/admin/clinic-appointments/{$appointmentId}/start-consultation")
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Stop Grooming must be applied to this pet before the clinic consultation can begin.',
            );

        $this->postJson(
            '/api/admin/bookings/10/pets/100/medical-concerns/401/apply-recommended-action',
            ['clinic_transfer_stop' => true],
        )
            ->assertOk()
            ->assertJsonPath('clinic_transfer_stop_applied', true);

        $started = $this->postJson(
            "/api/admin/clinic-appointments/{$appointmentId}/start-consultation",
        )
            ->assertOk()
            ->assertJsonPath('referral_linked', true)
            ->assertJsonPath('already_synchronized', false)
            ->assertJsonPath('appointment.status', 'in_consultation');

        $this->assertSame('under_clinic_review', $referral->fresh()->status);
        $this->assertSame(
            GroomingMedicalConcern::STATUS_UNDER_CLINIC_REVIEW,
            DB::table('grooming_medical_concerns')->where('id', 401)->value('status'),
        );
        $this->assertSame(
            BookingPet::GROOMING_STATE_STOPPED,
            DB::table('booking_pets')->where('booking_pet_id', 100)->value('grooming_state'),
        );
        $this->assertNull(
            DB::table('booking_pets')->where('booking_pet_id', 100)->value('grooming_end_time'),
        );
        $siblingAfter = DB::table('booking_pets')->where('booking_pet_id', 101)->first();
        $this->assertSame($siblingBefore->grooming_state, $siblingAfter->grooming_state);
        $this->assertSame($siblingBefore->grooming_start_time, $siblingAfter->grooming_start_time);
        $this->assertDatabaseHas('customer_notifications', [
            'grooming_clinic_referral_id' => $referral->id,
            'type' => CustomerNotification::TYPE_GROOMING_CLINIC_ASSESSMENT_STARTED,
        ]);
        $assessmentStartedNotification = DB::table('customer_notifications')
            ->where('grooming_clinic_referral_id', $referral->id)
            ->where('type', CustomerNotification::TYPE_GROOMING_CLINIC_ASSESSMENT_STARTED)
            ->first();
        $this->assertSame(4, $assessmentStartedNotification->user_id);
        $this->assertStringContainsString('Zeus', $assessmentStartedNotification->message);
        $this->assertStringNotContainsString('Luna', $assessmentStartedNotification->message);
        $started->assertJsonPath('appointment.grooming_referral.grooming_outcome', 'stopped');

        $this->postJson("/api/admin/clinic-appointments/{$appointmentId}/start-consultation")
            ->assertOk()
            ->assertJsonPath('already_synchronized', true);
        $this->assertSame(1, DB::table('customer_notifications')
            ->where('grooming_clinic_referral_id', $referral->id)
            ->where('type', CustomerNotification::TYPE_GROOMING_CLINIC_ASSESSMENT_STARTED)
            ->count());

        $this->postJson('/api/admin/bookings/10/pets/100/medical-concerns/401/resume-grooming', [
            'internal_resolution_notes' => 'Attempted resume during assessment.',
            'customer_resolution_summary' => 'Attempted resume during assessment.',
        ])->assertConflict()->assertJsonPath(
            'message',
            'Grooming cannot resume because this pet was transferred to clinic care and the grooming session was ended.',
        );
    }

    public function test_referral_assessment_completion_is_permanent_safe_and_idempotent(): void
    {
        [$referral, $appointmentId] = $this->acceptRoutineReferralForAssessment(
            GroomingMedicalConcern::ACTION_STOP_GROOMING,
        );
        $this->authenticateAs(2);
        $this->postJson("/api/admin/clinic-appointments/{$appointmentId}/start-consultation")
            ->assertOk();

        $this->postJson("/api/admin/clinic-appointments/{$appointmentId}/finish-consultation", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'internal_resolution_notes',
                'customer_resolution_summary',
            ]);
        $this->postJson("/api/admin/clinic-appointments/{$appointmentId}/finish-consultation", [
            'internal_resolution_notes' => 'PRIVATE assessment outcome.',
            'customer_resolution_summary' => 'Initial clinic assessment completed safely.',
            'grooming_clearance_status' => 'cleared_to_resume',
        ])->assertUnprocessable()->assertJsonValidationErrors('grooming_clearance_status');

        $payload = [
            'internal_resolution_notes' => 'PRIVATE assessment outcome.',
            'customer_resolution_summary' => 'Initial clinic assessment completed safely.',
        ];
        $this->postJson(
            "/api/admin/clinic-appointments/{$appointmentId}/finish-consultation",
            $payload,
        )
            ->assertOk()
            ->assertJsonPath('already_synchronized', false)
            ->assertJsonPath('appointment.status', 'for_payment')
            ->assertJsonPath('appointment.grooming_referral.assessment_completed', true)
            ->assertJsonPath(
                'appointment.grooming_referral.customer_resolution_summary',
                $payload['customer_resolution_summary'],
            );

        $referral->refresh();
        $this->assertSame(GroomingClinicReferral::STATUS_COMPLETED, $referral->status);
        $this->assertSame(GroomingClinicReferral::CLEARANCE_NOT_APPLICABLE, $referral->grooming_clearance_status);
        $this->assertSame($payload['internal_resolution_notes'], $referral->internal_resolution_notes);
        $this->assertSame(
            GroomingMedicalConcern::STATUS_RESOLVED,
            DB::table('grooming_medical_concerns')->where('id', 401)->value('status'),
        );
        $this->assertSame(
            BookingPet::GROOMING_STATE_STOPPED,
            DB::table('booking_pets')->where('booking_pet_id', 100)->value('grooming_state'),
        );
        $this->assertNull(DB::table('booking_pets')->where('booking_pet_id', 100)->value('grooming_end_time'));
        $this->assertDatabaseHas('customer_notifications', [
            'grooming_clinic_referral_id' => $referral->id,
            'type' => CustomerNotification::TYPE_GROOMING_CLINIC_ASSESSMENT_COMPLETED,
        ]);
        $assessmentCompletedNotification = DB::table('customer_notifications')
            ->where('grooming_clinic_referral_id', $referral->id)
            ->where('type', CustomerNotification::TYPE_GROOMING_CLINIC_ASSESSMENT_COMPLETED)
            ->first();
        $this->assertSame(4, $assessmentCompletedNotification->user_id);
        $this->assertStringContainsString('Zeus', $assessmentCompletedNotification->message);
        $this->assertStringNotContainsString('Luna', $assessmentCompletedNotification->message);

        $this->postJson(
            "/api/admin/clinic-appointments/{$appointmentId}/finish-consultation",
            $payload,
        )->assertOk()->assertJsonPath('already_synchronized', true);
        $this->postJson("/api/admin/clinic-appointments/{$appointmentId}/finish-consultation", [
            ...$payload,
            'customer_resolution_summary' => 'A conflicting replacement summary.',
        ])->assertConflict();
        $this->assertSame(1, DB::table('customer_notifications')
            ->where('grooming_clinic_referral_id', $referral->id)
            ->where('type', CustomerNotification::TYPE_GROOMING_CLINIC_ASSESSMENT_COMPLETED)
            ->count());

        $readiness = app(GroomingPaymentReadinessService::class)
            ->summarize(Booking::query()->findOrFail(10));
        $this->assertFalse($readiness['payment_ready']);
        $this->assertStringContainsString('Payment review is required', $readiness['payment_blocked_reason']);
        $assessment = app(GroomingClinicReferralAssessmentService::class);
        $this->assertSame(
            'The grooming workflow is complete, but the linked clinic appointment must be paid and completed before pickup.',
            $assessment->pickupBlockedReason(Booking::query()->findOrFail(10)),
        );
        DB::table('clinic_appointments')->where('id', $appointmentId)->update([
            'status' => 'completed',
            'paid' => true,
        ]);
        $this->assertNull($assessment->pickupBlockedReason(Booking::query()->findOrFail(10)));

        $this->postJson('/api/admin/bookings/10/pets/100/medical-concerns/401/resume-grooming', [
            'internal_resolution_notes' => 'Attempted resume after completion.',
            'customer_resolution_summary' => 'Attempted resume after completion.',
        ])->assertConflict()->assertJsonPath(
            'message',
            'Grooming cannot resume because this pet was transferred to clinic care and the grooming session was ended.',
        );

        $this->authenticateAs(4);
        $customer = $this->getJson(
            "/api/pets/1000/grooming-clinic-referrals/{$referral->public_id}",
        )
            ->assertOk()
            ->assertJsonPath('referral.clinic_assessment_completed', true)
            ->assertJsonPath('referral.grooming_outcome', 'stopped')
            ->assertJsonPath('referral.customer_resolution_summary', $payload['customer_resolution_summary']);
        $this->assertStringNotContainsString('PRIVATE assessment outcome', $customer->getContent());
        $this->assertStringNotContainsString('internal_resolution_notes', $customer->getContent());
        $this->assertStringNotContainsString('resolved_by_user_id', $customer->getContent());
    }

    public function test_unregistered_referral_assessment_creates_no_portal_notifications(): void
    {
        DB::table('booking_pets')->where('booking_pet_id', 200)->update([
            'grooming_state' => BookingPet::GROOMING_STATE_STOPPED,
        ]);
        DB::table('grooming_medical_concerns')->where('id', 402)->update([
            'applied_grooming_action' => GroomingMedicalConcern::ACTION_STOP_GROOMING,
            'action_applied_at' => now(),
            'action_applied_by_user_id' => 2,
        ]);
        $this->authenticateAs(2);
        $created = $this->postJson(
            '/api/admin/bookings/20/pets/200/medical-concerns/402/clinic-referral',
            $this->payload([
                'urgency' => 'emergency',
                'request_token' => '40000000-0000-4000-8000-000000000004',
                'emergency_without_consent_reason' => 'Immediate clinic intake is required.',
            ]),
        )->assertCreated();
        $publicId = $created->json('referral.public_id');
        $accepted = $this->postJson("/api/admin/clinic-referrals/{$publicId}/accept")
            ->assertCreated();
        $appointmentId = (int) $accepted->json('referral.clinic_appointment.id');

        $this->postJson("/api/admin/clinic-appointments/{$appointmentId}/start-consultation")
            ->assertOk();
        $this->postJson("/api/admin/clinic-appointments/{$appointmentId}/finish-consultation", [
            'internal_resolution_notes' => 'Internal assessment completed.',
            'customer_resolution_summary' => 'Initial clinic assessment completed.',
        ])->assertOk();

        $referralId = GroomingClinicReferral::query()
            ->where('public_id', $publicId)
            ->value('id');
        $this->assertSame(0, DB::table('customer_notifications')
            ->where('grooming_clinic_referral_id', $referralId)
            ->count());
    }

    public function test_accepted_referral_blocks_resume_and_active_referral_blocks_payment_and_pickup(): void
    {
        [$referral] = $this->acceptRoutineReferralForAssessment(
            GroomingMedicalConcern::ACTION_PAUSE_GROOMING,
        );
        $this->authenticateAs(2);

        $this->postJson('/api/admin/bookings/10/pets/100/medical-concerns/401/resume-grooming', [
            'internal_resolution_notes' => 'Attempted resume.',
            'customer_resolution_summary' => 'Attempted resume.',
        ])
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Grooming cannot resume because this pet was transferred to clinic care and the grooming session was ended.',
            );

        $readiness = app(GroomingPaymentReadinessService::class)
            ->summarize(Booking::query()->findOrFail(10));
        $this->assertFalse($readiness['payment_ready']);
        $this->assertStringContainsString('active clinic referral', $readiness['payment_blocked_reason']);
        $this->assertSame(
            'Physical pickup is unavailable while a linked clinic referral is active.',
            app(GroomingClinicReferralAssessmentService::class)
                ->pickupBlockedReason(Booking::query()->findOrFail(10)),
        );
        $this->assertSame(GroomingClinicReferral::STATUS_ACCEPTED, $referral->fresh()->status);
    }

    private function createRoutineReferral(): GroomingClinicReferral
    {
        $this->authenticateAs(2);
        $this->postJson(self::STAFF_URI, $this->payload())->assertCreated();

        return GroomingClinicReferral::query()->firstOrFail();
    }

    /** @return array{0: GroomingClinicReferral, 1: int} */
    private function acceptRoutineReferralForAssessment(string $appliedAction): array
    {
        $state = $appliedAction === GroomingMedicalConcern::ACTION_PAUSE_GROOMING
            ? BookingPet::GROOMING_STATE_PAUSED
            : BookingPet::GROOMING_STATE_STOPPED;
        DB::table('booking_pets')->where('booking_pet_id', 100)->update([
            'grooming_state' => $state,
        ]);
        DB::table('grooming_medical_concerns')->where('id', 401)->update([
            'applied_grooming_action' => $appliedAction,
            'action_applied_at' => now(),
            'action_applied_by_user_id' => 2,
        ]);
        $referral = $this->createRoutineReferral();
        $this->authenticateAs(4);
        $this->postJson(
            "/api/pets/1000/grooming-clinic-referrals/{$referral->public_id}/consent",
            ['decision' => 'approved', 'signature_name' => 'Pet Owner'],
        )->assertCreated();
        $this->authenticateAs(2);
        $accepted = $this->postJson("/api/admin/clinic-referrals/{$referral->public_id}/accept")
            ->assertCreated();

        return [$referral->fresh(), (int) $accepted->json('referral.clinic_appointment.id')];
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
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('sedation_consent')->default(false);
            $table->boolean('terms_agreed')->default(false);
            $table->unsignedInteger('user_id')->nullable();
            $table->string('appointment_type')->default('grooming');
            $table->text('chief_complaint')->nullable();
            $table->timestamps();
        });
        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('pet_name');
            $table->string('species')->nullable();
            $table->string('breed')->nullable();
            $table->string('size')->nullable();
            $table->decimal('weight', 8, 2)->nullable();
            $table->foreign('user_id')->references('user_id')->on('users')->nullOnDelete();
        });
        Schema::create('time_windows', function (Blueprint $table) {
            $table->increments('window_id');
            $table->string('window_label');
        });
        Schema::create('bookings', function (Blueprint $table) {
            $table->increments('booking_id');
            $table->string('booking_reference')->unique();
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedBigInteger('walkin_id')->nullable();
            $table->unsignedInteger('window_id')->nullable();
            $table->date('booking_date')->nullable();
            $table->unsignedTinyInteger('number_of_pets')->default(1);
            $table->string('status');
            $table->boolean('paid')->default(false);
            $table->unsignedTinyInteger('reschedule_count')->default(0);
            $table->unsignedTinyInteger('cancel_count')->default(0);
            $table->text('special_notes')->nullable();
            $table->timestamp('dropped_off_at')->nullable();
            $table->timestamp('grooming_started_at')->nullable();
            $table->timestamp('grooming_finished_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('created_at')->nullable();
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
        Schema::create('booking_services', function (Blueprint $table) {
            $table->increments('booking_service_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('booking_pet_id');
            $table->unsignedInteger('service_id')->nullable();
            $table->unsignedInteger('addon_id')->nullable();
            $table->decimal('price_at_booking', 8, 2)->default(0);
        });
        Schema::create('clinic_appointments', function (Blueprint $table) {
            $table->id();
            $table->string('appointment_reference')->unique();
            $table->string('appointment_type')->default('walk_in');
            $table->string('status')->default('checked_in');
            $table->unsignedSmallInteger('queue_number')->nullable();
            $table->date('appointment_date');
            $table->unsignedInteger('window_id')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedBigInteger('walkin_id')->nullable();
            $table->unsignedInteger('pet_id')->nullable();
            $table->text('chief_complaint')->nullable();
            $table->decimal('total_amount', 10, 2)->nullable();
            $table->boolean('paid')->default(false);
            $table->text('notes')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('consultation_started_at')->nullable();
            $table->timestamp('consultation_finished_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->foreign('user_id')->references('user_id')->on('users')->nullOnDelete();
            $table->foreign('walkin_id')->references('id')->on('walkins')->nullOnDelete();
            $table->foreign('pet_id')->references('pet_id')->on('pets')->nullOnDelete();
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
            $table->unsignedBigInteger('clinic_appointment_id')->nullable();
            $table->string('status')->default('open');
            $table->boolean('acknowledgment_required')->default(false);
            $table->boolean('consent_required')->default(false);
            $table->string('customer_response_status')->default('not_required');
            $table->timestamp('customer_notified_at')->nullable();
            $table->unsignedInteger('resolved_by_user_id')->nullable();
            $table->string('resolved_by_name', 200)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('internal_resolution_notes')->nullable();
            $table->text('customer_resolution_summary')->nullable();
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
        DB::table('clinic_settings')->insert([
            'id' => 1,
            'groomers_on_duty' => 2,
            'clinic_open_time' => '08:00:00',
            'clinic_close_time' => '17:00:00',
            'clinic_prereg_cutoff_time' => '14:00:00',
            'grooming_open_time' => '08:00:00',
            'grooming_close_time' => '17:00:00',
            'grooming_prereg_cutoff_time' => '14:00:00',
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
