<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminGroomingMedicalConcernController;
use App\Models\BookingPet;
use App\Models\CustomerNotification;
use App\Models\GroomingMedicalConcern;
use App\Models\GroomingMedicalConcernResponse;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminGroomingMedicalConcernApiTest extends TestCase
{
    private const BASE_URI = '/api/admin/bookings/10/pets/100/medical-concerns';

    private const CONCERN_ROUTES = [
        ['GET', 'api/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns'],
        ['POST', 'api/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns'],
        ['GET', 'api/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns/{concernId}'],
        ['PATCH', 'api/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns/{concernId}'],
        ['POST', 'api/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns/{concernId}/notify-customer'],
        ['POST', 'api/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns/{concernId}/apply-recommended-action'],
        ['POST', 'api/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns/{concernId}/resume-grooming'],
        ['POST', 'api/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns/{concernId}/cancel'],
        ['POST', 'api/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns/{concernId}/resolve'],
    ];

    private $bookingPetMigration;

    private $concernMigration;

    private $notificationLinkMigration;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-24 14:30:00'));
        DB::statement('PRAGMA foreign_keys = ON');

        $this->createExistingSchema();

        $this->bookingPetMigration = require base_path(
            'database/migrations/2026_07_24_000002_add_grooming_state_and_integrity_to_booking_pets.php',
        );
        $this->concernMigration = require base_path(
            'database/migrations/2026_07_24_000003_create_grooming_medical_concern_tables.php',
        );
        $this->notificationLinkMigration = require base_path(
            'database/migrations/2026_07_24_000004_link_customer_notifications_to_grooming_medical_concerns.php',
        );

        $this->bookingPetMigration->up();
        $this->concernMigration->up();
        $this->notificationLinkMigration->up();

        $this->seedExistingData();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Schema::disableForeignKeyConstraints();

        foreach ([
            'grooming_medical_concern_responses',
            'customer_notifications',
            'grooming_medical_concerns',
            'notifications',
            'payments',
            'inventory_items',
            'clinic_settings',
            'clinic_appointments',
            'booking_pets',
            'bookings',
            'pets',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
        parent::tearDown();
    }

    #[DataProvider('concernRouteRequests')]
    public function test_unauthenticated_and_customer_requests_are_rejected(
        string $method,
        string $uri,
        array $payload,
    ): void {
        $this->json($method, $uri, $payload)->assertUnauthorized();

        $this->authenticateAs(3);
        $this->json($method, $uri, $payload)
            ->assertForbidden()
            ->assertExactJson([
                'success' => false,
                'message' => 'Forbidden. You do not have permission to access this resource.',
            ]);
    }

    public static function concernRouteRequests(): array
    {
        return [
            'list' => ['GET', self::BASE_URI, []],
            'create' => ['POST', self::BASE_URI, []],
            'show' => ['GET', self::BASE_URI.'/1', []],
            'update' => ['PATCH', self::BASE_URI.'/1', []],
            'notify customer' => ['POST', self::BASE_URI.'/1/notify-customer', []],
            'apply recommended action' => [
                'POST',
                self::BASE_URI.'/1/apply-recommended-action',
                [],
            ],
            'resume grooming' => [
                'POST',
                self::BASE_URI.'/1/resume-grooming',
                [],
            ],
            'cancel' => ['POST', self::BASE_URI.'/1/cancel', []],
            'resolve' => ['POST', self::BASE_URI.'/1/resolve', []],
        ];
    }

    #[DataProvider('authorizedRoles')]
    public function test_staff_and_admin_can_list_and_create_concerns(int $userId): void
    {
        $this->authenticateAs($userId);

        $this->getJson(self::BASE_URI)
            ->assertOk()
            ->assertJsonPath('booking_pet.booking_id', 10)
            ->assertJsonPath('booking_pet.booking_pet_id', 100)
            ->assertJsonPath('booking_pet.pet_id', 1000)
            ->assertJsonCount(0, 'concerns');

        $this->postJson(self::BASE_URI, $this->validPayload([
            'category' => "skin-{$userId}",
        ]))
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('concern.booking_id', 10)
            ->assertJsonPath('concern.booking_pet_id', 100)
            ->assertJsonPath('concern.pet_id', 1000);
    }

    public static function authorizedRoles(): array
    {
        return [
            'staff' => [2],
            'administrator' => [1],
        ];
    }

    public function test_all_routes_have_required_middleware_and_no_delete_route_exists(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        foreach (self::CONCERN_ROUTES as [$method, $uri]) {
            $route = $routes->first(
                fn (RoutingRoute $route) => $route->uri() === $uri
                    && in_array($method, $route->methods(), true),
            );

            $this->assertNotNull($route, "Missing route [{$method} {$uri}].");
            $this->assertSame(
                AdminGroomingMedicalConcernController::class,
                $route->getControllerClass(),
            );
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
            $this->assertContains('role:admin,staff', $route->gatherMiddleware());
        }

        $this->authenticateAs(2);
        $concern = $this->createConcern();

        $this->deleteJson(self::BASE_URI."/{$concern->id}")
            ->assertMethodNotAllowed();
        $this->assertDatabaseHas('grooming_medical_concerns', [
            'id' => $concern->id,
        ]);
    }

    #[DataProvider('authorizedRoles')]
    public function test_staff_and_admin_can_transactionally_notify_an_eligible_concern(
        int $userId,
    ): void {
        $this->authenticateAs($userId);
        $concern = $this->createConcern([
            'acknowledgment_required' => true,
            'customer_response_status' => GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING,
        ]);

        $response = $this->postJson(
            self::BASE_URI."/{$concern->id}/notify-customer",
        )
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('already_notified', false)
            ->assertJsonPath('concern.status', GroomingMedicalConcern::STATUS_AWAITING_CUSTOMER)
            ->assertJsonPath(
                'concern.customer_response_status',
                GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING,
            )
            ->assertJsonPath('concern.customer_visible_fields_editable', false)
            ->assertJsonPath(
                'notification.type',
                CustomerNotification::TYPE_GROOMING_MEDICAL_CONCERN,
            )
            ->assertJsonPath('notification.pet_id', 1000)
            ->assertJsonPath('notification.pet_name', 'Zeus');

        $this->assertNotNull($response->json('concern.customer_notified_at'));
        $this->assertDatabaseHas('customer_notifications', [
            'user_id' => 4,
            'booking_id' => 10,
            'grooming_medical_concern_id' => $concern->id,
            'type' => CustomerNotification::TYPE_GROOMING_MEDICAL_CONCERN,
            'is_read' => 0,
        ]);
        $this->assertDatabaseHas('grooming_medical_concerns', [
            'id' => $concern->id,
            'status' => GroomingMedicalConcern::STATUS_AWAITING_CUSTOMER,
            'customer_response_status' => GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING,
            'customer_notified_at' => now()->toDateTimeString(),
        ]);
        $this->assertDatabaseCount('grooming_medical_concern_responses', 0);
    }

    public function test_notification_without_a_required_response_keeps_open_state(): void
    {
        $this->authenticateAs(2);
        $concern = $this->createConcern([
            'acknowledgment_required' => false,
            'consent_required' => false,
            'customer_response_status' => GroomingMedicalConcern::CUSTOMER_RESPONSE_NOT_REQUIRED,
        ]);

        $this->postJson(self::BASE_URI."/{$concern->id}/notify-customer")
            ->assertOk()
            ->assertJsonPath('concern.status', GroomingMedicalConcern::STATUS_OPEN)
            ->assertJsonPath(
                'concern.customer_response_status',
                GroomingMedicalConcern::CUSTOMER_RESPONSE_NOT_REQUIRED,
            );

        $this->assertDatabaseCount('grooming_medical_concern_responses', 0);
    }

    public function test_notification_rejects_blank_terminal_unlinked_and_inconsistent_concerns_without_side_effects(): void
    {
        $this->authenticateAs(2);
        $initialNotificationCount = CustomerNotification::query()->count();

        $blank = $this->createConcern([
            'category' => 'blank message',
            'customer_message' => '   ',
        ]);
        $this->postJson(self::BASE_URI."/{$blank->id}/notify-customer")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer_message');

        $terminal = $this->createConcern([
            'category' => 'terminal',
            'status' => GroomingMedicalConcern::STATUS_RESOLVED,
        ]);
        $this->postJson(self::BASE_URI."/{$terminal->id}/notify-customer")
            ->assertConflict();

        $inconsistent = $this->createConcern([
            'category' => 'inconsistent',
            'acknowledgment_required' => true,
            'customer_response_status' => GroomingMedicalConcern::CUSTOMER_RESPONSE_NOT_REQUIRED,
        ]);
        $this->postJson(self::BASE_URI."/{$inconsistent->id}/notify-customer")
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'The concern requirement flags and customer-response status are inconsistent.',
            );

        $unlinked = $this->createConcern([
            'category' => 'guest walk-in',
        ]);
        DB::table('pets')->where('pet_id', 1000)->update(['user_id' => null]);
        $this->postJson(self::BASE_URI."/{$unlinked->id}/notify-customer")
            ->assertUnprocessable()
            ->assertExactJson([
                'success' => false,
                'message' => 'No linked customer account.',
            ]);

        $this->assertSame(
            $initialNotificationCount,
            CustomerNotification::query()->count(),
        );
        foreach ([$blank, $terminal, $inconsistent, $unlinked] as $concern) {
            $this->assertNull($concern->fresh()->customer_notified_at);
        }
    }

    public function test_repeated_notification_is_idempotent_and_keeps_original_owner_and_timestamp(): void
    {
        $this->authenticateAs(2);
        $concern = $this->createConcern();
        $uri = self::BASE_URI."/{$concern->id}/notify-customer";

        $first = $this->postJson($uri)
            ->assertOk()
            ->assertJsonPath('already_notified', false);
        $notifiedAt = $first->json('concern.customer_notified_at');
        $notificationId = $first->json('notification.id');

        DB::table('pets')->where('pet_id', 1000)->update(['user_id' => 3]);
        Carbon::setTestNow(now()->addMinutes(10));

        $this->postJson($uri)
            ->assertOk()
            ->assertJsonPath('already_notified', true)
            ->assertJsonPath('notification.id', $notificationId)
            ->assertJsonPath('concern.customer_notified_at', $notifiedAt);

        $this->assertSame(
            1,
            CustomerNotification::query()
                ->where('grooming_medical_concern_id', $concern->id)
                ->count(),
        );
        $this->assertDatabaseHas('customer_notifications', [
            'id' => $notificationId,
            'user_id' => 4,
        ]);
    }

    public function test_notification_insert_failure_rolls_back_concern_release(): void
    {
        $this->authenticateAs(2);
        $concern = $this->createConcern();
        $initialNotificationCount = CustomerNotification::query()->count();

        DB::statement(
            "CREATE TRIGGER reject_concern_notification
             BEFORE INSERT ON customer_notifications
             WHEN NEW.type = 'grooming_medical_concern'
             BEGIN
                 SELECT RAISE(ABORT, 'simulated notification failure');
             END",
        );

        try {
            $this->postJson(
                self::BASE_URI."/{$concern->id}/notify-customer",
            )->assertServerError();
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS reject_concern_notification');
        }

        $concern->refresh();
        $this->assertNull($concern->customer_notified_at);
        $this->assertSame(GroomingMedicalConcern::STATUS_OPEN, $concern->status);
        $this->assertSame(
            $initialNotificationCount,
            CustomerNotification::query()->count(),
        );
    }

    public function test_concern_notification_linkage_is_customer_safe_and_read_is_not_a_response(): void
    {
        $this->authenticateAs(2);
        $concern = $this->createConcern([
            'acknowledgment_required' => true,
            'customer_response_status' => GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING,
            'internal_description' => 'PRIVATE STAFF OBSERVATION',
            'internal_resolution_notes' => 'PRIVATE STAFF RESOLUTION',
            'report_token' => '10000000-0000-4000-8000-000000000013',
        ]);

        $notificationId = $this->postJson(
            self::BASE_URI."/{$concern->id}/notify-customer",
        )
            ->assertOk()
            ->json('notification.id');

        $this->authenticateAs(4);
        $response = $this->getJson('/api/customer/notifications')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $notificationId,
                'type' => CustomerNotification::TYPE_GROOMING_MEDICAL_CONCERN,
                'concern_public_id' => $concern->public_id,
                'pet_id' => 1000,
                'pet_name' => 'Zeus',
                'destination' => "./pet-details.html?pet_id=1000&tab=notifications&concern={$concern->public_id}",
            ]);

        foreach ([
            'PRIVATE STAFF OBSERVATION',
            'PRIVATE STAFF RESOLUTION',
            '10000000-0000-4000-8000-000000000013',
            'reported_by_user_id',
            'booking_pet_id',
            'statement_text',
            'signature',
            'inventory',
            'payment',
        ] as $privateValue) {
            $this->assertStringNotContainsString(
                $privateValue,
                $response->getContent(),
            );
        }

        $this->patchJson("/api/customer/notifications/{$notificationId}/read")
            ->assertOk();

        $concern->refresh();
        $this->assertSame(
            GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING,
            $concern->customer_response_status,
        );
        $this->assertSame(
            GroomingMedicalConcern::STATUS_AWAITING_CUSTOMER,
            $concern->status,
        );
        $this->assertDatabaseCount('grooming_medical_concern_responses', 0);

        $this->authenticateAs(3);
        $this->getJson('/api/customer/notifications')
            ->assertOk()
            ->assertJsonMissing([
                'concern_public_id' => $concern->public_id,
            ]);
        $this->patchJson("/api/customer/notifications/{$notificationId}/read")
            ->assertNotFound();
    }

    public function test_notification_locks_customer_visible_fields_but_keeps_staff_notes_editable(): void
    {
        $this->authenticateAs(2);
        $concern = $this->createConcern();
        $uri = self::BASE_URI."/{$concern->id}";

        $this->postJson("{$uri}/notify-customer")
            ->assertOk()
            ->assertJsonPath('concern.customer_visible_fields_editable', false)
            ->assertJsonMissing(['notify_customer']);

        $this->patchJson($uri, [
            'customer_message' => 'Attempted customer-visible rewrite.',
        ])->assertConflict();

        $this->patchJson($uri, [
            'internal_description' => 'Internal follow-up after sending.',
        ])
            ->assertOk()
            ->assertJsonPath(
                'concern.internal_description',
                'Internal follow-up after sending.',
            )
            ->assertJsonPath('concern.customer_visible_fields_editable', false);
    }

    public function test_nested_scoping_is_generic_and_multi_pet_histories_never_mix(): void
    {
        $this->authenticateAs(2);
        $firstPetConcern = $this->createConcern(['category' => 'skin']);
        $secondPetConcern = $this->createConcern([
            'booking_pet_id' => 101,
            'pet_id' => 1001,
            'category' => 'ear',
        ]);

        $this->getJson(self::BASE_URI)
            ->assertOk()
            ->assertJsonCount(1, 'concerns')
            ->assertJsonPath('concerns.0.id', $firstPetConcern->id)
            ->assertJsonMissing([
                'id' => $secondPetConcern->id,
            ]);

        $this->getJson('/api/admin/bookings/10/pets/101/medical-concerns')
            ->assertOk()
            ->assertJsonCount(1, 'concerns')
            ->assertJsonPath('concerns.0.id', $secondPetConcern->id);

        $this->getJson('/api/admin/bookings/20/pets/100/medical-concerns')
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Grooming booking pet not found.',
            ]);
        $this->postJson('/api/admin/bookings/20/pets/100/medical-concerns', [])
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Grooming booking pet not found.',
            ]);

        $this->getJson(self::BASE_URI."/{$secondPetConcern->id}")
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Medical concern not found.',
            ]);
        $this->patchJson(self::BASE_URI."/{$secondPetConcern->id}", [])
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Medical concern not found.',
            ]);
    }

    public function test_list_returns_active_resolved_and_cancelled_history_newest_first(): void
    {
        $this->authenticateAs(2);
        $oldResolved = $this->createConcern([
            'category' => 'old-resolved',
            'status' => GroomingMedicalConcern::STATUS_RESOLVED,
            'reported_at' => '2026-07-24 10:00:00',
            'resolved_at' => '2026-07-24 11:00:00',
        ]);
        $cancelled = $this->createConcern([
            'category' => 'cancelled',
            'status' => GroomingMedicalConcern::STATUS_CANCELLED,
            'reported_at' => '2026-07-24 12:00:00',
            'resolved_at' => '2026-07-24 13:00:00',
        ]);
        $newActive = $this->createConcern([
            'category' => 'new-active',
            'reported_at' => '2026-07-24 14:00:00',
        ]);

        $response = $this->getJson(self::BASE_URI)
            ->assertOk()
            ->assertJsonCount(3, 'concerns');

        $this->assertSame(
            [$newActive->id, $cancelled->id, $oldResolved->id],
            collect($response->json('concerns'))->pluck('id')->all(),
        );
        $this->assertSame(
            [
                GroomingMedicalConcern::STATUS_OPEN,
                GroomingMedicalConcern::STATUS_CANCELLED,
                GroomingMedicalConcern::STATUS_RESOLVED,
            ],
            collect($response->json('concerns'))->pluck('status')->all(),
        );
    }

    #[DataProvider('eligibleGroomingContexts')]
    public function test_creation_uses_booking_status_and_timestamps_not_stale_grooming_state(
        string $bookingStatus,
        ?string $startTime,
        string $staleGroomingState,
    ): void {
        $this->authenticateAs(2);
        DB::table('bookings')->where('booking_id', 10)->update([
            'status' => $bookingStatus,
        ]);
        DB::table('booking_pets')->where('booking_pet_id', 100)->update([
            'grooming_start_time' => $startTime,
            'grooming_end_time' => null,
            'grooming_state' => $staleGroomingState,
        ]);

        $this->postJson(self::BASE_URI, $this->validPayload())
            ->assertCreated();
    }

    public static function eligibleGroomingContexts(): array
    {
        return [
            'checked-in queue with stale finished state' => [
                'checked_in',
                null,
                BookingPet::GROOMING_STATE_FINISHED,
            ],
            'partially started booking pet' => [
                'checked_in',
                '2026-07-24 14:00:00',
                BookingPet::GROOMING_STATE_NOT_STARTED,
            ],
            'in-progress pet' => [
                'in_progress',
                '2026-07-24 14:00:00',
                BookingPet::GROOMING_STATE_STOPPED,
            ],
            'legacy waiting queue' => [
                'waiting',
                null,
                BookingPet::GROOMING_STATE_IN_PROGRESS,
            ],
        ];
    }

    #[DataProvider('ineligibleGroomingContexts')]
    public function test_creation_rejects_ineligible_or_finished_contexts(
        string $bookingStatus,
        ?string $endTime,
        bool $bookingArchived,
        bool $petArchived,
        string $staleGroomingState,
    ): void {
        $this->authenticateAs(2);
        DB::table('bookings')->where('booking_id', 10)->update([
            'status' => $bookingStatus,
            'archived_at' => $bookingArchived ? '2026-07-24 14:00:00' : null,
        ]);
        DB::table('booking_pets')->where('booking_pet_id', 100)->update([
            'grooming_end_time' => $endTime,
            'grooming_state' => $staleGroomingState,
        ]);
        DB::table('pets')->where('pet_id', 1000)->update([
            'is_archived' => $petArchived,
        ]);

        $this->postJson(self::BASE_URI, $this->validPayload())
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'A medical concern can only be reported while the selected pet is in an active grooming workflow.',
            );
        $this->assertSame(0, GroomingMedicalConcern::count());
    }

    public static function ineligibleGroomingContexts(): array
    {
        return [
            'not yet arrived' => ['waiting_to_arrive', null, false, false, 'not_started'],
            'released despite stale in-progress state' => ['released', null, false, false, 'in_progress'],
            'archived status' => ['archived', null, false, false, 'not_started'],
            'archived timestamp' => ['checked_in', null, true, false, 'not_started'],
            'cancelled' => ['cancelled', null, false, false, 'not_started'],
            'no-show' => ['no_show', null, false, false, 'not_started'],
            'payment stage' => ['for_payment', null, false, false, 'not_started'],
            'fully finished pet' => [
                'in_progress',
                '2026-07-24 14:20:00',
                false,
                false,
                'not_started',
            ],
            'archived pet record' => ['checked_in', null, false, true, 'not_started'],
        ];
    }

    public function test_creation_validates_payload_and_uses_server_audit_context(): void
    {
        $this->authenticateAs(2);

        $this->postJson(self::BASE_URI, [
            'category' => '   ',
            'severity' => 'critical',
            'internal_description' => '   ',
            'customer_message' => '   ',
            'recommended_grooming_action' => 'refer_to_clinic',
            'report_token' => 'not-a-uuid',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'category',
                'severity',
                'internal_description',
                'customer_message',
                'recommended_grooming_action',
                'report_token',
            ]);

        $this->postJson(self::BASE_URI, $this->validPayload([
            'id' => 999,
            'public_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'booking_id' => 20,
            'booking_pet_id' => 200,
            'pet_id' => 1002,
            'reported_by_user_id' => 1,
            'reported_by_name' => 'Impersonated Admin',
            'reported_at' => '2020-01-01 00:00:00',
            'status' => GroomingMedicalConcern::STATUS_RESOLVED,
            'customer_response_status' => GroomingMedicalConcern::CUSTOMER_RESPONSE_APPROVED,
            'customer_notified_at' => now()->toIso8601String(),
            'clinic_appointment_id' => 500,
            'applied_grooming_action' => GroomingMedicalConcern::ACTION_STOP_GROOMING,
            'resolved_by_user_id' => 1,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'id',
                'public_id',
                'booking_id',
                'booking_pet_id',
                'pet_id',
                'reported_by_user_id',
                'reported_by_name',
                'reported_at',
                'status',
                'customer_response_status',
                'customer_notified_at',
                'clinic_appointment_id',
                'applied_grooming_action',
                'resolved_by_user_id',
            ]);
        $this->assertSame(0, GroomingMedicalConcern::count());

        $response = $this->postJson(self::BASE_URI, $this->validPayload([
            'category' => '  Skin irritation  ',
            'acknowledgment_required' => false,
            'consent_required' => true,
        ]))
            ->assertCreated()
            ->assertJsonPath('concern.category', 'Skin irritation')
            ->assertJsonPath('concern.status', GroomingMedicalConcern::STATUS_OPEN)
            ->assertJsonPath(
                'concern.customer_response_status',
                GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING,
            )
            ->assertJsonPath('concern.reported_by_name', 'Staff Member')
            ->assertJsonPath('concern.reported_at', now()->toIso8601String())
            ->assertJsonPath('concern.recommended_action_is_advisory', true)
            ->assertJsonPath('concern.applied_grooming_action', null)
            ->assertJsonPath('concern.customer_notified_at', null)
            ->assertJsonMissingPath('concern.report_token')
            ->assertJsonMissingPath('concern.reported_by_user_id');

        $this->assertTrue(
            str($response->json('concern.public_id'))->isUuid(),
        );
        $this->assertDatabaseHas('grooming_medical_concerns', [
            'booking_id' => 10,
            'booking_pet_id' => 100,
            'pet_id' => 1000,
            'reported_by_user_id' => 2,
            'reported_by_name' => 'Staff Member',
            'status' => GroomingMedicalConcern::STATUS_OPEN,
            'customer_response_status' => GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING,
        ]);
    }

    public function test_requirement_flags_derive_customer_response_status(): void
    {
        $this->authenticateAs(2);

        foreach ([
            [false, false, GroomingMedicalConcern::CUSTOMER_RESPONSE_NOT_REQUIRED],
            [true, false, GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING],
            [false, true, GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING],
            [true, true, GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING],
        ] as $index => [$acknowledgment, $consent, $expectedStatus]) {
            $response = $this->postJson(self::BASE_URI, $this->validPayload([
                'category' => "category-{$index}",
                'acknowledgment_required' => $acknowledgment,
                'consent_required' => $consent,
            ]));

            $response
                ->assertCreated()
                ->assertJsonPath('concern.customer_response_status', $expectedStatus);
        }
    }

    public function test_report_token_replay_cross_pet_conflict_and_active_category_control(): void
    {
        $this->authenticateAs(2);
        $token = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $payload = $this->validPayload([
            'category' => 'skin',
            'report_token' => $token,
        ]);

        $created = $this->postJson(self::BASE_URI, $payload)
            ->assertCreated()
            ->assertJsonPath('idempotent_replay', false);
        $concernId = $created->json('concern.id');

        $this->postJson(self::BASE_URI, $payload)
            ->assertOk()
            ->assertJsonPath('idempotent_replay', true)
            ->assertJsonPath('concern.id', $concernId);
        $this->assertSame(1, GroomingMedicalConcern::count());

        $this->postJson(
            '/api/admin/bookings/10/pets/101/medical-concerns',
            $this->validPayload([
                'category' => 'ear',
                'report_token' => $token,
            ]),
        )
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'This report token is already associated with a different booking pet.',
            );

        $this->postJson(self::BASE_URI, $this->validPayload([
            'category' => '  SKIN  ',
        ]))
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'An active medical concern with this category already exists for the selected booking pet.',
            );

        $this->postJson(self::BASE_URI."/{$concernId}/resolve", [
            'internal_resolution_notes' => 'Condition handled safely.',
            'customer_resolution_summary' => 'The condition was handled.',
        ])->assertOk();

        $this->postJson(self::BASE_URI, $this->validPayload([
            'category' => 'skin',
        ]))->assertCreated();

        $cancelled = $this->createConcern(['category' => 'coat']);
        $this->postJson(self::BASE_URI."/{$cancelled->id}/cancel", [
            'internal_cancellation_reason' => 'Duplicate staff entry.',
        ])->assertOk();
        $this->postJson(self::BASE_URI, $this->validPayload([
            'category' => 'coat',
        ]))->assertCreated();
    }

    public function test_active_updates_are_scoped_and_customer_facts_lock_after_notification(): void
    {
        $this->authenticateAs(2);
        $concern = $this->createConcern();

        $this->patchJson(self::BASE_URI."/{$concern->id}", [
            'category' => 'coat',
            'severity' => GroomingMedicalConcern::SEVERITY_URGENT,
            'customer_message' => 'Corrected customer-safe facts.',
            'recommended_grooming_action' => GroomingMedicalConcern::ACTION_STOP_GROOMING,
            'acknowledgment_required' => true,
            'consent_required' => false,
            'internal_description' => 'Corrected internal observation.',
        ])
            ->assertOk()
            ->assertJsonPath('concern.category', 'coat')
            ->assertJsonPath('concern.customer_visible_fields_editable', true)
            ->assertJsonPath(
                'concern.customer_response_status',
                GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING,
            );

        DB::table('grooming_medical_concerns')
            ->where('id', $concern->id)
            ->update(['customer_notified_at' => now()]);

        $this->patchJson(self::BASE_URI."/{$concern->id}", [
            'customer_message' => 'Silently rewritten facts.',
        ])
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Customer-visible concern facts cannot be changed after notification or a customer response. Cancel this concern and create a corrected replacement.',
            );
        $this->assertDatabaseMissing('grooming_medical_concerns', [
            'id' => $concern->id,
            'customer_message' => 'Silently rewritten facts.',
        ]);

        $this->patchJson(self::BASE_URI."/{$concern->id}", [
            'internal_description' => 'Post-notification internal follow-up.',
            'internal_resolution_notes' => 'Observation continues.',
        ])
            ->assertOk()
            ->assertJsonPath('concern.customer_visible_fields_editable', false)
            ->assertJsonPath(
                'concern.internal_description',
                'Post-notification internal follow-up.',
            );

        $this->patchJson(self::BASE_URI."/{$concern->id}", [
            'booking_id' => 20,
            'reported_by_user_id' => 1,
            'status' => GroomingMedicalConcern::STATUS_RESOLVED,
            'report_token' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'booking_id',
                'reported_by_user_id',
                'status',
                'report_token',
            ]);
    }

    public function test_customer_response_locks_visible_fields_but_not_internal_notes(): void
    {
        $this->authenticateAs(2);
        $concern = $this->createConcern();
        $this->createResponse($concern);

        $this->patchJson(self::BASE_URI."/{$concern->id}", [
            'severity' => GroomingMedicalConcern::SEVERITY_URGENT,
        ])->assertConflict();

        $this->patchJson(self::BASE_URI."/{$concern->id}", [
            'internal_description' => 'Internal evidence follow-up.',
        ])
            ->assertOk()
            ->assertJsonPath('concern.has_customer_response', true)
            ->assertJsonPath('concern.customer_visible_fields_editable', false);

        $detail = $this->getJson(self::BASE_URI."/{$concern->id}")
            ->assertOk()
            ->json('concern');

        $this->assertSame($this->expectedResponseFields(), array_keys($detail));
        $this->assertArrayNotHasKey('report_token', $detail);
        $this->assertArrayNotHasKey('responses', $detail);
        $this->assertArrayNotHasKey('statement_text', $detail);
        $this->assertStringNotContainsString('staff@example.test', json_encode($detail));
        $this->assertStringNotContainsString('09170000002', json_encode($detail));
        $this->assertSame('acknowledgment', $detail['customer_response']['response_kind']);
        $this->assertSame('acknowledged', $detail['customer_response']['decision']);
        $this->assertSame('Pet Owner', $detail['customer_response']['responded_by_name']);
        $this->assertSame(now()->toIso8601String(), $detail['customer_response']['responded_at']);
        $this->assertArrayNotHasKey('signature_name', $detail['customer_response']);
        $this->assertArrayNotHasKey('responded_by_user_id', $detail['customer_response']);
    }

    public function test_cancel_requires_reason_preserves_evidence_and_sets_server_audit(): void
    {
        $this->authenticateAs(2);
        $concern = $this->createConcern();
        $response = $this->createResponse($concern);

        $this->postJson(self::BASE_URI."/{$concern->id}/cancel", [
            'internal_cancellation_reason' => '   ',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('internal_cancellation_reason');

        $this->postJson(self::BASE_URI."/{$concern->id}/cancel", [
            'internal_cancellation_reason' => 'Incorrect duplicate concern.',
            'customer_cancellation_summary' => 'This concern entry was corrected.',
            'resolved_by_user_id' => 1,
            'resolved_at' => '2020-01-01 00:00:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resolved_by_user_id', 'resolved_at']);

        $this->postJson(self::BASE_URI."/{$concern->id}/cancel", [
            'internal_cancellation_reason' => 'Incorrect duplicate concern.',
            'customer_cancellation_summary' => 'This concern entry was corrected.',
        ])
            ->assertOk()
            ->assertJsonPath('concern.status', GroomingMedicalConcern::STATUS_CANCELLED)
            ->assertJsonPath('concern.resolved_by_name', 'Staff Member')
            ->assertJsonPath('concern.resolved_at', now()->toIso8601String())
            ->assertJsonPath('concern.available_staff_actions', []);

        $this->assertDatabaseHas('grooming_medical_concerns', [
            'id' => $concern->id,
            'status' => GroomingMedicalConcern::STATUS_CANCELLED,
            'resolved_by_user_id' => 2,
            'internal_resolution_notes' => 'Incorrect duplicate concern.',
            'customer_resolution_summary' => 'This concern entry was corrected.',
        ]);
        $this->assertDatabaseHas('grooming_medical_concern_responses', [
            'id' => $response->id,
            'statement_text' => 'Immutable acknowledgment statement.',
        ]);

        $this->postJson(self::BASE_URI."/{$concern->id}/cancel", [
            'internal_cancellation_reason' => 'Again.',
        ])->assertConflict();
        $this->postJson(self::BASE_URI."/{$concern->id}/resolve", [
            'internal_resolution_notes' => 'Again.',
            'customer_resolution_summary' => 'Again.',
        ])->assertConflict();
        $this->patchJson(self::BASE_URI."/{$concern->id}", [
            'internal_description' => 'Terminal rewrite.',
        ])->assertConflict();
    }

    public function test_resolve_requires_both_summaries_and_sets_server_audit(): void
    {
        $this->authenticateAs(1);
        $concern = $this->createConcern();

        $this->postJson(self::BASE_URI."/{$concern->id}/resolve", [
            'internal_resolution_notes' => '   ',
            'customer_resolution_summary' => '   ',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'internal_resolution_notes',
                'customer_resolution_summary',
            ]);

        $this->postJson(self::BASE_URI."/{$concern->id}/resolve", [
            'internal_resolution_notes' => 'Area cleaned and monitored.',
            'customer_resolution_summary' => 'Your pet remained comfortable and was monitored.',
        ])
            ->assertOk()
            ->assertJsonPath('concern.status', GroomingMedicalConcern::STATUS_RESOLVED)
            ->assertJsonPath('concern.resolved_by_name', 'Admin User')
            ->assertJsonPath('concern.resolved_at', now()->toIso8601String())
            ->assertJsonPath('concern.staff_internal_fields_editable', false);

        $this->assertDatabaseHas('grooming_medical_concerns', [
            'id' => $concern->id,
            'status' => GroomingMedicalConcern::STATUS_RESOLVED,
            'resolved_by_user_id' => 1,
            'resolved_by_name' => 'Admin User',
            'internal_resolution_notes' => 'Area cleaned and monitored.',
            'customer_resolution_summary' => 'Your pet remained comfortable and was monitored.',
        ]);
        $this->postJson(self::BASE_URI."/{$concern->id}/resolve", [
            'internal_resolution_notes' => 'Again.',
            'customer_resolution_summary' => 'Again.',
        ])->assertConflict();
    }

    public function test_pause_and_resume_are_atomic_per_pet_and_preserve_start_time(): void
    {
        $this->authenticateAs(2);
        DB::table('bookings')->where('booking_id', 10)->update([
            'status' => 'in_progress',
        ]);
        DB::table('booking_pets')->where('booking_pet_id', 100)->update([
            'grooming_state' => BookingPet::GROOMING_STATE_IN_PROGRESS,
            'grooming_start_time' => '2026-07-24 13:00:00',
        ]);
        $concern = $this->createConcern([
            'recommended_grooming_action' => GroomingMedicalConcern::ACTION_PAUSE_GROOMING,
        ]);
        $countsBefore = $this->protectedModuleCounts();

        $this->postJson(self::BASE_URI."/{$concern->id}/apply-recommended-action")
            ->assertOk()
            ->assertJsonPath('safety_override_used', false)
            ->assertJsonPath('concern.applied_grooming_action', 'pause_grooming')
            ->assertJsonPath('concern.booking_pet_grooming_state', 'paused')
            ->assertJsonPath('concern.resume_grooming_available', true);

        $this->assertDatabaseHas('booking_pets', [
            'booking_pet_id' => 100,
            'grooming_state' => BookingPet::GROOMING_STATE_PAUSED,
            'grooming_start_time' => '2026-07-24 13:00:00',
            'grooming_end_time' => null,
        ]);
        $this->assertDatabaseHas('booking_pets', [
            'booking_pet_id' => 101,
            'grooming_state' => BookingPet::GROOMING_STATE_NOT_STARTED,
        ]);
        $this->assertDatabaseHas('bookings', [
            'booking_id' => 10,
            'status' => 'in_progress',
            'paid' => false,
        ]);
        $this->assertSame($countsBefore, $this->protectedModuleCounts());

        $this->postJson(self::BASE_URI."/{$concern->id}/apply-recommended-action")
            ->assertConflict();
        $this->postJson(self::BASE_URI."/{$concern->id}/cancel", [
            'internal_cancellation_reason' => 'Do not hide the applied pause.',
        ])->assertConflict();
        $this->postJson(self::BASE_URI."/{$concern->id}/resolve", [
            'internal_resolution_notes' => 'Attempted ordinary resolution.',
            'customer_resolution_summary' => 'Attempted resolution.',
        ])->assertConflict();

        $this->postJson(self::BASE_URI."/{$concern->id}/resume-grooming", [
            'internal_resolution_notes' => 'Area checked and safe to continue.',
            'customer_resolution_summary' => 'Grooming safely resumed.',
        ])
            ->assertOk()
            ->assertJsonPath('concern.status', GroomingMedicalConcern::STATUS_RESOLVED)
            ->assertJsonPath('concern.booking_pet_grooming_state', 'in_progress')
            ->assertJsonPath('concern.applied_grooming_action', 'pause_grooming');

        $this->assertDatabaseHas('booking_pets', [
            'booking_pet_id' => 100,
            'grooming_state' => BookingPet::GROOMING_STATE_IN_PROGRESS,
            'grooming_start_time' => '2026-07-24 13:00:00',
            'grooming_end_time' => null,
        ]);
        $this->assertDatabaseHas('grooming_medical_concerns', [
            'id' => $concern->id,
            'status' => GroomingMedicalConcern::STATUS_RESOLVED,
            'resolved_by_user_id' => 2,
            'customer_resolution_summary' => 'Grooming safely resumed.',
        ]);
        $this->postJson(self::BASE_URI."/{$concern->id}/resume-grooming", [
            'internal_resolution_notes' => 'Duplicate.',
            'customer_resolution_summary' => 'Duplicate.',
        ])->assertConflict();
    }

    public function test_safety_override_is_required_audited_and_does_not_fabricate_consent(): void
    {
        $this->authenticateAs(1);
        DB::table('bookings')->where('booking_id', 10)->update([
            'status' => 'in_progress',
        ]);
        DB::table('booking_pets')->where('booking_pet_id', 100)->update([
            'grooming_state' => BookingPet::GROOMING_STATE_IN_PROGRESS,
            'grooming_start_time' => '2026-07-24 13:15:00',
        ]);
        $concern = $this->createConcern([
            'recommended_grooming_action' => GroomingMedicalConcern::ACTION_STOP_GROOMING,
            'consent_required' => true,
            'customer_response_status' => GroomingMedicalConcern::CUSTOMER_RESPONSE_DECLINED,
            'internal_resolution_notes' => 'Earlier internal note.',
        ]);
        $responsesBefore = DB::table('grooming_medical_concern_responses')->count();

        $this->postJson(self::BASE_URI."/{$concern->id}/apply-recommended-action")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('safety_override_reason');

        $this->postJson(self::BASE_URI."/{$concern->id}/apply-recommended-action", [
            'safety_override_reason' => 'Immediate stop required to protect the pet.',
        ])
            ->assertOk()
            ->assertJsonPath('safety_override_used', true)
            ->assertJsonPath('concern.booking_pet_grooming_state', 'stopped')
            ->assertJsonPath(
                'concern.customer_response_status',
                GroomingMedicalConcern::CUSTOMER_RESPONSE_DECLINED,
            );

        $stored = GroomingMedicalConcern::query()->findOrFail($concern->id);
        $this->assertStringContainsString('Earlier internal note.', $stored->internal_resolution_notes);
        $this->assertStringContainsString('Safety override', $stored->internal_resolution_notes);
        $this->assertStringContainsString('Admin User', $stored->internal_resolution_notes);
        $this->assertStringContainsString(
            'Immediate stop required to protect the pet.',
            $stored->internal_resolution_notes,
        );
        $this->assertSame(
            $responsesBefore,
            DB::table('grooming_medical_concern_responses')->count(),
        );
        $this->assertDatabaseHas('booking_pets', [
            'booking_pet_id' => 100,
            'grooming_state' => BookingPet::GROOMING_STATE_STOPPED,
            'grooming_start_time' => '2026-07-24 13:15:00',
            'grooming_end_time' => null,
        ]);
        $this->assertDatabaseHas('bookings', [
            'booking_id' => 10,
            'status' => 'in_progress',
            'paid' => false,
        ]);

        $this->patchJson(self::BASE_URI."/{$concern->id}", [
            'internal_resolution_notes' => 'Overwrite audit.',
        ])->assertConflict();
        $this->patchJson(self::BASE_URI."/{$concern->id}", [
            'recommended_grooming_action' => GroomingMedicalConcern::ACTION_PAUSE_GROOMING,
        ])->assertConflict();
        $this->postJson(self::BASE_URI."/{$concern->id}/resume-grooming", [
            'internal_resolution_notes' => 'Should not resume.',
            'customer_resolution_summary' => 'Should not resume.',
        ])->assertConflict();

        $this->postJson(self::BASE_URI."/{$concern->id}/resolve", [
            'internal_resolution_notes' => 'Stopped workflow handed to staff review.',
            'customer_resolution_summary' => 'Grooming remains stopped.',
        ])->assertOk();
        $this->assertDatabaseHas('booking_pets', [
            'booking_pet_id' => 100,
            'grooming_state' => BookingPet::GROOMING_STATE_STOPPED,
            'grooming_end_time' => null,
        ]);
    }

    public function test_continue_requires_the_configured_response_and_never_changes_grooming_state(): void
    {
        $this->authenticateAs(2);
        $concern = $this->createConcern([
            'recommended_grooming_action' => GroomingMedicalConcern::ACTION_CONTINUE_WITH_OBSERVATION,
            'acknowledgment_required' => true,
            'customer_response_status' => GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING,
        ]);

        $this->postJson(self::BASE_URI."/{$concern->id}/apply-recommended-action", [
            'safety_override_reason' => 'Continue cannot use an override.',
        ])->assertConflict();

        $concern->forceFill([
            'customer_response_status' => GroomingMedicalConcern::CUSTOMER_RESPONSE_ACKNOWLEDGED,
        ])->save();

        $this->postJson(self::BASE_URI."/{$concern->id}/apply-recommended-action")
            ->assertOk()
            ->assertJsonPath('concern.applied_grooming_action', 'continue_with_observation')
            ->assertJsonPath('concern.booking_pet_grooming_state', 'not_started');

        $this->assertDatabaseHas('booking_pets', [
            'booking_pet_id' => 100,
            'grooming_state' => BookingPet::GROOMING_STATE_NOT_STARTED,
            'grooming_start_time' => null,
            'grooming_end_time' => null,
        ]);
    }

    public function test_concern_lifecycle_has_no_grooming_payment_notification_clinic_or_inventory_side_effects(): void
    {
        $this->authenticateAs(2);

        $bookingBefore = DB::table('bookings')->where('booking_id', 10)->first();
        $bookingPetBefore = DB::table('booking_pets')->where('booking_pet_id', 100)->first();
        $countsBefore = $this->protectedModuleCounts();
        $capacityBefore = DB::table('clinic_settings')->where('id', 1)->value('groomers_on_duty');
        $inventoryBefore = DB::table('inventory_items')->where('item_id', 1)->first();

        $created = $this->postJson(self::BASE_URI, $this->validPayload())
            ->assertCreated();
        $concernId = $created->json('concern.id');

        $this->patchJson(self::BASE_URI."/{$concernId}", [
            'internal_description' => 'Updated staff observation.',
        ])->assertOk();
        $this->postJson(self::BASE_URI."/{$concernId}/resolve", [
            'internal_resolution_notes' => 'Resolved without workflow side effects.',
            'customer_resolution_summary' => 'The concern was resolved.',
        ])->assertOk();

        $bookingAfter = DB::table('bookings')->where('booking_id', 10)->first();
        $bookingPetAfter = DB::table('booking_pets')->where('booking_pet_id', 100)->first();

        $this->assertSame($bookingBefore->status, $bookingAfter->status);
        $this->assertSame($bookingBefore->paid, $bookingAfter->paid);
        $this->assertSame($bookingBefore->total_amount, $bookingAfter->total_amount);
        $this->assertSame($bookingBefore->grooming_started_at, $bookingAfter->grooming_started_at);
        $this->assertSame($bookingBefore->grooming_finished_at, $bookingAfter->grooming_finished_at);
        $this->assertSame($bookingPetBefore->grooming_state, $bookingPetAfter->grooming_state);
        $this->assertSame($bookingPetBefore->grooming_start_time, $bookingPetAfter->grooming_start_time);
        $this->assertSame($bookingPetBefore->grooming_end_time, $bookingPetAfter->grooming_end_time);
        $this->assertSame($countsBefore, $this->protectedModuleCounts());
        $this->assertSame(
            $capacityBefore,
            DB::table('clinic_settings')->where('id', 1)->value('groomers_on_duty'),
        );
        $this->assertEquals(
            $inventoryBefore,
            DB::table('inventory_items')->where('item_id', 1)->first(),
        );
        $this->assertDatabaseHas('grooming_medical_concerns', [
            'id' => $concernId,
            'applied_grooming_action' => null,
            'action_applied_at' => null,
            'action_applied_by_user_id' => null,
            'clinic_appointment_id' => null,
            'customer_notified_at' => null,
        ]);
    }

    private function createExistingSchema(): void
    {
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
            $table->boolean('is_archived')->default(false);
            $table->foreign('user_id')->references('user_id')->on('users')->nullOnDelete();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->increments('booking_id');
            $table->string('booking_reference')->unique();
            $table->unsignedInteger('user_id')->nullable();
            $table->string('status');
            $table->boolean('paid')->default(false);
            $table->decimal('total_amount', 8, 2)->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('grooming_started_at')->nullable();
            $table->timestamp('grooming_finished_at')->nullable();
            $table->timestamps();
            $table->foreign('user_id')->references('user_id')->on('users')->nullOnDelete();
        });

        Schema::create('booking_pets', function (Blueprint $table) {
            $table->increments('booking_pet_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('pet_id')->nullable();
            $table->dateTime('grooming_start_time')->nullable();
            $table->dateTime('grooming_end_time')->nullable();
            $table->foreign('booking_id')->references('booking_id')->on('bookings')->cascadeOnDelete();
            $table->foreign('pet_id')->references('pet_id')->on('pets')->nullOnDelete();
        });

        Schema::create('clinic_appointments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('pet_id')->nullable();
            $table->string('appointment_reference')->unique();
            $table->foreign('pet_id')->references('pet_id')->on('pets')->nullOnDelete();
        });

        Schema::create('customer_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('booking_id')->nullable();
            $table->string('type', 50);
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->increments('notification_id');
            $table->string('type');
            $table->unsignedInteger('booking_id');
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->increments('payment_id');
            $table->unsignedInteger('booking_id')->nullable();
            $table->decimal('total_amount', 8, 2)->default(0);
            $table->string('payment_status');
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->increments('item_id');
            $table->string('item_name');
            $table->integer('quantity_on_hand')->default(0);
        });

        Schema::create('clinic_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('groomers_on_duty')->default(1);
        });
    }

    private function seedExistingData(): void
    {
        DB::table('users')->insert([
            [
                'user_id' => 1,
                'first_name' => 'Admin',
                'last_name' => 'User',
                'email' => 'admin@example.test',
                'phone' => '09170000001',
                'role' => 'admin',
            ],
            [
                'user_id' => 2,
                'first_name' => 'Staff',
                'last_name' => 'Member',
                'email' => 'staff@example.test',
                'phone' => '09170000002',
                'role' => 'staff',
            ],
            [
                'user_id' => 3,
                'first_name' => 'Customer',
                'last_name' => 'User',
                'email' => 'customer@example.test',
                'phone' => '09170000003',
                'role' => 'customer',
            ],
            [
                'user_id' => 4,
                'first_name' => 'Pet',
                'last_name' => 'Owner',
                'email' => null,
                'phone' => null,
                'role' => 'customer',
            ],
        ]);
        DB::table('pets')->insert([
            ['pet_id' => 1000, 'user_id' => 4, 'pet_name' => 'Zeus', 'species' => 'Dog'],
            ['pet_id' => 1001, 'user_id' => 4, 'pet_name' => 'Ginger', 'species' => 'Cat'],
            ['pet_id' => 1002, 'user_id' => 4, 'pet_name' => 'Milo', 'species' => 'Dog'],
        ]);
        DB::table('bookings')->insert([
            [
                'booking_id' => 10,
                'booking_reference' => 'BOOKING-10',
                'user_id' => 4,
                'status' => 'checked_in',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'booking_id' => 20,
                'booking_reference' => 'BOOKING-20',
                'user_id' => 4,
                'status' => 'checked_in',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('booking_pets')->insert([
            [
                'booking_pet_id' => 100,
                'booking_id' => 10,
                'pet_id' => 1000,
                'grooming_state' => BookingPet::GROOMING_STATE_NOT_STARTED,
            ],
            [
                'booking_pet_id' => 101,
                'booking_id' => 10,
                'pet_id' => 1001,
                'grooming_state' => BookingPet::GROOMING_STATE_NOT_STARTED,
            ],
            [
                'booking_pet_id' => 200,
                'booking_id' => 20,
                'pet_id' => 1002,
                'grooming_state' => BookingPet::GROOMING_STATE_NOT_STARTED,
            ],
        ]);
        DB::table('clinic_appointments')->insert([
            'id' => 500,
            'pet_id' => 1000,
            'appointment_reference' => 'CL-500',
        ]);
        DB::table('customer_notifications')->insert([
            'id' => 1,
            'user_id' => 4,
            'booking_id' => 10,
            'type' => 'grooming_started',
            'message' => 'Existing customer notification.',
            'created_at' => now(),
        ]);
        DB::table('notifications')->insert([
            'notification_id' => 1,
            'type' => 'booked',
            'booking_id' => 10,
            'message' => 'Existing staff notification.',
            'created_at' => now(),
        ]);
        DB::table('payments')->insert([
            'payment_id' => 1,
            'booking_id' => 20,
            'total_amount' => 500,
            'payment_status' => 'paid',
        ]);
        DB::table('inventory_items')->insert([
            'item_id' => 1,
            'item_name' => 'Existing inventory',
            'quantity_on_hand' => 10,
        ]);
        DB::table('clinic_settings')->insert([
            'id' => 1,
            'groomers_on_duty' => 2,
        ]);
    }

    private function authenticateAs(int $userId): void
    {
        Sanctum::actingAs(User::query()->findOrFail($userId), ['*']);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'category' => 'skin',
            'severity' => GroomingMedicalConcern::SEVERITY_MODERATE,
            'internal_description' => 'A factual internal grooming observation.',
            'customer_message' => 'We noticed an area that needs attention.',
            'recommended_grooming_action' => GroomingMedicalConcern::ACTION_PAUSE_GROOMING,
        ], $overrides);
    }

    private function createConcern(array $overrides = []): GroomingMedicalConcern
    {
        return GroomingMedicalConcern::create(array_merge([
            'booking_id' => 10,
            'booking_pet_id' => 100,
            'pet_id' => 1000,
            'reported_by_user_id' => 2,
            'reported_by_name' => 'Staff Member',
            'reported_at' => now(),
            'category' => 'skin',
            'severity' => GroomingMedicalConcern::SEVERITY_MODERATE,
            'internal_description' => 'A factual internal grooming observation.',
            'customer_message' => 'We noticed an area that needs attention.',
            'recommended_grooming_action' => GroomingMedicalConcern::ACTION_PAUSE_GROOMING,
            'status' => GroomingMedicalConcern::STATUS_OPEN,
            'customer_response_status' => GroomingMedicalConcern::CUSTOMER_RESPONSE_NOT_REQUIRED,
        ], $overrides));
    }

    private function createResponse(
        GroomingMedicalConcern $concern,
    ): GroomingMedicalConcernResponse {
        return GroomingMedicalConcernResponse::create([
            'concern_id' => $concern->id,
            'responded_by_user_id' => 4,
            'responded_by_name' => 'Pet Owner',
            'response_kind' => GroomingMedicalConcernResponse::KIND_ACKNOWLEDGMENT,
            'decision' => GroomingMedicalConcernResponse::DECISION_ACKNOWLEDGED,
            'statement_text' => 'Immutable acknowledgment statement.',
            'statement_version' => 'v1',
            'responded_at' => now(),
        ]);
    }

    private function protectedModuleCounts(): array
    {
        return [
            'customer_notifications' => DB::table('customer_notifications')->count(),
            'staff_notifications' => DB::table('notifications')->count(),
            'responses' => DB::table('grooming_medical_concern_responses')->count(),
            'clinic_appointments' => DB::table('clinic_appointments')->count(),
            'payments' => DB::table('payments')->count(),
            'inventory_items' => DB::table('inventory_items')->count(),
        ];
    }

    private function expectedResponseFields(): array
    {
        return [
            'id',
            'public_id',
            'booking_id',
            'booking_reference',
            'booking_pet_id',
            'pet_id',
            'pet_name',
            'pet_species',
            'reported_by_name',
            'reported_at',
            'category',
            'severity',
            'internal_description',
            'customer_message',
            'recommended_grooming_action',
            'recommended_action_is_advisory',
            'applied_grooming_action',
            'action_applied_at',
            'action_applied_by_user_id',
            'action_applied_by_name',
            'booking_pet_grooming_state',
            'booking_pet_grooming_state_label',
            'recommended_action_can_be_applied',
            'recommended_action_blocked_reason',
            'safety_override_available',
            'safety_override_required',
            'resume_grooming_available',
            'resume_grooming_blocked_reason',
            'customer_response_requirement',
            'status',
            'acknowledgment_required',
            'consent_required',
            'customer_response_status',
            'customer_notified_at',
            'has_customer_response',
            'customer_response',
            'clinic_appointment_id',
            'clinic_appointment_reference',
            'customer_resolution_summary',
            'internal_resolution_notes',
            'resolved_by_name',
            'resolved_at',
            'created_at',
            'updated_at',
            'customer_visible_fields_editable',
            'recommended_action_editable',
            'customer_account_linked',
            'staff_internal_fields_editable',
            'internal_resolution_notes_editable',
            'available_staff_actions',
        ];
    }
}
