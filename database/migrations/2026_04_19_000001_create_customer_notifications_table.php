<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('booking_id')->nullable();
            $table->enum('type', ['reminder_24h', 'reminder_3h', 'grooming_started', 'ready_for_pickup']);
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->timestamp('created_at')->useCurrent();
        });

        // Expand admin notifications ENUM to include payment_due
        DB::statement("
            ALTER TABLE notifications
            MODIFY COLUMN type ENUM('booked','cancelled','rescheduled','payment_due') NOT NULL
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_notifications');

        DB::statement("
            ALTER TABLE notifications
            MODIFY COLUMN type ENUM('booked','cancelled','rescheduled') NOT NULL
        ");
    }
};
