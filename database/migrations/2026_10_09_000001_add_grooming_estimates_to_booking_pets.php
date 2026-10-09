<?php

use App\Models\Booking;
use App\Services\GroomingTimeEstimate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_pets', function (Blueprint $table) {
            $table->string('grooming_preference', 32)->nullable();
            $table->unsignedSmallInteger('grooming_estimate_min')->nullable();
            $table->unsignedSmallInteger('grooming_estimate_max')->nullable();
            $table->json('grooming_estimate_factors')->nullable();
        });
        Booking::whereIn('status', ['waiting_to_arrive', 'checked_in', 'in_progress'])
            ->with(['bookingPets.pet', 'bookingServices.service'])->chunkById(100, function ($bookings) {
                $engine = app(GroomingTimeEstimate::class);
                foreach ($bookings as $booking) {
                    foreach ($booking->bookingPets as $pet) $engine->recalculate($booking, $pet);
                }
            }, 'booking_id');
    }

    public function down(): void
    {
        Schema::table('booking_pets', fn (Blueprint $table) => $table->dropColumn([
            'grooming_preference', 'grooming_estimate_min', 'grooming_estimate_max', 'grooming_estimate_factors',
        ]));
    }
};
