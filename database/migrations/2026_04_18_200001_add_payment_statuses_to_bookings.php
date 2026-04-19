<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Expand the status ENUM to include for_payment and released
        DB::statement("
            ALTER TABLE bookings
            MODIFY COLUMN status ENUM(
                'waiting_to_arrive',
                'checked_in',
                'in_progress',
                'for_pickup',
                'for_payment',
                'released',
                'archived',
                'waiting',
                'groomed',
                'waiting_for_payment',
                'completed',
                'cancelled',
                'no_show'
            ) NOT NULL DEFAULT 'waiting_to_arrive'
        ");

        // Add paid flag so early-payment bookings can skip the payment step
        Schema::table('bookings', function (Blueprint $table) {
            $table->boolean('paid')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('paid');
        });

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
};
