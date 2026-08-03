<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPet;
use App\Models\ClinicAppointment;
use App\Models\CustomerNotification;
use App\Models\GroomingClinicReferral;
use App\Models\GroomingMedicalConcern;
use App\Models\GroomingMedicalConcernResponse;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class GroomingClinicReferralFoundationTest extends TestCase
{
    private object $referralMigration;

    private object $notificationMigration;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON');
        $this->createPrerequisiteSchema();
        $this->seedPrerequisiteData();

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
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('customer_notifications');
        Schema::dropIfExists('grooming_clinic_referrals');
        Schema::dropIfExists('grooming_medical_concern_responses');
        Schema::dropIfExists('grooming_medical_concerns');
        Schema::dropIfExists('clinic_appointments');
        Schema::dropIfExists('booking_pets');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('pets');
        Schema::dropIfExists('users');
        Schema::enableForeignKeyConstraints();

        parent::tearDown();
    }

    public function test_sqlite_migrations_create_the_portable_referral_and_notification_schema(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertTrue(Schema::hasColumns('grooming_clinic_referrals', [
            'id',
            'public_id',
            'request_token',
            'grooming_medical_concern_id',
            'booking_id',
            'booking_pet_id',
            'pet_id',
            'clinic_appointment_id',
            'status',
            'urgency',
            'referral_reason',
            'customer_explanation',
            'consent_required',
            'consent_response_id',
            'owner_user_id_at_referral',
            'owner_name_at_referral',
            'referred_by_user_id',
            'referred_by_name',
            'referred_at',
            'customer_notified_at',
            'emergency_without_consent_reason',
            'accepted_by_user_id',
            'accepted_by_name',
            'accepted_at',
            'clinic_review_started_by_user_id',
            'clinic_review_started_by_name',
            'clinic_review_started_at',
            'grooming_clearance_status',
            'cancelled_by_user_id',
            'cancelled_by_name',
            'cancelled_at',
            'cancellation_reason',
            'customer_cancellation_summary',
            'resolved_by_user_id',
            'resolved_by_name',
            'resolved_at',
            'internal_resolution_notes',
            'customer_resolution_summary',
            'created_at',
        ]));
        $this->assertFalse(Schema::hasColumn('grooming_clinic_referrals', 'updated_at'));
        $this->assertFalse(Schema::hasColumn('grooming_clinic_referrals', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('grooming_clinic_referrals', 'draft'));
        $this->assertTrue(Schema::hasColumn(
            'customer_notifications',
            'grooming_clinic_referral_id',
        ));

        foreach (['status', 'urgency', 'grooming_clearance_status'] as $column) {
            $definition = collect(DB::select(
                "PRAGMA table_info('grooming_clinic_referrals')",
            ))->firstWhere('name', $column);
            $this->assertNotNull($definition);
            $this->assertSame('varchar', strtolower($definition->type));
            $this->assertStringNotContainsString('enum', strtolower($definition->type));
        }

        $this->assertIndexesExist('grooming_clinic_referrals', [
            'gcr_public_uq',
            'gcr_token_uq',
            'gcr_concern_uq',
            'gcr_appt_uq',
            'gcr_consent_uq',
            'gcr_booking_status_idx',
            'gcr_bp_status_idx',
            'gcr_pet_referred_idx',
            'gcr_status_urgency_idx',
            'gcr_referrer_idx',
            'gcr_owner_idx',
            'gcr_clearance_idx',
            'gcr_bp_context_idx',
            'gcr_concern_context_idx',
            'gcr_consent_context_idx',
        ]);
        $this->assertIndexesExist('customer_notifications', [
            'cn_grooming_referral_idx',
            'cn_referral_type_uq',
        ]);

        $this->assertSqliteForeignKey(
            'grooming_clinic_referrals',
            ['booking_pet_id', 'booking_id', 'pet_id'],
            'booking_pets',
            ['booking_pet_id', 'booking_id', 'pet_id'],
            'RESTRICT',
        );
        $this->assertSqliteForeignKey(
            'grooming_clinic_referrals',
            ['grooming_medical_concern_id', 'booking_id', 'booking_pet_id', 'pet_id'],
            'grooming_medical_concerns',
            ['id', 'booking_id', 'booking_pet_id', 'pet_id'],
            'RESTRICT',
        );
        $this->assertSqliteForeignKey(
            'grooming_clinic_referrals',
            ['consent_response_id', 'grooming_medical_concern_id'],
            'grooming_medical_concern_responses',
            ['id', 'concern_id'],
            'RESTRICT',
        );
        $this->assertSqliteForeignKey(
            'grooming_clinic_referrals',
            ['clinic_appointment_id'],
            'clinic_appointments',
            ['id'],
            'RESTRICT',
        );
        $this->assertSqliteForeignKey(
            'grooming_clinic_referrals',
            ['owner_user_id_at_referral'],
            'users',
            ['user_id'],
            'SET NULL',
        );
        $this->assertSqliteForeignKey(
            'customer_notifications',
            ['grooming_clinic_referral_id'],
            'grooming_clinic_referrals',
            ['id'],
            'RESTRICT',
        );
    }

    public function test_migrations_roll_back_without_changing_existing_notifications(): void
    {
        $originalColumns = [
            'id',
            'user_id',
            'booking_id',
            'grooming_medical_concern_id',
            'type',
            'message',
            'is_read',
            'created_at',
        ];
        $before = DB::table('customer_notifications')
            ->orderBy('id')
            ->get($originalColumns)
            ->toJson();

        $this->notificationMigration->down();
        $this->referralMigration->down();

        $this->assertFalse(Schema::hasTable('grooming_clinic_referrals'));
        $this->assertFalse(Schema::hasColumn(
            'customer_notifications',
            'grooming_clinic_referral_id',
        ));
        $this->assertSame(
            $before,
            DB::table('customer_notifications')
                ->orderBy('id')
                ->get($originalColumns)
                ->toJson(),
        );

        $this->referralMigration->up();
        $this->notificationMigration->up();
        $this->assertDatabaseCount('grooming_clinic_referrals', 0);
        $this->assertDatabaseHas('customer_notifications', [
            'id' => 701,
            'grooming_clinic_referral_id' => null,
        ]);
    }

    public function test_exact_context_is_enforced_and_sibling_pets_never_mix(): void
    {
        $valid = $this->createReferral();
        $this->assertSame(301, $valid->booking_pet_id);
        DB::table('grooming_clinic_referrals')->where('id', $valid->id)->delete();

        $this->assertReferralInsertFails(['booking_id' => 202]);
        $this->assertReferralInsertFails(['pet_id' => 102]);
        $this->assertReferralInsertFails(['grooming_medical_concern_id' => 402]);

        $alpha = $this->createReferral();
        $beta = $this->createReferral([
            'grooming_medical_concern_id' => 402,
            'booking_pet_id' => 302,
            'pet_id' => 102,
            'request_token' => '22222222-2222-4222-8222-222222222222',
        ]);

        $this->assertNotSame($alpha->booking_pet_id, $beta->booking_pet_id);
        $this->assertSame(2, GroomingClinicReferral::query()->count());
    }

    public function test_consent_and_appointment_context_and_uniqueness_are_enforced(): void
    {
        $referral = $this->createReferral([
            'consent_response_id' => 501,
            'clinic_appointment_id' => 601,
        ]);

        $this->assertReferralInsertFails([
            'grooming_medical_concern_id' => 404,
            'consent_response_id' => 501,
            'request_token' => '33333333-3333-4333-8333-333333333333',
        ]);
        $this->assertReferralInsertFails([
            'grooming_medical_concern_id' => 404,
            'consent_response_id' => 502,
            'clinic_appointment_id' => 601,
            'request_token' => '44444444-4444-4444-8444-444444444444',
        ]);

        $this->assertDeleteFails('clinic_appointments', 'id', 601);
        $this->assertDeleteFails('grooming_medical_concern_responses', 'id', 501);
        $this->assertSame(501, $referral->consentResponse->id);
        $this->assertSame(601, $referral->clinicAppointment->id);
        $this->assertDatabaseHas('grooming_medical_concern_responses', [
            'id' => 502,
            'concern_id' => 402,
            'response_kind' => GroomingMedicalConcernResponse::KIND_CONSENT,
            'decision' => GroomingMedicalConcernResponse::DECISION_APPROVED,
        ]);
    }

    public function test_separate_concerns_allow_sequential_referrals_for_one_booking_pet(): void
    {
        $first = $this->createReferral();
        $second = $this->createReferral([
            'grooming_medical_concern_id' => 404,
            'request_token' => '55555555-5555-4555-8555-555555555555',
            'status' => GroomingClinicReferral::STATUS_COMPLETED,
        ]);

        $this->assertSame($first->booking_pet_id, $second->booking_pet_id);
        $this->assertNotSame($first->grooming_medical_concern_id, $second->grooming_medical_concern_id);
        $this->assertSame(2, BookingPet::findOrFail(301)->groomingClinicReferrals()->count());

        $this->assertReferralInsertFails([
            'request_token' => '66666666-6666-4666-8666-666666666666',
        ]);
        $this->assertReferralInsertFails([
            'grooming_medical_concern_id' => 403,
            'booking_id' => 202,
            'booking_pet_id' => 303,
            'request_token' => $first->request_token,
        ]);
        $this->assertReferralInsertFails([
            'public_id' => $first->public_id,
            'grooming_medical_concern_id' => 403,
            'booking_id' => 202,
            'booking_pet_id' => 303,
            'request_token' => '77777777-7777-4777-8777-777777777777',
        ]);
    }

    public function test_notification_link_supports_event_types_and_preserves_null_rows(): void
    {
        $referral = $this->createReferral();
        $this->assertDatabaseHas('customer_notifications', [
            'id' => 701,
            'grooming_clinic_referral_id' => null,
        ]);

        $first = CustomerNotification::create([
            'user_id' => 1,
            'booking_id' => 201,
            'grooming_medical_concern_id' => 401,
            'grooming_clinic_referral_id' => $referral->id,
            'type' => 'grooming_clinic_referral_requested',
            'message' => 'Referral requested.',
            'is_read' => false,
            'created_at' => '2026-08-04 10:10:00',
        ]);
        CustomerNotification::create([
            'user_id' => 1,
            'booking_id' => 201,
            'grooming_medical_concern_id' => 401,
            'grooming_clinic_referral_id' => $referral->id,
            'type' => 'grooming_clinic_referral_accepted',
            'message' => 'Referral accepted.',
            'is_read' => false,
            'created_at' => '2026-08-04 10:15:00',
        ]);
        DB::table('customer_notifications')->insert([
            'user_id' => 1,
            'type' => 'grooming_clinic_referral_requested',
            'message' => 'Another ordinary null-linked notification.',
            'is_read' => false,
            'created_at' => '2026-08-04 10:20:00',
        ]);

        $this->assertQueryFails(fn () => DB::table('customer_notifications')->insert([
            'user_id' => 1,
            'grooming_clinic_referral_id' => $referral->id,
            'type' => 'grooming_clinic_referral_requested',
            'message' => 'Duplicate event.',
            'is_read' => false,
            'created_at' => '2026-08-04 10:25:00',
        ]));
        $this->assertDeleteFails('grooming_clinic_referrals', 'id', $referral->id);
        $this->assertTrue($first->groomingClinicReferral->is($referral));
        $this->assertCount(2, $referral->notifications);
    }

    public function test_user_deletion_nulls_audit_ids_and_preserves_name_snapshots(): void
    {
        $referral = $this->createReferral([
            'accepted_by_user_id' => 2,
            'accepted_by_name' => 'Staff Member',
            'clinic_review_started_by_user_id' => 2,
            'clinic_review_started_by_name' => 'Staff Member',
            'cancelled_by_user_id' => 2,
            'cancelled_by_name' => 'Staff Member',
            'resolved_by_user_id' => 2,
            'resolved_by_name' => 'Staff Member',
        ]);

        DB::table('customer_notifications')->delete();
        DB::table('users')->where('user_id', 2)->delete();
        DB::table('users')->where('user_id', 1)->delete();
        $referral->refresh();

        $this->assertNull($referral->owner_user_id_at_referral);
        $this->assertNull($referral->referred_by_user_id);
        $this->assertNull($referral->accepted_by_user_id);
        $this->assertNull($referral->clinic_review_started_by_user_id);
        $this->assertNull($referral->cancelled_by_user_id);
        $this->assertNull($referral->resolved_by_user_id);
        $this->assertSame('Customer Owner', $referral->owner_name_at_referral);
        $this->assertSame('Staff Member', $referral->referred_by_name);
        $this->assertSame('Staff Member', $referral->accepted_by_name);
    }

    public function test_model_constants_scopes_relationships_uuid_and_immutability(): void
    {
        $referral = $this->createReferral([
            'consent_response_id' => 501,
            'clinic_appointment_id' => 601,
        ]);

        $this->assertTrue(Str::isUuid($referral->public_id));
        $this->assertTrue($referral->consent_required);
        $this->assertNotNull($referral->referred_at);
        $this->assertNotNull($referral->created_at);
        $this->assertTrue(GroomingClinicReferral::isValidStatus('pending_consent'));
        $this->assertTrue(GroomingClinicReferral::isValidUrgency('emergency'));
        $this->assertTrue(GroomingClinicReferral::isValidGroomingClearanceStatus('do_not_resume'));
        $this->assertSame('Pending clinic acceptance', GroomingClinicReferral::statusLabel(
            GroomingClinicReferral::STATUS_PENDING_CLINIC_ACCEPTANCE,
        ));
        $this->assertSame('Emergency', GroomingClinicReferral::urgencyLabel('emergency'));
        $this->assertSame('Cleared to resume', GroomingClinicReferral::groomingClearanceLabel(
            GroomingClinicReferral::CLEARANCE_CLEARED_TO_RESUME,
        ));

        $this->assertSame(401, $referral->groomingMedicalConcern->id);
        $this->assertSame(201, $referral->booking->booking_id);
        $this->assertSame(301, $referral->bookingPet->booking_pet_id);
        $this->assertSame(101, $referral->pet->pet_id);
        $this->assertSame(1, $referral->ownerAtReferral->user_id);
        $this->assertSame(2, $referral->referredBy->user_id);
        $this->assertTrue(GroomingMedicalConcern::findOrFail(401)->clinicReferral->is($referral));
        $this->assertTrue(Booking::findOrFail(201)->groomingClinicReferrals->contains($referral));
        $this->assertTrue(BookingPet::findOrFail(301)->groomingClinicReferrals->contains($referral));
        $this->assertTrue(Pet::findOrFail(101)->groomingClinicReferrals->contains($referral));
        $this->assertTrue(ClinicAppointment::findOrFail(601)->groomingClinicReferral->is($referral));
        $this->assertTrue(GroomingMedicalConcernResponse::findOrFail(501)
            ->clinicReferralConsent->is($referral));
        $this->assertTrue(User::findOrFail(1)->groomingClinicReferralsOwnedAtReferral->contains($referral));
        $this->assertTrue(User::findOrFail(2)->groomingClinicReferralsReferred->contains($referral));

        $this->createReferral([
            'grooming_medical_concern_id' => 402,
            'booking_pet_id' => 302,
            'pet_id' => 102,
            'request_token' => '22222222-2222-4222-8222-222222222229',
            'status' => GroomingClinicReferral::STATUS_PENDING_CLINIC_ACCEPTANCE,
            'urgency' => GroomingClinicReferral::URGENCY_URGENT,
        ]);
        $this->createReferral([
            'grooming_medical_concern_id' => 403,
            'booking_id' => 202,
            'booking_pet_id' => 303,
            'request_token' => '33333333-3333-4333-8333-333333333339',
            'status' => GroomingClinicReferral::STATUS_UNDER_CLINIC_REVIEW,
            'urgency' => GroomingClinicReferral::URGENCY_EMERGENCY,
        ]);
        $this->createReferral([
            'grooming_medical_concern_id' => 404,
            'request_token' => '44444444-4444-4444-8444-444444444449',
            'status' => GroomingClinicReferral::STATUS_COMPLETED,
        ]);
        $this->assertSame(3, GroomingClinicReferral::active()->count());
        $this->assertSame(1, GroomingClinicReferral::pendingConsent()->count());
        $this->assertSame(1, GroomingClinicReferral::pendingClinicAcceptance()->count());
        $this->assertSame(1, GroomingClinicReferral::underClinicReview()->count());
        $this->assertSame(1, GroomingClinicReferral::terminal()->count());
        $this->assertSame(2, GroomingClinicReferral::urgentOrEmergency()->count());

        $referral->status = GroomingClinicReferral::STATUS_ACCEPTED;
        $referral->grooming_clearance_status = GroomingClinicReferral::CLEARANCE_NOT_APPLICABLE;
        $referral->accepted_by_user_id = 2;
        $referral->accepted_by_name = 'Staff Member';
        $referral->accepted_at = '2026-08-04 11:00:00';
        $referral->save();
        $this->assertSame(3, GroomingClinicReferral::active()->count());
        $this->assertSame(0, GroomingClinicReferral::pendingConsent()->count());

        try {
            $referral->referral_reason = 'Rewritten original reason';
            $referral->save();
            $this->fail('Immutable referral facts should reject updates.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('Observed medical concern during grooming.', $referral->fresh()->referral_reason);

        try {
            $referral->delete();
            $this->fail('Referral history should reject hard deletion.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'clinic-referral'));
        $this->assertCount(0, $routes);
    }

    private function createPrerequisiteSchema(): void
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
            $table->unsignedInteger('user_id')->nullable();
            $table->string('booking_reference');
            $table->foreign('user_id')->references('user_id')->on('users')->nullOnDelete();
        });
        Schema::create('booking_pets', function (Blueprint $table) {
            $table->increments('booking_pet_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('pet_id')->nullable();
            $table->foreign('booking_id')->references('booking_id')->on('bookings')->cascadeOnDelete();
            $table->foreign('pet_id')->references('pet_id')->on('pets')->nullOnDelete();
            $table->unique(
                ['booking_pet_id', 'booking_id', 'pet_id'],
                'bp_concern_identity_uq',
            );
        });
        Schema::create('clinic_appointments', function (Blueprint $table) {
            $table->id();
            $table->string('appointment_reference');
            $table->timestamps();
        });
        Schema::create('grooming_medical_concerns', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('booking_pet_id');
            $table->unsignedInteger('pet_id');
            $table->unsignedBigInteger('clinic_appointment_id')->nullable();
            $table->string('status', 30)->default('open');
            $table->timestamps();
            $table->unique(
                ['id', 'booking_id', 'booking_pet_id', 'pet_id'],
                'gmc_review_context_uq',
            );
            $table->foreign(
                ['booking_pet_id', 'booking_id', 'pet_id'],
                'gmc_booking_pet_fk',
            )->references(['booking_pet_id', 'booking_id', 'pet_id'])
                ->on('booking_pets')->restrictOnDelete();
            $table->foreign('clinic_appointment_id')->references('id')
                ->on('clinic_appointments')->restrictOnDelete();
        });
        Schema::create('grooming_medical_concern_responses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('concern_id');
            $table->unsignedInteger('responded_by_user_id')->nullable();
            $table->string('responded_by_name', 200);
            $table->string('response_kind', 30);
            $table->string('decision', 30);
            $table->text('statement_text');
            $table->string('statement_version', 50);
            $table->string('signature_name', 200)->nullable();
            $table->timestamp('responded_at');
            $table->timestamp('created_at');
            $table->unique(['id', 'concern_id'], 'gmcr_ref_context_uq');
            $table->unique(['concern_id', 'response_kind'], 'gmcr_concern_kind_uq');
            $table->foreign('concern_id')->references('id')
                ->on('grooming_medical_concerns')->restrictOnDelete();
            $table->foreign('responded_by_user_id')->references('user_id')
                ->on('users')->nullOnDelete();
        });
        Schema::create('customer_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('booking_id')->nullable();
            $table->unsignedBigInteger('grooming_medical_concern_id')->nullable();
            $table->string('type', 50);
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->timestamp('created_at');
            $table->foreign('user_id')->references('user_id')->on('users')->restrictOnDelete();
            $table->foreign('booking_id')->references('booking_id')->on('bookings')->restrictOnDelete();
            $table->foreign('grooming_medical_concern_id')->references('id')
                ->on('grooming_medical_concerns')->restrictOnDelete();
        });
    }

    private function seedPrerequisiteData(): void
    {
        DB::table('users')->insert([
            ['user_id' => 1, 'first_name' => 'Customer', 'last_name' => 'Owner', 'role' => 'customer'],
            ['user_id' => 2, 'first_name' => 'Staff', 'last_name' => 'Member', 'role' => 'staff'],
        ]);
        DB::table('pets')->insert([
            ['pet_id' => 101, 'user_id' => 1, 'pet_name' => 'Alpha'],
            ['pet_id' => 102, 'user_id' => 1, 'pet_name' => 'Beta'],
        ]);
        DB::table('bookings')->insert([
            ['booking_id' => 201, 'user_id' => 1, 'booking_reference' => 'BK-201'],
            ['booking_id' => 202, 'user_id' => 1, 'booking_reference' => 'BK-202'],
        ]);
        DB::table('booking_pets')->insert([
            ['booking_pet_id' => 301, 'booking_id' => 201, 'pet_id' => 101],
            ['booking_pet_id' => 302, 'booking_id' => 201, 'pet_id' => 102],
            ['booking_pet_id' => 303, 'booking_id' => 202, 'pet_id' => 101],
        ]);
        DB::table('clinic_appointments')->insert([
            ['id' => 601, 'appointment_reference' => 'CL-601', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 602, 'appointment_reference' => 'CL-602', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('grooming_medical_concerns')->insert([
            ['id' => 401, 'public_id' => '11111111-1111-4111-8111-111111111111', 'booking_id' => 201, 'booking_pet_id' => 301, 'pet_id' => 101, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 402, 'public_id' => '22222222-2222-4222-8222-222222222222', 'booking_id' => 201, 'booking_pet_id' => 302, 'pet_id' => 102, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 403, 'public_id' => '33333333-3333-4333-8333-333333333333', 'booking_id' => 202, 'booking_pet_id' => 303, 'pet_id' => 101, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 404, 'public_id' => '44444444-4444-4444-8444-444444444444', 'booking_id' => 201, 'booking_pet_id' => 301, 'pet_id' => 101, 'status' => 'resolved', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('grooming_medical_concern_responses')->insert([
            ['id' => 501, 'concern_id' => 401, 'responded_by_user_id' => 1, 'responded_by_name' => 'Customer Owner', 'response_kind' => 'clinic_referral_consent', 'decision' => 'approved', 'statement_text' => 'Referral consent.', 'statement_version' => 'ref-v1', 'signature_name' => 'Customer Owner', 'responded_at' => now(), 'created_at' => now()],
            ['id' => 502, 'concern_id' => 402, 'responded_by_user_id' => 1, 'responded_by_name' => 'Customer Owner', 'response_kind' => 'consent', 'decision' => 'approved', 'statement_text' => 'Grooming consent.', 'statement_version' => 'consent-v1', 'signature_name' => 'Customer Owner', 'responded_at' => now(), 'created_at' => now()],
        ]);
        DB::table('customer_notifications')->insert([
            'id' => 701,
            'user_id' => 1,
            'booking_id' => 201,
            'grooming_medical_concern_id' => 401,
            'type' => 'grooming_medical_concern',
            'message' => 'Existing concern notification.',
            'is_read' => false,
            'created_at' => now(),
        ]);
    }

    private function createReferral(array $overrides = []): GroomingClinicReferral
    {
        return GroomingClinicReferral::create(array_merge([
            'request_token' => '11111111-1111-4111-8111-111111111119',
            'grooming_medical_concern_id' => 401,
            'booking_id' => 201,
            'booking_pet_id' => 301,
            'pet_id' => 101,
            'status' => GroomingClinicReferral::STATUS_PENDING_CONSENT,
            'urgency' => GroomingClinicReferral::URGENCY_ROUTINE,
            'referral_reason' => 'Observed medical concern during grooming.',
            'customer_explanation' => 'A clinic assessment has been recommended.',
            'consent_required' => true,
            'owner_user_id_at_referral' => 1,
            'owner_name_at_referral' => 'Customer Owner',
            'referred_by_user_id' => 2,
            'referred_by_name' => 'Staff Member',
            'referred_at' => '2026-08-04 10:00:00',
            'grooming_clearance_status' => GroomingClinicReferral::CLEARANCE_PENDING,
        ], $overrides));
    }

    private function assertReferralInsertFails(array $overrides): void
    {
        $record = array_merge([
            'public_id' => (string) Str::uuid(),
            'request_token' => (string) Str::uuid(),
            'grooming_medical_concern_id' => 401,
            'booking_id' => 201,
            'booking_pet_id' => 301,
            'pet_id' => 101,
            'status' => 'pending_consent',
            'urgency' => 'routine',
            'referral_reason' => 'Invalid context.',
            'customer_explanation' => 'Invalid context.',
            'consent_required' => true,
            'owner_user_id_at_referral' => 1,
            'owner_name_at_referral' => 'Customer Owner',
            'referred_by_user_id' => 2,
            'referred_by_name' => 'Staff Member',
            'referred_at' => '2026-08-04 10:30:00',
            'grooming_clearance_status' => 'pending',
            'created_at' => '2026-08-04 10:30:00',
        ], $overrides);

        $this->assertQueryFails(
            fn () => DB::table('grooming_clinic_referrals')->insert($record),
        );
    }

    private function assertQueryFails(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a database integrity error.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function assertDeleteFails(string $table, string $key, int $value): void
    {
        try {
            DB::table($table)->where($key, $value)->delete();
            $this->fail("Deleting the linked {$table} row should be restricted.");
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
        array $columns,
        string $referencedTable,
        array $referencedColumns,
        string $deleteRule,
    ): void {
        $groups = collect(DB::select("PRAGMA foreign_key_list('{$table}')"))
            ->groupBy('id');

        $matching = $groups->first(function ($rows) use (
            $columns,
            $referencedTable,
            $referencedColumns,
            $deleteRule,
        ) {
            $rows = $rows->sortBy('seq')->values();

            return $rows->first()->table === $referencedTable
                && $rows->pluck('from')->all() === $columns
                && $rows->pluck('to')->all() === $referencedColumns
                && $rows->pluck('on_delete')->unique()->values()->all() === [$deleteRule];
        });

        $this->assertNotNull(
            $matching,
            'Missing expected foreign key on '.$table.'.',
        );
    }
}
