<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add 'checked_in' and 'for_pickup' to the bookings.status ENUM.
     * These values are required by AdminBookingController but were missing
     * from the original schema, causing silent failures on check-in.
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
                'waiting',
                'in_progress',
                'groomed',
                'waiting_for_payment',
                'completed',
                'cancelled',
                'no_show'
            ) NOT NULL DEFAULT 'waiting_to_arrive'
        ");
    }
};
