<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;
use App\Models\Booking;
use App\Models\CustomerNotification;

class SendPickupReminders extends Command
{
    protected $signature   = 'pickups:remind';
    protected $description = 'Send hourly pickup reminders to customers whose pets are ready but not yet collected.';

    public function handle(): void
    {
        $today = Carbon::today()->toDateString();
        $now   = Carbon::now();

        $bookings = Booking::where('status', 'released')
            ->where('booking_date', $today)
            ->with(['user', 'bookingPets.pet'])
            ->get();

        $sent = 0;

        foreach ($bookings as $booking) {
            if (!$booking->user) {
                continue;
            }

            $petName = $booking->bookingPets->first()?->pet?->pet_name ?? 'your pet';

            // Only send if no pickup_reminder has been sent in the last 60 minutes
            $lastReminder = CustomerNotification::where('user_id', $booking->user->user_id)
                ->where('booking_id', $booking->booking_id)
                ->where('type', 'pickup_reminder')
                ->latest('created_at')
                ->first();

            if ($lastReminder && $now->diffInMinutes($lastReminder->created_at) < 60) {
                continue;
            }

            CustomerNotification::create([
                'user_id'    => $booking->user->user_id,
                'booking_id' => $booking->booking_id,
                'type'       => 'pickup_reminder',
                'message'    => "Reminder: {$petName} is still waiting to be picked up at the clinic. Please come at your earliest convenience!",
                'is_read'    => false,
                'created_at' => $now,
            ]);

            $sent++;
        }

        $this->info("Pickup reminders sent: {$sent}");
    }
}
