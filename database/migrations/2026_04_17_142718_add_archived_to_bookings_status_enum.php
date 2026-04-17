<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add 'archived' to the bookings.status ENUM.
     */
    public function up(): void
    {
        DB::statement("
            ALTER TABLE bookings
            MODIFY COLUMN status ENUM(
                'waiting_to_arrive',
                'checked_in',
                'in_progress',
                'for_pickup',
                'archived',
                'waiting',
                'groomed',
                'waiting_for_payment',
                'completed',
                'cancelled',
                'no_show'
            ) NOT NULL DEFAULT 'waiting_to_arrive'
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE bookings
            MODIFY COLUMN status ENUM(
                'waiting_to_arrive',
                'checked_in',
                'in_progress',
                'for_pickup',
                'waiting',
                'groomed',
                'waiting_for_payment',
                'completed',
                'cancelled',
                'no_show'
            ) NOT NULL DEFAULT 'waiting_to_arrive'
        ");
    }
};
