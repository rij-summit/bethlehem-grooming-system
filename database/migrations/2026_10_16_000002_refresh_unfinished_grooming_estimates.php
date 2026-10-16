<?php

use App\Models\Booking;
use App\Services\GroomingTimeEstimate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Booking::whereIn('status', ['waiting_to_arrive', 'checked_in', 'in_progress'])
            ->with(['bookingPets.pet', 'bookingServices.service'])->chunkById(100, function ($bookings) {
                $engine = app(GroomingTimeEstimate::class);
                foreach ($bookings as $booking) {
                    foreach ($booking->bookingPets as $pet) $engine->recalculate($booking, $pet);
                }
            }, 'booking_id');
    }

    public function down(): void {}
};
