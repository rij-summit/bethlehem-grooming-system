<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPet;
use App\Models\ClinicAppointment;
use App\Models\CustomerNotification;
use App\Models\GroomingMedicalConcern;
use App\Models\GroomingMedicalConcernResponse;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class GroomingMedicalConcernFoundationTest extends TestCase
{
    private $notificationTypeMigration;

    private $bookingPetMigration;

    private $concernMigration;

    private $notificationLinkMigration;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON');
        $this->createExistingSchema();
        $this->insertExistingData();

        $this->notificationTypeMigration = require base_path(
            'database/migrations/2026_07_24_000001_convert_customer_notification_type_to_string.php',
        );
        $this->bookingPetMigration = require base_path(
            'database/migrations/2026_07_24_000002_add_grooming_state_and_integrity_to_booking_pets.php',
        );
        $this->concernMigration = require base_path(
            'database/migrations/2026_07_24_000003_create_grooming_medical_concern_tables.php',
        );
        $this->notificationLinkMigration = require base_path(
            'database/migrations/2026_07_24_000004_link_customer_notifications_to_grooming_medical_concerns.php',
        );

        $this->notificationTypeMigration->up();
        $this->bookingPetMigration->up();
        $this->concernMigration->up();
        $this->notificationLinkMigration->up();
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::dropIfExists('grooming_medical_concern_responses');
        Schema::dropIfExists('grooming_medical_concerns');
        Schema::dropIfExists('customer_notifications');
        Schema::dropIfExists('booking_pets');
        Schema::dropIfExists('clinic_appointments');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('pets');
        Schema::dropIfExists('users');

        Schema::enableForeignKeyConstraints();

        parent::tearDown();
    }

    public function test_sqlite_migrations_create_the_approved_schema_indexes_and_foreign_keys(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertTrue(Schema::hasColumns('grooming_medical_concerns', [
            'id',
            'public_id',
            'report_token',
            'booking_id',
            'booking_pet_id',
            'pet_id',
            'reported_by_user_id',
            'reported_by_name',
            'reported_at',
            'category',
            'severity',
            'internal_description',
            'customer_message',
            'recommended_grooming_action',
            'applied_grooming_action',
            'action_applied_at',
            'action_applied_by_user_id',
            'status',
            'acknowledgment_required',
            'consent_required',
            'customer_response_status',
            'customer_notified_at',
            'clinic_appointment_id',
            'customer_resolution_summary',
            'internal_resolution_notes',
            'resolved_at',
            'resolved_by_user_id',
            'resolved_by_name',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('grooming_medical_concern_responses', [
            'id',
            'concern_id',
            'responded_by_user_id',
            'responded_by_name',
            'response_kind',
            'decision',
            'statement_text',
            'statement_version',
            'signature_name',
            'responded_at',
            'created_at',
        ]));
        $this->assertFalse(Schema::hasColumn(
            'grooming_medical_concern_responses',
            'updated_at',
        ));
        $this->assertTrue(Schema::hasColumn('booking_pets', 'grooming_state'));
        $this->assertTrue(Schema::hasColumn(
            'customer_notifications',
            'grooming_medical_concern_id',
        ));

        $this->assertIndexesExist('grooming_medical_concerns', [
            'gmc_public_id_uq',
            'gmc_report_token_uq',
            'gmc_booking_pet_status_idx',
            'gmc_booking_reported_idx',
            'gmc_pet_reported_idx',
            'gmc_pet_status_idx',
            'gmc_clinic_appt_idx',
            'gmc_response_status_idx',
        ]);
        $this->assertIndexesExist('grooming_medical_concern_responses', [
            'gmcr_concern_responded_idx',
            'gmcr_concern_kind_uq',
        ]);
        $this->assertIndexesExist('booking_pets', [
            'bp_grooming_state_idx',
            'bp_concern_identity_uq',
        ]);
        $this->assertIndexesExist('customer_notifications', [
            'cn_grooming_concern_idx',
        ]);

        $concernForeignKeys = collect(DB::select(
            "PRAGMA foreign_key_list('grooming_medical_concerns')",
        ));
        $bookingPetKey = $concernForeignKeys
            ->where('table', 'booking_pets')
            ->sortBy('seq')
            ->values();

        $this->assertSame(
            ['booking_pet_id', 'booking_id', 'pet_id'],
            $bookingPetKey->pluck('from')->all(),
        );
        $this->assertSame(
            ['booking_pet_id', 'booking_id', 'pet_id'],
            $bookingPetKey->pluck('to')->all(),
        );
        $this->assertSame(
            ['RESTRICT'],
            $bookingPetKey->pluck('on_delete')->unique()->values()->all(),
        );

        foreach ([
            ['reported_by_user_id', 'users', 'user_id', 'SET NULL'],
            ['action_applied_by_user_id', 'users', 'user_id', 'SET NULL'],
            ['resolved_by_user_id', 'users', 'user_id', 'SET NULL'],
            ['clinic_appointment_id', 'clinic_appointments', 'id', 'RESTRICT'],
        ] as [$column, $referencedTable, $referencedColumn, $deleteRule]) {
            $this->assertSqliteForeignKey(
                'grooming_medical_concerns',
                $column,
                $referencedTable,
                $referencedColumn,
                $deleteRule,
            );
        }
        $this->assertSqliteForeignKey(
            'grooming_medical_concern_responses',
            'concern_id',
            'grooming_medical_concerns',
            'id',
            'RESTRICT',
        );
        $this->assertSqliteForeignKey(
            'grooming_medical_concern_responses',
            'responded_by_user_id',
            'users',
            'user_id',
            'SET NULL',
        );
        $this->assertSqliteForeignKey(
            'customer_notifications',
            'grooming_medical_concern_id',
            'grooming_medical_concerns',
            'id',
            'RESTRICT',
        );

        foreach ([
            'grooming_medical_concerns',
            'grooming_medical_concern_responses',
            'customer_notifications',
            'booking_pets',
        ] as $table) {
            $columnTypes = collect(Schema::getColumns($table))
                ->pluck('type_name')
                ->map(fn ($type) => strtolower((string) $type));

            $this->assertFalse(
                $columnTypes->contains('enum'),
                "The {$table} table contains an unexpected ENUM column.",
            );
        }
    }

    public function test_notification_conversion_preserves_rows_accepts_future_types_and_rolls_back_safely(): void
    {
        $this->assertDatabaseHas('customer_notifications', [
            'id' => 401,
            'type' => 'grooming_started',
            'message' => 'Original notification',
            'is_read' => 0,
            'created_at' => '2026-07-24 08:00:00',
        ]);

        DB::table('customer_notifications')->insert([
            'id' => 402,
            'user_id' => 1,
            'booking_id' => 201,
            'type' => 'future_medical_concern',
            'message' => 'Future notification type',
            'is_read' => false,
            'created_at' => '2026-07-24 09:00:00',
        ]);

        try {
            $this->notificationTypeMigration->down();
            $this->fail('Rollback should reject notification values outside the legacy ENUM.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'future_medical_concern',
                $exception->getMessage(),
            );
            $this->assertDatabaseHas('customer_notifications', [
                'id' => 402,
                'type' => 'future_medical_concern',
            ]);
        }

        DB::table('customer_notifications')->where('id', 402)->delete();
        $this->notificationTypeMigration->down();

        $this->assertDatabaseHas('customer_notifications', [
            'id' => 401,
            'type' => 'grooming_started',
        ]);
        $this->assertInsertFails('customer_notifications', [
            'id' => 403,
            'user_id' => 1,
            'booking_id' => 201,
            'type' => 'future_medical_concern',
            'message' => 'Should be rejected by restored legacy storage.',
            'is_read' => false,
            'created_at' => '2026-07-24 10:00:00',
        ]);

        $this->notificationTypeMigration->up();
        DB::table('customer_notifications')->insert([
            'id' => 404,
            'user_id' => 1,
            'booking_id' => 201,
            'type' => 'future_medical_concern',
            'message' => 'Allowed again after conversion.',
            'is_read' => false,
            'created_at' => '2026-07-24 11:00:00',
        ]);

        $this->assertDatabaseHas('customer_notifications', [
            'id' => 404,
            'type' => 'future_medical_concern',
        ]);
    }

    public function test_grooming_state_backfill_uses_only_existing_timestamps(): void
    {
        $this->assertDatabaseHas('booking_pets', [
            'booking_pet_id' => 301,
            'grooming_state' => BookingPet::GROOMING_STATE_FINISHED,
            'grooming_start_time' => '2026-07-24 08:00:00',
            'grooming_end_time' => '2026-07-24 09:00:00',
        ]);
        $this->assertDatabaseHas('booking_pets', [
            'booking_pet_id' => 302,
            'grooming_state' => BookingPet::GROOMING_STATE_IN_PROGRESS,
            'grooming_start_time' => '2026-07-24 09:30:00',
            'grooming_end_time' => null,
        ]);
        $this->assertDatabaseHas('booking_pets', [
            'booking_pet_id' => 303,
            'grooming_state' => BookingPet::GROOMING_STATE_NOT_STARTED,
        ]);
        $this->assertDatabaseHas('booking_pets', [
            'booking_pet_id' => 304,
            'grooming_state' => BookingPet::GROOMING_STATE_NOT_STARTED,
        ]);
        $this->assertDatabaseHas('bookings', [
            'booking_id' => 202,
            'status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('bookings', [
            'booking_id' => 203,
            'status' => 'no_show',
        ]);
        $this->assertDatabaseMissing('booking_pets', [
            'grooming_state' => BookingPet::GROOMING_STATE_STOPPED,
        ]);

        DB::table('booking_pets')->insert([
            'booking_pet_id' => 305,
            'booking_id' => 203,
            'pet_id' => 101,
        ]);
        $this->assertDatabaseHas('booking_pets', [
            'booking_pet_id' => 305,
            'grooming_state' => BookingPet::GROOMING_STATE_NOT_STARTED,
        ]);
    }

    public function test_composite_integrity_rejects_mismatches_and_allows_multiple_concerns(): void
    {
        $first = $this->createConcern([
            'public_id' => '11111111-1111-4111-8111-111111111111',
            'report_token' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        ]);
        $second = $this->createConcern([
            'public_id' => '22222222-2222-4222-8222-222222222222',
            'report_token' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        ]);

        $this->assertSame(2, GroomingMedicalConcern::count());
        $this->assertSame($first->booking_pet_id, $second->booking_pet_id);
        $this->assertSame($first->pet_id, $second->pet_id);

        $this->assertConcernInsertFails([
            'booking_id' => 202,
            'booking_pet_id' => 301,
            'pet_id' => 101,
            'public_id' => '33333333-3333-4333-8333-333333333333',
        ]);
        $this->assertConcernInsertFails([
            'booking_id' => 201,
            'booking_pet_id' => 301,
            'pet_id' => 102,
            'public_id' => '44444444-4444-4444-8444-444444444444',
        ]);
        $this->assertConcernInsertFails([
            'public_id' => $first->public_id,
        ]);
        $this->assertConcernInsertFails([
            'public_id' => '55555555-5555-4555-8555-555555555555',
            'report_token' => $first->report_token,
        ]);

        $third = $this->createConcern([
            'public_id' => '66666666-6666-4666-8666-666666666666',
            'report_token' => null,
        ]);
        $fourth = $this->createConcern([
            'public_id' => '77777777-7777-4777-8777-777777777777',
            'report_token' => null,
        ]);

        $this->assertNull($third->report_token);
        $this->assertNull($fourth->report_token);
    }

    public function test_foreign_key_delete_rules_preserve_history_and_name_snapshots(): void
    {
        DB::table('users')->insert([
            'user_id' => 2,
            'first_name' => 'Historical',
            'last_name' => 'Staff',
            'role' => 'staff',
        ]);
        DB::table('clinic_appointments')->insert([
            'id' => 501,
            'pet_id' => 101,
            'appointment_reference' => 'CL-501',
        ]);

        $concern = $this->createConcern([
            'reported_by_user_id' => 2,
            'reported_by_name' => 'Historical Reporter',
            'action_applied_by_user_id' => 2,
            'resolved_by_user_id' => 2,
            'resolved_by_name' => 'Historical Resolver',
            'clinic_appointment_id' => 501,
        ]);
        $response = $this->createResponse($concern, [
            'responded_by_user_id' => 2,
        ]);

        DB::table('customer_notifications')
            ->where('id', 401)
            ->update(['grooming_medical_concern_id' => $concern->id]);

        $this->assertSame(2, $concern->reportedBy->user_id);
        $this->assertSame(2, $concern->actionAppliedBy->user_id);
        $this->assertSame(2, $concern->resolvedBy->user_id);
        $this->assertSame(2, $response->respondedBy->user_id);
        $this->assertTrue(User::findOrFail(2)->groomingMedicalConcernsReported->contains($concern));
        $this->assertTrue(User::findOrFail(2)->groomingMedicalConcernActionsApplied->contains($concern));
        $this->assertTrue(User::findOrFail(2)->groomingMedicalConcernsResolved->contains($concern));
        $this->assertTrue(User::findOrFail(2)->groomingMedicalConcernResponses->contains($response));

        DB::table('users')->where('user_id', 2)->delete();
        $concern->refresh();
        $response->refresh();

        $this->assertNull($concern->reported_by_user_id);
        $this->assertNull($concern->action_applied_by_user_id);
        $this->assertNull($concern->resolved_by_user_id);
        $this->assertSame('Historical Reporter', $concern->reported_by_name);
        $this->assertSame('Historical Resolver', $concern->resolved_by_name);
        $this->assertNull($response->responded_by_user_id);
        $this->assertSame('Historical Responder', $response->responded_by_name);

        $this->assertDeleteFails('clinic_appointments', 'id', 501);
        $this->assertDeleteFails('booking_pets', 'booking_pet_id', 301);
        $this->assertDeleteFails('bookings', 'booking_id', 201);
        $this->assertDeleteFails('pets', 'pet_id', 101);
        $this->assertDeleteFails('grooming_medical_concerns', 'id', $concern->id);

        $this->assertSame(
            $concern->id,
            CustomerNotification::findOrFail(401)->groomingMedicalConcern->id,
        );
    }

    public function test_response_evidence_is_unique_by_kind_and_immutable_through_models(): void
    {
        $concern = $this->createConcern();
        $acknowledgment = $this->createResponse($concern);
        $consent = $this->createResponse($concern, [
            'response_kind' => GroomingMedicalConcernResponse::KIND_CONSENT,
            'decision' => GroomingMedicalConcernResponse::DECISION_APPROVED,
            'statement_text' => 'I consent to the stated next step.',
        ]);

        $this->assertSame(2, $concern->responses()->count());
        $this->assertSame(
            [
                GroomingMedicalConcernResponse::KIND_ACKNOWLEDGMENT,
                GroomingMedicalConcernResponse::KIND_CONSENT,
            ],
            $concern->responses()->orderBy('id')->pluck('response_kind')->all(),
        );

        $this->assertInsertFails('grooming_medical_concern_responses', [
            'concern_id' => $concern->id,
            'responded_by_name' => 'Duplicate Responder',
            'response_kind' => GroomingMedicalConcernResponse::KIND_ACKNOWLEDGMENT,
            'decision' => GroomingMedicalConcernResponse::DECISION_ACKNOWLEDGED,
            'statement_text' => 'Duplicate acknowledgment.',
            'statement_version' => 'v1',
            'responded_at' => '2026-07-24 12:30:00',
        ]);
        $this->assertInsertFails('grooming_medical_concern_responses', [
            'concern_id' => $concern->id,
            'responded_by_name' => 'Duplicate Responder',
            'response_kind' => GroomingMedicalConcernResponse::KIND_CONSENT,
            'decision' => GroomingMedicalConcernResponse::DECISION_DECLINED,
            'statement_text' => 'Duplicate consent.',
            'statement_version' => 'v1',
            'responded_at' => '2026-07-24 12:31:00',
        ]);

        try {
            $acknowledgment->statement_text = 'Changed evidence';
            $acknowledgment->save();
            $this->fail('An immutable response should not be updateable.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(
            'I acknowledge the grooming concern.',
            $acknowledgment->fresh()->statement_text,
        );
        $this->assertNotNull($consent->created_at);

        try {
            $consent->delete();
            $this->fail('An immutable response should not be deleteable.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->assertDatabaseHas('grooming_medical_concern_responses', [
            'id' => $consent->id,
        ]);
    }

    public function test_model_relationships_scopes_constants_and_delete_guards_are_reusable(): void
    {
        DB::table('clinic_appointments')->insert([
            'id' => 501,
            'pet_id' => 101,
            'appointment_reference' => 'CL-501',
        ]);

        $active = $this->createConcern([
            'clinic_appointment_id' => 501,
            'customer_notified_at' => '2026-07-24 12:00:00',
        ]);
        $awaiting = $this->createConcern([
            'status' => GroomingMedicalConcern::STATUS_AWAITING_CUSTOMER,
            'customer_response_status' => GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING,
        ]);
        $resolved = $this->createConcern([
            'status' => GroomingMedicalConcern::STATUS_RESOLVED,
            'resolved_at' => '2026-07-24 13:00:00',
        ]);
        $this->createConcern([
            'status' => GroomingMedicalConcern::STATUS_CANCELLED,
        ]);

        $this->assertSame(2, GroomingMedicalConcern::active()->count());
        $this->assertSame([$awaiting->id], GroomingMedicalConcern::awaitingCustomer()->pluck('id')->all());
        $this->assertSame([$resolved->id], GroomingMedicalConcern::resolved()->pluck('id')->all());
        $this->assertSame([$active->id], GroomingMedicalConcern::customerVisible()->pluck('id')->all());
        $this->assertTrue($active->isCustomerVisible());

        $this->assertSame(201, $active->booking->booking_id);
        $this->assertSame(301, $active->bookingPet->booking_pet_id);
        $this->assertSame(101, $active->pet->pet_id);
        $this->assertSame(501, $active->clinicAppointment->id);
        $this->assertTrue(Booking::findOrFail(201)->groomingMedicalConcerns->contains($active));
        $this->assertTrue(BookingPet::findOrFail(301)->groomingMedicalConcerns->contains($active));
        $this->assertTrue(Pet::findOrFail(101)->groomingMedicalConcerns->contains($active));
        $this->assertTrue(ClinicAppointment::findOrFail(501)->groomingMedicalConcerns->contains($active));

        $this->assertTrue(GroomingMedicalConcern::isValidSeverity('urgent'));
        $this->assertFalse(GroomingMedicalConcern::isValidSeverity('critical'));
        $this->assertTrue(GroomingMedicalConcern::isValidGroomingAction('pause_grooming'));
        $this->assertTrue(GroomingMedicalConcern::isValidStatus('under_clinic_review'));
        $this->assertTrue(GroomingMedicalConcern::isValidCustomerResponseStatus('declined'));
        $this->assertTrue(BookingPet::isValidGroomingState('paused'));
        $this->assertFalse(BookingPet::isValidGroomingState('cancelled'));
        $this->assertTrue(GroomingMedicalConcernResponse::isValidKind('consent'));
        $this->assertTrue(GroomingMedicalConcernResponse::isValidDecisionForKind(
            'acknowledgment',
            'acknowledged',
        ));
        $this->assertFalse(GroomingMedicalConcernResponse::isValidDecisionForKind(
            'acknowledgment',
            'approved',
        ));

        $this->expectException(LogicException::class);
        $active->delete();
    }

    private function createExistingSchema(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('role');
        });

        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('pet_name');
            $table->foreign('user_id')->references('user_id')->on('users')->nullOnDelete();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->increments('booking_id');
            $table->unsignedInteger('user_id');
            $table->string('booking_reference');
            $table->string('status');
            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
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
            $table->string('appointment_reference');
            $table->foreign('pet_id')->references('pet_id')->on('pets')->nullOnDelete();
        });

        Schema::create('customer_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('booking_id')->nullable();
            $table->enum('type', [
                'reminder_24h',
                'reminder_3h',
                'grooming_started',
                'grooming_finished',
                'ready_for_pickup',
                'pickup_reminder',
                'picked_up',
            ]);
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    private function insertExistingData(): void
    {
        DB::table('users')->insert([
            'user_id' => 1,
            'first_name' => 'Customer',
            'last_name' => 'Owner',
            'role' => 'customer',
        ]);
        DB::table('pets')->insert([
            [
                'pet_id' => 101,
                'user_id' => 1,
                'pet_name' => 'Alpha',
            ],
            [
                'pet_id' => 102,
                'user_id' => 1,
                'pet_name' => 'Beta',
            ],
        ]);
        DB::table('bookings')->insert([
            [
                'booking_id' => 201,
                'user_id' => 1,
                'booking_reference' => 'BK-201',
                'status' => 'in_progress',
            ],
            [
                'booking_id' => 202,
                'user_id' => 1,
                'booking_reference' => 'BK-202',
                'status' => 'cancelled',
            ],
            [
                'booking_id' => 203,
                'user_id' => 1,
                'booking_reference' => 'BK-203',
                'status' => 'no_show',
            ],
        ]);
        DB::table('booking_pets')->insert([
            [
                'booking_pet_id' => 301,
                'booking_id' => 201,
                'pet_id' => 101,
                'grooming_start_time' => '2026-07-24 08:00:00',
                'grooming_end_time' => '2026-07-24 09:00:00',
            ],
            [
                'booking_pet_id' => 302,
                'booking_id' => 201,
                'pet_id' => 102,
                'grooming_start_time' => '2026-07-24 09:30:00',
                'grooming_end_time' => null,
            ],
            [
                'booking_pet_id' => 303,
                'booking_id' => 202,
                'pet_id' => 101,
                'grooming_start_time' => null,
                'grooming_end_time' => null,
            ],
            [
                'booking_pet_id' => 304,
                'booking_id' => 203,
                'pet_id' => 102,
                'grooming_start_time' => null,
                'grooming_end_time' => null,
            ],
        ]);
        DB::table('customer_notifications')->insert([
            'id' => 401,
            'user_id' => 1,
            'booking_id' => 201,
            'type' => 'grooming_started',
            'message' => 'Original notification',
            'is_read' => false,
            'created_at' => '2026-07-24 08:00:00',
        ]);
    }

    private function createConcern(array $overrides = []): GroomingMedicalConcern
    {
        return GroomingMedicalConcern::create(array_merge([
            'booking_id' => 201,
            'booking_pet_id' => 301,
            'pet_id' => 101,
            'reported_by_name' => 'Test Reporter',
            'reported_at' => '2026-07-24 10:00:00',
            'category' => 'skin',
            'severity' => GroomingMedicalConcern::SEVERITY_MODERATE,
            'internal_description' => 'Internal factual observation.',
            'customer_message' => 'Customer-safe factual observation.',
            'recommended_grooming_action' => GroomingMedicalConcern::ACTION_PAUSE_GROOMING,
        ], $overrides));
    }

    private function createResponse(
        GroomingMedicalConcern $concern,
        array $overrides = [],
    ): GroomingMedicalConcernResponse {
        return GroomingMedicalConcernResponse::create(array_merge([
            'concern_id' => $concern->id,
            'responded_by_user_id' => $overrides['responded_by_user_id'] ?? null,
            'responded_by_name' => 'Historical Responder',
            'response_kind' => GroomingMedicalConcernResponse::KIND_ACKNOWLEDGMENT,
            'decision' => GroomingMedicalConcernResponse::DECISION_ACKNOWLEDGED,
            'statement_text' => 'I acknowledge the grooming concern.',
            'statement_version' => 'v1',
            'responded_at' => '2026-07-24 12:00:00',
        ], $overrides));
    }

    private function assertConcernInsertFails(array $overrides): void
    {
        $record = array_merge([
            'public_id' => '99999999-9999-4999-8999-999999999999',
            'booking_id' => 201,
            'booking_pet_id' => 301,
            'pet_id' => 101,
            'reported_by_name' => 'Invalid Reporter',
            'reported_at' => '2026-07-24 10:00:00',
            'category' => 'skin',
            'severity' => 'moderate',
            'internal_description' => 'Internal description.',
            'customer_message' => 'Customer message.',
            'recommended_grooming_action' => 'pause_grooming',
            'created_at' => '2026-07-24 10:00:00',
            'updated_at' => '2026-07-24 10:00:00',
        ], $overrides);

        $this->assertInsertFails('grooming_medical_concerns', $record);
    }

    private function assertInsertFails(string $table, array $record): void
    {
        try {
            DB::table($table)->insert($record);
            $this->fail("The invalid {$table} record should have been rejected.");
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function assertDeleteFails(string $table, string $key, int $value): void
    {
        try {
            DB::table($table)->where($key, $value)->delete();
            $this->fail("Deleting the linked {$table} row should have been restricted.");
        } catch (QueryException) {
            $this->assertDatabaseHas($table, [$key => $value]);
        }
    }

    private function assertIndexesExist(string $table, array $expectedNames): void
    {
        $indexNames = collect(Schema::getIndexes($table))->pluck('name');

        foreach ($expectedNames as $expectedName) {
            $this->assertTrue(
                $indexNames->contains($expectedName),
                "Missing index {$expectedName} on {$table}.",
            );
        }
    }

    private function assertSqliteForeignKey(
        string $table,
        string $column,
        string $referencedTable,
        string $referencedColumn,
        string $deleteRule,
    ): void {
        $foreignKey = collect(DB::select("PRAGMA foreign_key_list('{$table}')"))
            ->first(fn ($key) => $key->from === $column);

        $this->assertNotNull($foreignKey, "Missing foreign key for {$table}.{$column}.");
        $this->assertSame($referencedTable, $foreignKey->table);
        $this->assertSame($referencedColumn, $foreignKey->to);
        $this->assertSame($deleteRule, $foreignKey->on_delete);
    }
}
