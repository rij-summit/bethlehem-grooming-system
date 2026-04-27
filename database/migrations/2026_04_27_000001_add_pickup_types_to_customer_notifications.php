<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE customer_notifications
            MODIFY COLUMN type ENUM(
                'reminder_24h',
                'reminder_3h',
                'grooming_started',
                'ready_for_pickup',
                'pickup_reminder',
                'picked_up'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE customer_notifications
            MODIFY COLUMN type ENUM(
                'reminder_24h',
                'reminder_3h',
                'grooming_started',
                'ready_for_pickup'
            ) NOT NULL
        ");
    }
};
