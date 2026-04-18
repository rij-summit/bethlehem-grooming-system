<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;
use App\Models\Booking;
use App\Models\Notification;
use App\Models\ClinicClosure;

class FlagNoShowBookings extends Command
{
    protected $signature   = 'bookings:flag-no-shows';
    protected $description = 'Flags bookings as no_show if the time window ended 30+ minutes ago and the customer never checked in.';

    public function handle(): void
    {
        $today = Carbon::today()->toDateString();
        $now   = Carbon::now();

        // Don't run if the clinic already manually stopped receiving today
        // (stopToday already flagged everything at that point)
        $stoppedToday = ClinicClosure::where('type', 'stop_today')
            ->where('start_date', $today)
            ->where('is_active', 1)
            ->exists();

        if ($stoppedToday) {
            $this->info('Clinic already stopped for today — skipping.');
            return;
        }

        // Find bookings that are still waiting_to_arrive for today
        // where the time window ended 30+ minutes ago
        $bookings = Booking::where('booking_date', $today)
            ->where('status', 'waiting_to_arrive')
            ->with('timeWindow')
            ->get()
            ->filter(function ($booking) use ($now) {
                $window = $booking->timeWindow;
                if (!$window || !$window->end_time) return false;

                // Parse end_time (HH:MM:SS) into today's Carbon datetime
                $windowEnd = Carbon::parse($today . ' ' . $window->end_time);
                return $now->greaterThan($windowEnd->addMinutes(30));
            });

        $count = 0;

        foreach ($bookings as $booking) {
            $booking->update(['status' => 'no_show']);

            $windowLabel = $booking->timeWindow?->window_label ?? 'Unknown window';
            $endTime     = $booking->timeWindow?->end_time
                ? Carbon::parse($booking->timeWindow->end_time)->format('g:i A')
                : '—';

            Notification::create([
                'type'       => 'no_show',
                'booking_id' => $booking->booking_id,
                'message'    => "Booking {$booking->booking_reference} was automatically marked as no-show. Window \"{$windowLabel}\" ended at {$endTime} with no check-in.",
                'is_read'    => 0,
                'created_at' => now(),
            ]);

            $count++;
        }

        $this->info("Flagged {$count} booking(s) as no-show.");
    }
}
