<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminNotificationMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('bookings', function (Blueprint $table): void {
            $table->increments('booking_id');
        });
        Schema::create('clinic_appointments', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('notifications', function (Blueprint $table): void {
            $table->increments('notification_id');
            $table->enum('type', ['booked', 'cancelled', 'rescheduled', 'payment_due']);
            $table->unsignedInteger('booking_id');
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->timestamp('created_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('clinic_appointments');
        Schema::dropIfExists('bookings');

        parent::tearDown();
    }

    public function test_migration_supports_clinic_notifications_without_a_grooming_booking(): void
    {
        DB::table('clinic_appointments')->insert(['id' => 25]);

        $migration = require base_path(
            'database/migrations/2026_08_21_000001_expand_admin_notifications_for_clinic.php',
        );
        $migration->up();

        $this->assertTrue(Schema::hasColumn('notifications', 'clinic_appointment_id'));

        DB::table('notifications')->insert([
            'type' => 'clinic_cancelled',
            'booking_id' => null,
            'clinic_appointment_id' => 25,
            'message' => 'Clinic appointment cancelled.',
            'is_read' => false,
            'created_at' => now(),
        ]);

        $this->assertDatabaseHas('notifications', [
            'type' => 'clinic_cancelled',
            'booking_id' => null,
            'clinic_appointment_id' => 25,
        ]);
    }
}
