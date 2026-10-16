<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedInteger('cancelled_by')->nullable();
        });
        Schema::table('booking_pets', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->unsignedInteger('cancelled_by')->nullable();
        });

        // Preserve old cancellation reasons without inventing missing dates or staff.
        DB::table('booking_pets')->where('grooming_state', 'not_started')
            ->whereNull('grooming_start_time')->whereNull('grooming_end_time')
            ->whereIn('booking_id', DB::table('bookings')->select('booking_id')
                ->where('status', 'cancelled')->orWhere('cancel_count', '>', 0)
                ->orWhere(fn ($query) => $query->whereNotNull('cancellation_reason')->where('cancellation_reason', '!=', '')))
            ->update(['grooming_state' => 'cancelled',
                'cancellation_reason' => DB::raw('(select cancellation_reason from bookings where bookings.booking_id = booking_pets.booking_id)')]);
    }

    public function down(): void
    {
        Schema::table('booking_pets', fn (Blueprint $table) => $table->dropColumn([
            'cancelled_at', 'cancellation_reason', 'cancelled_by',
        ]));
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn([
            'cancelled_at', 'cancelled_by',
        ]));
    }
};
