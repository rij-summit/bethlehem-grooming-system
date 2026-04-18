<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('clinic_closures')) return;

        Schema::create('clinic_closures', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['stop_today', 'blocked_date']);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('reason')->nullable();  // e.g. "Christmas", "Summer Vacation"
            $table->boolean('is_active')->default(1);
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();
        });

        // Add no_show + existing code-used types to notifications type enum
        DB::statement("
            ALTER TABLE notifications
            MODIFY COLUMN type ENUM(
                'booking_confirmed',
                'status_update',
                'queue_update',
                'payment_confirmed',
                'walk_in_registered',
                'reminder_24hr',
                'reminder_3hr',
                'no_show',
                'booked',
                'rescheduled',
                'cancelled',
                'general'
            ) NOT NULL DEFAULT 'general'
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_closures');

        DB::statement("
            ALTER TABLE notifications
            MODIFY COLUMN type ENUM(
                'booking_confirmed',
                'status_update',
                'queue_update',
                'payment_confirmed',
                'walk_in_registered',
                'reminder_24hr',
                'reminder_3hr',
                'general'
            ) NOT NULL DEFAULT 'general'
        ");
    }
};
