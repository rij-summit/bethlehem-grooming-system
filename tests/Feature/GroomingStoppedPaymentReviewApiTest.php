<?php

namespace Tests\Feature;

use App\Models\BookingPet;
use App\Models\GroomingMedicalConcern;
use App\Models\GroomingStoppedPaymentReview;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GroomingStoppedPaymentReviewApiTest extends TestCase
{
    private $reviewMigration;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON');
        Carbon::setTestNow('2026-08-01 11:30:00');
        $this->createSchema();
        $this->seedContext();

        $this->reviewMigration = require base_path(
            'database/migrations/2026_08_01_000001_create_grooming_stopped_payment_reviews_table.php',
        );
        $this->reviewMigration->up();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Schema::disableForeignKeyConstraints();

        foreach ([
            'grooming_stopped_payment_reviews',
            'grooming_medical_concern_responses',
            'grooming_medical_concerns',
            'customer_notifications',
            'notifications',
            'payments',
            'booking_services',
            'booking_pets',
            'clinic_appointments',
            'inventory_transactions',
            'addons',
            'services',
            'bookings',
            'pets',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
        parent::tearDown();
    }

    public function test_review_routes_require_authentication_and_reject_customers(): void
    {
        $this->getJson($this->showUri())->assertUnauthorized();
        $this->postJson($this->storeUri(), $this->fullChargePayload())->assertUnauthorized();

        $this->authenticateAs('customer');
        $this->getJson($this->showUri())->assertForbidden();
        $this->postJson($this->storeUri(), $this->fullChargePayload())->assertForbidden();
    }

    #[DataProvider('authorizedRoles')]
    public function test_staff_and_admin_can_view_and_complete_reviews(string $role): void
    {
        $this->authenticateAs($role);

        $this->getJson($this->showUri())
            ->assertOk()
            ->assertJsonPath('review.review_status', 'pending')
            ->assertJsonPath('review.review_creation_allowed', true);

        $this->postJson($this->storeUri(), $this->fullChargePayload())
            ->assertCreated()
            ->assertJsonPath('review.review_status', 'completed')
            ->assertJsonPath('review.decision', 'full_charge');
    }

    public static function authorizedRoles(): array
    {
        return [
            'staff' => ['staff'],
            'administrator' => ['admin'],
        ];
    }

    public function test_routes_have_required_middleware_and_no_mutation_route_exists(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());
        $expected = [
            'api/admin/bookings/{bookingId}/pets/{bookingPetId}/stopped-payment-review' => ['GET', 'HEAD'],
            'api/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns/{concernId}/stopped-payment-review' => ['POST'],
        ];

        foreach ($expected as $uri => $methods) {
            $route = $routes->first(fn ($route) => $route->uri() === $uri);
            $this->assertNotNull($route);
            $this->assertSame($methods, $route->methods());
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth:sanctum', $middleware);
            $this->assertContains('role:admin,staff', $middleware);
        }

        $this->authenticateAs('staff');
        $this->patchJson($this->showUri(), [])->assertMethodNotAllowed();
        $this->deleteJson($this->showUri())->assertMethodNotAllowed();
    }

    public function test_nested_scoping_is_generic_and_multi_pet_data_is_isolated(): void
    {
        $this->authenticateAs('staff');

        $this->getJson('/api/admin/bookings/201/pets/303/stopped-payment-review')
            ->assertNotFound()
            ->assertJsonPath('message', 'Grooming booking pet not found.');
        $this->postJson(
            '/api/admin/bookings/201/pets/301/medical-concerns/402/stopped-payment-review',
            $this->fullChargePayload(),
        )
            ->assertNotFound()
            ->assertJsonPath('message', 'Medical concern not found.');

        $response = $this->getJson($this->showUri())
            ->assertOk()
            ->assertJsonPath('review.original_pet_subtotal', '625.00')
            ->assertJsonCount(2, 'review.service_breakdown');
        $lineIds = collect($response->json('review.service_breakdown'))
            ->pluck('booking_service_id');
        $this->assertSame([501, 502], $lineIds->all());
        $this->assertFalse($lineIds->contains(503));
    }

    #[DataProvider('ineligiblePetStates')]
    public function test_non_stopped_pet_states_are_rejected(string $state): void
    {
        $this->authenticateAs('staff');
        DB::table('booking_pets')->where('booking_pet_id', 301)->update([
            'grooming_state' => $state,
        ]);

        $this->postJson($this->storeUri(), $this->fullChargePayload())
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Only a stopped grooming pet requires this payment review.');
    }

    public static function ineligiblePetStates(): array
    {
        return [
            'not started' => [BookingPet::GROOMING_STATE_NOT_STARTED],
            'in progress' => [BookingPet::GROOMING_STATE_IN_PROGRESS],
            'paused' => [BookingPet::GROOMING_STATE_PAUSED],
            'finished' => [BookingPet::GROOMING_STATE_FINISHED],
        ];
    }

    public function test_normal_finish_timestamp_and_invalid_applied_action_audit_are_rejected(): void
    {
        $this->authenticateAs('staff');
        DB::table('booking_pets')->where('booking_pet_id', 301)->update([
            'grooming_end_time' => '2026-08-01 11:00:00',
        ]);
        $this->postJson($this->storeUri(), $this->fullChargePayload())
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'A stopped pet with a normal grooming finish timestamp cannot be reviewed.',
            );

        DB::table('booking_pets')->where('booking_pet_id', 301)->update([
            'grooming_end_time' => null,
        ]);
        foreach ([
            ['applied_grooming_action' => null],
            ['applied_grooming_action' => GroomingMedicalConcern::ACTION_PAUSE_GROOMING],
            ['recommended_grooming_action' => GroomingMedicalConcern::ACTION_PAUSE_GROOMING],
            ['action_applied_at' => null],
            ['action_applied_by_user_id' => null],
        ] as $invalidAudit) {
            $original = DB::table('grooming_medical_concerns')->where('id', 401)->first();
            DB::table('grooming_medical_concerns')->where('id', 401)->update($invalidAudit);
            $this->postJson($this->storeUri(), $this->fullChargePayload())
                ->assertUnprocessable()
                ->assertJsonPath(
                    'message',
                    'The selected concern does not contain a fully audited applied Stop Grooming action.',
                );
            DB::table('grooming_medical_concerns')->where('id', 401)->update([
                'recommended_grooming_action' => $original->recommended_grooming_action,
                'applied_grooming_action' => $original->applied_grooming_action,
                'action_applied_at' => $original->action_applied_at,
                'action_applied_by_user_id' => $original->action_applied_by_user_id,
            ]);
        }
    }

    #[DataProvider('invalidBookingStates')]
    public function test_inactive_paid_and_archived_bookings_are_rejected(
        string $status,
        bool $paid,
        ?string $archivedAt,
    ): void {
        $this->authenticateAs('staff');
        DB::table('bookings')->where('booking_id', 201)->update([
            'status' => $status,
            'paid' => $paid,
            'archived_at' => $archivedAt,
        ]);

        $this->postJson($this->storeUri(), $this->fullChargePayload())
            ->assertUnprocessable();
    }

    public static function invalidBookingStates(): array
    {
        return [
            'cancelled' => ['cancelled', false, null],
            'no show' => ['no_show', false, null],
            'released' => ['released', false, null],
            'archived status' => ['archived', false, '2026-08-01 10:00:00'],
            'archived timestamp' => ['in_progress', false, '2026-08-01 10:00:00'],
            'already paid' => ['in_progress', true, null],
        ];
    }

    public function test_resolved_applied_stop_concern_remains_eligible_but_cancelled_does_not(): void
    {
        $this->authenticateAs('staff');
        DB::table('grooming_medical_concerns')->where('id', 401)->update([
            'status' => GroomingMedicalConcern::STATUS_RESOLVED,
        ]);
        $this->postJson($this->storeUri(), $this->fullChargePayload())
            ->assertCreated();

        DB::table('grooming_stopped_payment_reviews')->delete();
        DB::table('grooming_medical_concerns')->where('id', 401)->update([
            'status' => GroomingMedicalConcern::STATUS_CANCELLED,
        ]);
        $this->postJson($this->storeUri(), $this->fullChargePayload())
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'A cancelled concern cannot support a stopped-grooming payment review.',
            );
    }

    public function test_subtotal_is_server_calculated_and_service_prices_are_unchanged(): void
    {
        $this->authenticateAs('staff');
        $before = DB::table('booking_services')
            ->orderBy('booking_service_id')
            ->pluck('price_at_booking', 'booking_service_id')
            ->all();

        $this->postJson($this->storeUri(), array_merge(
            $this->fullChargePayload(),
            ['original_pet_subtotal' => '1.00'],
        ))->assertUnprocessable()->assertJsonValidationErrors('original_pet_subtotal');

        $this->postJson($this->storeUri(), $this->fullChargePayload())
            ->assertCreated()
            ->assertJsonPath('review.original_pet_subtotal', '625.00')
            ->assertJsonPath('review.final_pet_charge', '625.00');

        $this->assertSame(
            $before,
            DB::table('booking_services')
                ->orderBy('booking_service_id')
                ->pluck('price_at_booking', 'booking_service_id')
                ->all(),
        );
    }

    public function test_zero_service_pet_requires_no_charge(): void
    {
        $this->authenticateAs('staff');
        DB::table('booking_services')->where('booking_pet_id', 301)->delete();

        $this->getJson($this->showUri())
            ->assertOk()
            ->assertJsonPath('review.original_pet_subtotal', '0.00')
            ->assertJsonCount(0, 'review.service_breakdown');
        $this->postJson($this->storeUri(), $this->fullChargePayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('decision');
        $this->postJson($this->storeUri(), $this->noChargePayload())
            ->assertCreated()
            ->assertJsonPath('review.final_pet_charge', '0.00');
    }

    public function test_full_partial_and_no_charge_rules_are_server_enforced(): void
    {
        $this->authenticateAs('staff');

        $this->postJson($this->storeUri(), array_merge(
            $this->fullChargePayload(),
            ['final_pet_charge' => '500.00'],
        ))->assertUnprocessable()->assertJsonValidationErrors('final_pet_charge');

        $this->postJson($this->storeUri(), $this->partialChargePayload('300.25'))
            ->assertCreated()
            ->assertJsonPath('review.final_pet_charge', '300.25')
            ->assertJsonPath('review.adjustment', '324.75');

        DB::table('grooming_stopped_payment_reviews')->delete();
        $this->postJson($this->storeUri(), array_merge(
            $this->noChargePayload(),
            ['final_pet_charge' => '1.00'],
        ))->assertUnprocessable()->assertJsonValidationErrors('final_pet_charge');
        $this->postJson($this->storeUri(), $this->noChargePayload())
            ->assertCreated()
            ->assertJsonPath('review.final_pet_charge', '0.00');
    }

    #[DataProvider('invalidPartialAmounts')]
    public function test_invalid_partial_amounts_are_rejected(string $amount): void
    {
        $this->authenticateAs('staff');

        $this->postJson($this->storeUri(), $this->partialChargePayload($amount))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('final_pet_charge');
    }

    public static function invalidPartialAmounts(): array
    {
        return [
            'negative' => ['-1.00'],
            'zero' => ['0.00'],
            'equal to original' => ['625.00'],
            'above original' => ['700.00'],
            'more than two decimals' => ['100.001'],
        ];
    }

    public function test_required_explanations_are_trimmed_and_audit_is_server_controlled(): void
    {
        $this->authenticateAs('staff');

        $this->postJson($this->storeUri(), $this->fullChargePayload([
            'internal_reason' => '   ',
            'customer_explanation' => '   ',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['internal_reason', 'customer_explanation']);

        $response = $this->postJson($this->storeUri(), $this->fullChargePayload([
            'internal_reason' => '  Safety review completed.  ',
            'customer_explanation' => '  Charge reflects completed work.  ',
        ]))
            ->assertCreated()
            ->assertJsonPath('review.reviewed_by_name', 'Staff Reviewer')
            ->assertJsonPath('review.reviewed_at', now()->toIso8601String());

        $review = GroomingStoppedPaymentReview::findOrFail(
            $response->json('review.review_id'),
        );
        $this->assertSame(2, $review->reviewed_by_user_id);
        $this->assertSame('Staff Reviewer', $review->reviewed_by_name);
        $this->assertSame('Safety review completed.', $review->internal_reason);
        $this->assertSame('Charge reflects completed work.', $review->customer_explanation);
    }

    public function test_exact_retry_is_idempotent_and_conflicting_retry_returns_conflict(): void
    {
        $this->authenticateAs('staff');
        $payload = $this->partialChargePayload('250.00');

        $first = $this->postJson($this->storeUri(), $payload)
            ->assertCreated()
            ->json('review');
        $this->postJson($this->storeUri(), $payload)
            ->assertOk()
            ->assertJsonPath('review.already_reviewed', true)
            ->assertJsonPath('review.review_id', $first['review_id']);
        $this->assertSame(1, GroomingStoppedPaymentReview::count());

        $this->postJson($this->storeUri(), array_merge($payload, [
            'internal_reason' => 'A conflicting reason.',
        ]))
            ->assertConflict();
        $this->assertSame(1, GroomingStoppedPaymentReview::count());
        $this->assertSame('Stopped before completion.', $first['internal_reason']);
    }

    public function test_response_is_an_explicit_staff_whitelist(): void
    {
        $this->authenticateAs('staff');
        $review = $this->postJson($this->storeUri(), $this->fullChargePayload())
            ->assertCreated()
            ->json('review');

        $this->assertSame([
            'review_status',
            'review_id',
            'booking_reference',
            'booking_pet_id',
            'pet_id',
            'pet_name',
            'pet_species',
            'grooming_state',
            'grooming_state_label',
            'review_creation_allowed',
            'review_creation_blocked_reason',
            'service_breakdown',
            'original_pet_subtotal',
            'concern_id',
            'concern_public_id',
            'applied_stop_grooming_at',
            'decision',
            'decision_label',
            'final_pet_charge',
            'adjustment',
            'internal_reason',
            'customer_explanation',
            'reviewed_by_name',
            'reviewed_at',
            'already_reviewed',
            'immutable',
            'payment_integration_pending',
        ], array_keys($review));
        $this->assertArrayNotHasKey('user', $review);
        $this->assertArrayNotHasKey('concern', $review);
        $this->assertArrayNotHasKey('customer_response', $review);
    }

    #[DataProvider('payNowBlockedStates')]
    public function test_pay_now_rejects_paused_and_stopped_pets(string $state): void
    {
        $this->authenticateAs('staff');
        DB::table('booking_pets')->where('booking_pet_id', 301)->update([
            'grooming_state' => $state,
        ]);

        $this->postJson('/api/admin/bookings/201/pay-now', $this->paymentPayload())
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                "Pay Now is unavailable while a booking pet is paused or stopped. Alpha is {$this->stateLabel($state)}.",
            );
        $this->assertSame(0, DB::table('payments')->count());
    }

    public static function payNowBlockedStates(): array
    {
        return [
            'paused' => [BookingPet::GROOMING_STATE_PAUSED],
            'stopped' => [BookingPet::GROOMING_STATE_STOPPED],
        ];
    }

    public function test_completed_review_does_not_enable_pay_now_or_normal_payment(): void
    {
        $this->authenticateAs('staff');
        $this->postJson($this->storeUri(), $this->fullChargePayload())
            ->assertCreated();

        $this->postJson('/api/admin/bookings/201/pay-now', $this->paymentPayload())
            ->assertUnprocessable();
        DB::table('bookings')->where('booking_id', 201)->update(['status' => 'for_payment']);
        $this->postJson('/api/admin/bookings/201/pay', $this->paymentPayload())
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Normal payment is available only after every booking pet is finished. Alpha is Stopped.',
            );
        $this->assertSame(0, DB::table('payments')->count());
    }

    #[DataProvider('releaseBlockedStates')]
    public function test_release_rejects_every_non_finished_pet_state(string $state): void
    {
        $this->authenticateAs('staff');
        DB::table('bookings')->where('booking_id', 201)->update([
            'status' => 'for_payment',
            'paid' => true,
        ]);
        DB::table('booking_pets')->where('booking_pet_id', 301)->update([
            'grooming_state' => $state,
        ]);

        $this->postJson('/api/admin/bookings/201/release')
            ->assertUnprocessable();
        $this->assertSame(
            'for_payment',
            DB::table('bookings')->where('booking_id', 201)->value('status'),
        );
    }

    public static function releaseBlockedStates(): array
    {
        return [
            'not started' => [BookingPet::GROOMING_STATE_NOT_STARTED],
            'in progress' => [BookingPet::GROOMING_STATE_IN_PROGRESS],
            'paused' => [BookingPet::GROOMING_STATE_PAUSED],
            'stopped' => [BookingPet::GROOMING_STATE_STOPPED],
        ];
    }

    public function test_manual_released_status_cannot_bypass_final_pickup_guards(): void
    {
        $this->authenticateAs('staff');
        DB::table('bookings')->where('booking_id', 201)->update([
            'status' => 'released',
            'paid' => true,
        ]);

        $this->postJson('/api/admin/bookings/201/picked-up')
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Pickup completion is unavailable until every booking pet is finished. Alpha is Stopped.',
            );
        $this->postJson('/api/admin/bookings/201/archive')
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Final pickup progression is unavailable until every booking pet is finished. Alpha is Stopped.',
            );
        $this->assertSame(
            'released',
            DB::table('bookings')->where('booking_id', 201)->value('status'),
        );
    }

    public function test_all_finished_normal_payment_and_release_remain_available(): void
    {
        $this->authenticateAs('staff');
        DB::table('booking_pets')->where('booking_id', 201)->update([
            'grooming_state' => BookingPet::GROOMING_STATE_FINISHED,
            'grooming_end_time' => '2026-08-01 11:00:00',
        ]);
        DB::table('bookings')->where('booking_id', 201)->update([
            'status' => 'for_payment',
        ]);

        $this->postJson('/api/admin/bookings/201/pay', $this->paymentPayload())
            ->assertOk();
        $this->assertDatabaseHas('payments', [
            'booking_id' => 201,
            'payment_status' => 'paid',
        ]);

        DB::table('bookings')->where('booking_id', 201)->update([
            'status' => 'for_payment',
            'paid' => true,
            'archived_at' => null,
        ]);
        $this->postJson('/api/admin/bookings/201/release')->assertOk();
    }

    public function test_review_creation_has_no_payment_workflow_or_unrelated_side_effects(): void
    {
        $this->authenticateAs('staff');
        $booking = DB::table('bookings')->where('booking_id', 201)->first();
        $bookingPet = DB::table('booking_pets')->where('booking_pet_id', 301)->first();
        $concern = DB::table('grooming_medical_concerns')->where('id', 401)->first();
        $servicePrices = DB::table('booking_services')
            ->pluck('price_at_booking', 'booking_service_id')
            ->all();
        $counts = collect([
            'payments',
            'notifications',
            'customer_notifications',
            'grooming_medical_concern_responses',
            'inventory_transactions',
            'clinic_appointments',
        ])->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]);

        $this->postJson($this->storeUri(), $this->partialChargePayload('300.00'))
            ->assertCreated();

        $this->assertEquals($booking, DB::table('bookings')->where('booking_id', 201)->first());
        $this->assertEquals($bookingPet, DB::table('booking_pets')->where('booking_pet_id', 301)->first());
        $this->assertEquals($concern, DB::table('grooming_medical_concerns')->where('id', 401)->first());
        $this->assertSame(
            $servicePrices,
            DB::table('booking_services')
                ->pluck('price_at_booking', 'booking_service_id')
                ->all(),
        );
        foreach ($counts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table);
        }
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('password_hash')->nullable();
            $table->string('role');
            $table->boolean('is_active')->default(true);
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
            $table->string('booking_reference');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('status');
            $table->boolean('paid')->default(false);
            $table->decimal('total_amount', 8, 2)->default(0);
            $table->dateTime('archived_at')->nullable();
            $table->dateTime('grooming_finished_at')->nullable();
            $table->foreign('user_id')->references('user_id')->on('users')->nullOnDelete();
        });
        Schema::create('booking_pets', function (Blueprint $table) {
            $table->increments('booking_pet_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('pet_id')->nullable();
            $table->dateTime('grooming_start_time')->nullable();
            $table->dateTime('grooming_end_time')->nullable();
            $table->string('grooming_state', 20)->default('not_started');
            $table->foreign('booking_id')->references('booking_id')->on('bookings')->cascadeOnDelete();
            $table->foreign('pet_id')->references('pet_id')->on('pets')->nullOnDelete();
            $table->unique(
                ['booking_pet_id', 'booking_id', 'pet_id'],
                'bp_concern_identity_uq',
            );
        });
        Schema::create('services', function (Blueprint $table) {
            $table->increments('service_id');
            $table->string('service_name');
        });
        Schema::create('addons', function (Blueprint $table) {
            $table->increments('addon_id');
            $table->string('addon_name');
        });
        Schema::create('booking_services', function (Blueprint $table) {
            $table->increments('booking_service_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('booking_pet_id');
            $table->unsignedInteger('service_id')->nullable();
            $table->unsignedInteger('addon_id')->nullable();
            $table->decimal('price_at_booking', 8, 2)->default(0);
        });
        Schema::create('grooming_medical_concerns', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('booking_pet_id');
            $table->unsignedInteger('pet_id');
            $table->string('recommended_grooming_action', 40);
            $table->string('applied_grooming_action', 40)->nullable();
            $table->dateTime('action_applied_at')->nullable();
            $table->unsignedInteger('action_applied_by_user_id')->nullable();
            $table->string('status', 30)->default('open');
            $table->timestamps();
            $table->foreign(
                ['booking_pet_id', 'booking_id', 'pet_id'],
                'gmc_booking_pet_fk',
            )
                ->references(['booking_pet_id', 'booking_id', 'pet_id'])
                ->on('booking_pets')
                ->restrictOnUpdate()
                ->restrictOnDelete();
            $table->foreign('action_applied_by_user_id')
                ->references('user_id')->on('users')->nullOnDelete();
        });
        Schema::create('grooming_medical_concern_responses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('concern_id');
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->increments('payment_id');
            $table->unsignedInteger('booking_id')->nullable();
            $table->decimal('total_amount', 8, 2)->default(0);
            $table->decimal('amount_tendered', 8, 2)->nullable();
            $table->decimal('change_amount', 8, 2)->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_status');
            $table->text('notes')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('created_at')->nullable();
        });
        Schema::create('notifications', function (Blueprint $table) {
            $table->increments('notification_id');
            $table->string('type');
            $table->unsignedInteger('booking_id');
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->dateTime('created_at')->nullable();
        });
        Schema::create('customer_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('booking_id')->nullable();
            $table->string('type');
            $table->text('message');
        });
        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();
        });
        Schema::create('clinic_appointments', function (Blueprint $table) {
            $table->id();
        });
    }

    private function seedContext(): void
    {
        DB::table('users')->insert([
            ['user_id' => 1, 'first_name' => 'Customer', 'last_name' => 'Owner', 'role' => 'customer'],
            ['user_id' => 2, 'first_name' => 'Staff', 'last_name' => 'Reviewer', 'role' => 'staff'],
            ['user_id' => 3, 'first_name' => 'Admin', 'last_name' => 'Reviewer', 'role' => 'admin'],
        ]);
        DB::table('pets')->insert([
            ['pet_id' => 101, 'user_id' => 1, 'pet_name' => 'Alpha', 'species' => 'Dog'],
            ['pet_id' => 102, 'user_id' => 1, 'pet_name' => 'Beta', 'species' => 'Cat'],
        ]);
        DB::table('bookings')->insert([
            [
                'booking_id' => 201,
                'booking_reference' => 'BK-201',
                'user_id' => 1,
                'status' => 'in_progress',
                'paid' => false,
            ],
            [
                'booking_id' => 202,
                'booking_reference' => 'BK-202',
                'user_id' => 1,
                'status' => 'in_progress',
                'paid' => false,
            ],
        ]);
        DB::table('booking_pets')->insert([
            [
                'booking_pet_id' => 301,
                'booking_id' => 201,
                'pet_id' => 101,
                'grooming_start_time' => '2026-08-01 09:00:00',
                'grooming_end_time' => null,
                'grooming_state' => BookingPet::GROOMING_STATE_STOPPED,
            ],
            [
                'booking_pet_id' => 302,
                'booking_id' => 201,
                'pet_id' => 102,
                'grooming_start_time' => '2026-08-01 09:00:00',
                'grooming_end_time' => '2026-08-01 10:00:00',
                'grooming_state' => BookingPet::GROOMING_STATE_FINISHED,
            ],
            [
                'booking_pet_id' => 303,
                'booking_id' => 202,
                'pet_id' => 101,
                'grooming_start_time' => '2026-08-01 09:00:00',
                'grooming_end_time' => null,
                'grooming_state' => BookingPet::GROOMING_STATE_STOPPED,
            ],
        ]);
        DB::table('services')->insert([
            ['service_id' => 1, 'service_name' => 'Basic Grooming'],
            ['service_id' => 2, 'service_name' => 'Nail Trim'],
        ]);
        DB::table('addons')->insert([
            ['addon_id' => 1, 'addon_name' => 'Coat Conditioner'],
        ]);
        DB::table('booking_services')->insert([
            [
                'booking_service_id' => 501,
                'booking_id' => 201,
                'booking_pet_id' => 301,
                'service_id' => 1,
                'addon_id' => null,
                'price_at_booking' => '500.00',
            ],
            [
                'booking_service_id' => 502,
                'booking_id' => 201,
                'booking_pet_id' => 301,
                'service_id' => null,
                'addon_id' => 1,
                'price_at_booking' => '125.00',
            ],
            [
                'booking_service_id' => 503,
                'booking_id' => 201,
                'booking_pet_id' => 302,
                'service_id' => 2,
                'addon_id' => null,
                'price_at_booking' => '200.00',
            ],
        ]);
        DB::table('grooming_medical_concerns')->insert([
            [
                'id' => 401,
                'public_id' => '11111111-1111-4111-8111-111111111111',
                'booking_id' => 201,
                'booking_pet_id' => 301,
                'pet_id' => 101,
                'recommended_grooming_action' => GroomingMedicalConcern::ACTION_STOP_GROOMING,
                'applied_grooming_action' => GroomingMedicalConcern::ACTION_STOP_GROOMING,
                'action_applied_at' => '2026-08-01 09:30:00',
                'action_applied_by_user_id' => 2,
                'status' => GroomingMedicalConcern::STATUS_OPEN,
                'created_at' => '2026-08-01 09:15:00',
                'updated_at' => '2026-08-01 09:30:00',
            ],
            [
                'id' => 402,
                'public_id' => '22222222-2222-4222-8222-222222222222',
                'booking_id' => 201,
                'booking_pet_id' => 302,
                'pet_id' => 102,
                'recommended_grooming_action' => GroomingMedicalConcern::ACTION_STOP_GROOMING,
                'applied_grooming_action' => GroomingMedicalConcern::ACTION_STOP_GROOMING,
                'action_applied_at' => '2026-08-01 09:40:00',
                'action_applied_by_user_id' => 2,
                'status' => GroomingMedicalConcern::STATUS_RESOLVED,
                'created_at' => '2026-08-01 09:20:00',
                'updated_at' => '2026-08-01 09:40:00',
            ],
        ]);
    }

    private function authenticateAs(string $role): User
    {
        $user = User::where('role', $role)->firstOrFail();
        Sanctum::actingAs($user);

        return $user;
    }

    private function showUri(): string
    {
        return '/api/admin/bookings/201/pets/301/stopped-payment-review';
    }

    private function storeUri(): string
    {
        return '/api/admin/bookings/201/pets/301/medical-concerns/401/stopped-payment-review';
    }

    private function fullChargePayload(array $overrides = []): array
    {
        return array_merge([
            'decision' => GroomingStoppedPaymentReview::DECISION_FULL_CHARGE,
            'internal_reason' => 'Stopped before completion.',
            'customer_explanation' => 'The charge reflects completed grooming work.',
        ], $overrides);
    }

    private function partialChargePayload(string $amount): array
    {
        return array_merge($this->fullChargePayload(), [
            'decision' => GroomingStoppedPaymentReview::DECISION_PARTIAL_CHARGE,
            'final_pet_charge' => $amount,
        ]);
    }

    private function noChargePayload(): array
    {
        return array_merge($this->fullChargePayload(), [
            'decision' => GroomingStoppedPaymentReview::DECISION_NO_CHARGE,
        ]);
    }

    private function paymentPayload(): array
    {
        return [
            'final_price' => 825,
            'amount_paid' => 825,
            'payment_method' => 'cash',
            'service_prices' => [
                ['booking_service_id' => 501, 'amount' => 500],
                ['booking_service_id' => 502, 'amount' => 125],
                ['booking_service_id' => 503, 'amount' => 200],
            ],
        ];
    }

    private function stateLabel(string $state): string
    {
        return match ($state) {
            BookingPet::GROOMING_STATE_PAUSED => 'Paused',
            BookingPet::GROOMING_STATE_STOPPED => 'Stopped',
            default => $state,
        };
    }
}
