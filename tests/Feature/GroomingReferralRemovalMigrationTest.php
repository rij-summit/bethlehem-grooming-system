<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GroomingReferralRemovalMigrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropAllTables();

        parent::tearDown();
    }

    public function test_removal_migration_deletes_the_legacy_workflow_without_touching_normal_records(): void
    {
        Schema::create('booking_pets', function (Blueprint $table): void {
            $table->increments('booking_pet_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('pet_id');
            $table->dateTime('grooming_start_time')->nullable();
            $table->dateTime('grooming_end_time')->nullable();
            $table->string('grooming_state', 20);
            $table->unique(
                ['booking_pet_id', 'booking_id', 'pet_id'],
                'bp_concern_identity_uq',
            );
        });
        Schema::create('clinic_appointments', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('grooming_medical_concerns', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('grooming_medical_concern_responses', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('grooming_clinic_referrals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('clinic_appointment_id')->nullable();
        });
        Schema::create('grooming_stopped_payment_reviews', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('customer_notifications', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 50);
            $table->unsignedBigInteger('grooming_medical_concern_id')->nullable();
            $table->unsignedBigInteger('grooming_clinic_referral_id')->nullable();
            $table->index('grooming_medical_concern_id', 'cn_grooming_concern_idx');
            $table->index('grooming_clinic_referral_id', 'cn_grooming_referral_idx');
            $table->unique(
                ['grooming_clinic_referral_id', 'type'],
                'cn_referral_type_uq',
            );
        });

        DB::table('clinic_appointments')->insert([['id' => 10], ['id' => 20]]);
        DB::table('grooming_clinic_referrals')->insert([
            'id' => 1,
            'clinic_appointment_id' => 10,
        ]);
        DB::table('customer_notifications')->insert([
            [
                'id' => 1,
                'type' => 'grooming_medical_concern',
                'grooming_medical_concern_id' => 1,
                'grooming_clinic_referral_id' => null,
            ],
            [
                'id' => 2,
                'type' => 'reminder_24h',
                'grooming_medical_concern_id' => null,
                'grooming_clinic_referral_id' => null,
            ],
            [
                'id' => 3,
                'type' => 'reminder_24h',
                'grooming_medical_concern_id' => null,
                'grooming_clinic_referral_id' => 1,
            ],
        ]);
        DB::table('booking_pets')->insert([
            [
                'booking_pet_id' => 1,
                'booking_id' => 1,
                'pet_id' => 1,
                'grooming_start_time' => '2026-09-16 09:00:00',
                'grooming_end_time' => null,
                'grooming_state' => 'paused',
            ],
            [
                'booking_pet_id' => 2,
                'booking_id' => 1,
                'pet_id' => 2,
                'grooming_start_time' => null,
                'grooming_end_time' => null,
                'grooming_state' => 'stopped',
            ],
            [
                'booking_pet_id' => 3,
                'booking_id' => 1,
                'pet_id' => 3,
                'grooming_start_time' => '2026-09-16 10:00:00',
                'grooming_end_time' => '2026-09-16 11:00:00',
                'grooming_state' => 'stopped',
            ],
        ]);

        $migration = require database_path(
            'migrations/2026_09_16_000001_remove_grooming_clinic_referral_workflow.php',
        );
        $migration->up();

        $this->assertFalse(Schema::hasTable('grooming_medical_concerns'));
        $this->assertFalse(Schema::hasTable('grooming_medical_concern_responses'));
        $this->assertFalse(Schema::hasTable('grooming_clinic_referrals'));
        $this->assertFalse(Schema::hasTable('grooming_stopped_payment_reviews'));
        $this->assertFalse(Schema::hasColumn(
            'customer_notifications',
            'grooming_medical_concern_id',
        ));
        $this->assertFalse(Schema::hasColumn(
            'customer_notifications',
            'grooming_clinic_referral_id',
        ));
        $this->assertDatabaseMissing('customer_notifications', ['id' => 1]);
        $this->assertDatabaseHas('customer_notifications', [
            'id' => 2,
            'type' => 'reminder_24h',
        ]);
        $this->assertDatabaseMissing('customer_notifications', ['id' => 3]);
        $this->assertDatabaseMissing('clinic_appointments', ['id' => 10]);
        $this->assertDatabaseHas('clinic_appointments', ['id' => 20]);
        $this->assertDatabaseHas('booking_pets', [
            'booking_pet_id' => 1,
            'grooming_state' => 'in_progress',
        ]);
        $this->assertDatabaseHas('booking_pets', [
            'booking_pet_id' => 2,
            'grooming_state' => 'not_started',
        ]);
        $this->assertDatabaseHas('booking_pets', [
            'booking_pet_id' => 3,
            'grooming_state' => 'finished',
        ]);
        $this->assertFalse(collect(Schema::getIndexes('booking_pets'))
            ->contains(fn (array $index): bool => ($index['name'] ?? null)
                === 'bp_concern_identity_uq'));
    }
}
