<?php

namespace Tests\Feature;

use App\Models\ClinicRecord;
use App\Models\ClinicVital;
use App\Models\GroomingMedicalConcernResponse;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class ReferralPrerequisiteHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON');
        $this->createPrerequisiteSchema();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('clinic_vitals');
        Schema::dropIfExists('clinic_records');
        Schema::dropIfExists('clinic_appointments');
        Schema::dropIfExists('grooming_medical_concern_responses');
        Schema::dropIfExists('grooming_medical_concerns');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_sqlite_migrations_preserve_audits_add_capture_context_and_roll_back_safely(): void
    {
        $this->seedHistoricalResponse();
        $reportedAt = DB::table('grooming_medical_concerns')->value('reported_at');
        $respondedAt = DB::table('grooming_medical_concern_responses')->value('responded_at');

        $timestampMigration = $this->migration('2026_08_04_000001_correct_grooming_concern_audit_timestamps.php');
        $captureMigration = $this->migration('2026_08_04_000002_add_response_capture_audit_fields.php');
        $uniquenessMigration = $this->migration('2026_08_04_000003_enforce_single_clinic_record_and_vitals.php');

        $timestampMigration->up();
        $captureMigration->up();
        $uniquenessMigration->up();

        $this->assertSame($reportedAt, DB::table('grooming_medical_concerns')->value('reported_at'));
        $this->assertSame($respondedAt, DB::table('grooming_medical_concern_responses')->value('responded_at'));
        $this->assertTrue(Schema::hasColumns('grooming_medical_concern_responses', [
            'response_channel',
            'captured_by_user_id',
            'captured_by_name',
        ]));
        $this->assertDatabaseHas('grooming_medical_concern_responses', [
            'id' => 100,
            'response_channel' => GroomingMedicalConcernResponse::CHANNEL_PORTAL,
            'captured_by_user_id' => null,
            'captured_by_name' => null,
        ]);
        $this->assertContains('gmcr_ref_context_uq', $this->indexNames('grooming_medical_concern_responses'));
        $this->assertContains('gmcr_concern_kind_uq', $this->indexNames('grooming_medical_concern_responses'));
        $this->assertContains('cr_appt_uq', $this->indexNames('clinic_records'));
        $this->assertContains('cv_appt_uq', $this->indexNames('clinic_vitals'));

        DB::table('grooming_medical_concerns')->where('id', 10)->update(['category' => 'Updated safely']);
        DB::table('grooming_medical_concern_responses')->where('id', 100)->update(['decision' => 'declined']);
        $this->assertSame($reportedAt, DB::table('grooming_medical_concerns')->value('reported_at'));
        $this->assertSame($respondedAt, DB::table('grooming_medical_concern_responses')->value('responded_at'));

        DB::table('grooming_medical_concerns')->insert([
            'id' => 11,
            'reported_at' => '2026-07-25 10:20:30',
            'category' => 'Referral readiness',
        ]);
        DB::table('grooming_medical_concern_responses')->insert([
            'id' => 101,
            'concern_id' => 11,
            'responded_by_user_id' => null,
            'responded_by_name' => 'Walk-in Owner',
            'response_channel' => GroomingMedicalConcernResponse::CHANNEL_IN_PERSON_STAFF_CAPTURED,
            'captured_by_user_id' => 2,
            'captured_by_name' => 'Staff Capture',
            'response_kind' => GroomingMedicalConcernResponse::KIND_CLINIC_REFERRAL_CONSENT,
            'decision' => GroomingMedicalConcernResponse::DECISION_APPROVED,
            'statement_text' => 'Future versioned referral statement.',
            'statement_version' => 'future-referral-v1',
            'signature_name' => 'Walk-in Owner',
            'responded_at' => '2026-07-25 10:25:30',
            'created_at' => '2026-07-25 10:25:30',
        ]);

        $captured = GroomingMedicalConcernResponse::query()->findOrFail(101);
        $this->assertSame('Staff', $captured->capturedBy?->first_name);
        User::query()->findOrFail(2)->delete();
        $this->assertDatabaseHas('grooming_medical_concern_responses', [
            'id' => 101,
            'captured_by_user_id' => null,
            'captured_by_name' => 'Staff Capture',
            'response_channel' => GroomingMedicalConcernResponse::CHANNEL_IN_PERSON_STAFF_CAPTURED,
        ]);

        $uniquenessMigration->down();
        $captureMigration->down();
        $timestampMigration->down();

        $this->assertFalse(Schema::hasColumn('grooming_medical_concern_responses', 'response_channel'));
        $this->assertNotContains('cr_appt_uq', $this->indexNames('clinic_records'));
        $this->assertNotContains('cv_appt_uq', $this->indexNames('clinic_vitals'));
        $this->assertSame($reportedAt, DB::table('grooming_medical_concerns')->where('id', 10)->value('reported_at'));
        $this->assertSame($respondedAt, DB::table('grooming_medical_concern_responses')->where('id', 100)->value('responded_at'));
    }

    public function test_response_constants_relationships_and_immutability_support_future_capture_without_a_route(): void
    {
        $this->seedHistoricalResponse();
        $this->migration('2026_08_04_000002_add_response_capture_audit_fields.php')->up();

        $this->assertTrue(GroomingMedicalConcernResponse::isValidKind(
            GroomingMedicalConcernResponse::KIND_CLINIC_REFERRAL_CONSENT,
        ));
        $this->assertTrue(GroomingMedicalConcernResponse::isValidDecisionForKind(
            GroomingMedicalConcernResponse::KIND_CLINIC_REFERRAL_CONSENT,
            GroomingMedicalConcernResponse::DECISION_APPROVED,
        ));
        $this->assertTrue(GroomingMedicalConcernResponse::isValidChannel(
            GroomingMedicalConcernResponse::CHANNEL_IN_PERSON_STAFF_CAPTURED,
        ));
        $this->assertSame(
            'In-person, staff captured',
            GroomingMedicalConcernResponse::channelLabel(
                GroomingMedicalConcernResponse::CHANNEL_IN_PERSON_STAFF_CAPTURED,
            ),
        );
        $this->assertSame(
            'Portal',
            GroomingMedicalConcernResponse::channelLabel(
                GroomingMedicalConcernResponse::CHANNEL_PORTAL,
            ),
        );

        $response = GroomingMedicalConcernResponse::query()->findOrFail(100);
        try {
            $response->update(['captured_by_name' => 'Forbidden update']);
            $this->fail('An existing response was updated despite its immutability guard.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
        try {
            $response->delete();
            $this->fail('An existing response was deleted despite its immutability guard.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        $hasOutOfScopeReferralRoute = collect(Route::getRoutes()->getRoutes())
            ->contains(fn ($route) => str_contains($route->uri(), 'clinic-referral')
                && (
                    array_intersect(['PATCH', 'PUT', 'DELETE'], $route->methods()) !== []
                    || str_contains($route->uri(), 'accept')
                    || str_contains($route->uri(), 'appointment')
                ));
        $this->assertFalse($hasOutOfScopeReferralRoute);
    }

    public function test_response_and_clinic_uniqueness_constraints_are_enforced_without_cross_appointment_collisions(): void
    {
        $this->seedHistoricalResponse();
        $this->migration('2026_08_04_000002_add_response_capture_audit_fields.php')->up();
        $this->migration('2026_08_04_000003_enforce_single_clinic_record_and_vitals.php')->up();

        $this->assertQueryFails(function (): void {
            DB::table('grooming_medical_concern_responses')->insert([
                'id' => 101,
                'concern_id' => 10,
                'responded_by_name' => 'Duplicate Owner',
                'response_kind' => GroomingMedicalConcernResponse::KIND_ACKNOWLEDGMENT,
                'decision' => GroomingMedicalConcernResponse::DECISION_ACKNOWLEDGED,
                'statement_text' => 'Duplicate.',
                'statement_version' => 'duplicate-v1',
                'responded_at' => '2026-07-25 10:25:30',
                'created_at' => '2026-07-25 10:25:30',
            ]);
        });

        DB::table('clinic_appointments')->insert([
            ['id' => 1, 'status' => 'in_consultation'],
            ['id' => 2, 'status' => 'in_consultation'],
        ]);
        ClinicRecord::query()->create(['clinic_appointment_id' => 1, 'diagnosis' => 'Initial']);
        ClinicVital::query()->create(['clinic_appointment_id' => 1, 'weight_kg' => 5.25]);
        ClinicRecord::query()->create(['clinic_appointment_id' => 2, 'diagnosis' => 'Separate']);
        ClinicVital::query()->create(['clinic_appointment_id' => 2, 'weight_kg' => 7.50]);

        $this->assertQueryFails(
            fn () => DB::table('clinic_records')->insert(['clinic_appointment_id' => 1]),
        );
        $this->assertQueryFails(
            fn () => DB::table('clinic_vitals')->insert(['clinic_appointment_id' => 1]),
        );

        ClinicRecord::query()->updateOrCreate(
            ['clinic_appointment_id' => 1],
            ['diagnosis' => 'Updated through updateOrCreate'],
        );
        ClinicVital::query()->updateOrCreate(
            ['clinic_appointment_id' => 1],
            ['weight_kg' => 5.50],
        );
        $this->assertSame(2, ClinicRecord::query()->count());
        $this->assertSame(2, ClinicVital::query()->count());
        $this->assertDatabaseHas('clinic_records', [
            'clinic_appointment_id' => 1,
            'diagnosis' => 'Updated through updateOrCreate',
        ]);
    }

    public function test_clinic_uniqueness_migration_stops_when_duplicate_history_exists(): void
    {
        DB::table('clinic_appointments')->insert(['id' => 1, 'status' => 'in_consultation']);
        DB::table('clinic_records')->insert([
            ['clinic_appointment_id' => 1],
            ['clinic_appointment_id' => 1],
        ]);

        try {
            $this->migration('2026_08_04_000003_enforce_single_clinic_record_and_vitals.php')->up();
            $this->fail('The migration did not stop for duplicate clinic records.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('duplicates', $exception->getMessage());
        }

        $this->assertNotContains('cr_appt_uq', $this->indexNames('clinic_records'));
        $this->assertNotContains('cv_appt_uq', $this->indexNames('clinic_vitals'));
        $this->assertDatabaseCount('clinic_records', 2);
    }

    private function createPrerequisiteSchema(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('role');
        });
        Schema::create('grooming_medical_concerns', function (Blueprint $table) {
            $table->id();
            $table->timestamp('reported_at');
            $table->string('category');
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
            $table->unique(['concern_id', 'response_kind'], 'gmcr_concern_kind_uq');
            $table->foreign('concern_id', 'gmcr_concern_fk')
                ->references('id')->on('grooming_medical_concerns')->restrictOnDelete();
            $table->foreign('responded_by_user_id', 'gmcr_responded_by_fk')
                ->references('user_id')->on('users')->nullOnDelete();
        });
        Schema::create('clinic_appointments', function (Blueprint $table) {
            $table->id();
            $table->string('status');
        });
        Schema::create('clinic_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_appointment_id');
            $table->text('diagnosis')->nullable();
            $table->timestamps();
        });
        Schema::create('clinic_vitals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_appointment_id');
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->timestamps();
        });
    }

    private function seedHistoricalResponse(): void
    {
        DB::table('users')->insert([
            ['user_id' => 1, 'first_name' => 'Owner', 'last_name' => 'One', 'role' => 'customer'],
            ['user_id' => 2, 'first_name' => 'Staff', 'last_name' => 'Capture', 'role' => 'staff'],
        ]);
        DB::table('grooming_medical_concerns')->insert([
            'id' => 10,
            'reported_at' => '2026-07-24 09:10:11',
            'category' => 'Original concern',
        ]);
        DB::table('grooming_medical_concern_responses')->insert([
            'id' => 100,
            'concern_id' => 10,
            'responded_by_user_id' => 1,
            'responded_by_name' => 'Owner One',
            'response_kind' => GroomingMedicalConcernResponse::KIND_ACKNOWLEDGMENT,
            'decision' => GroomingMedicalConcernResponse::DECISION_ACKNOWLEDGED,
            'statement_text' => 'Immutable statement.',
            'statement_version' => 'ack-v1',
            'signature_name' => 'Owner One',
            'responded_at' => '2026-07-24 09:15:12',
            'created_at' => '2026-07-24 09:15:12',
        ]);
    }

    private function migration(string $file): object
    {
        return require database_path('migrations/'.$file);
    }

    private function indexNames(string $table): array
    {
        return collect(DB::select("PRAGMA index_list('{$table}')"))
            ->pluck('name')
            ->all();
    }

    private function assertQueryFails(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a database integrity error.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }
}
