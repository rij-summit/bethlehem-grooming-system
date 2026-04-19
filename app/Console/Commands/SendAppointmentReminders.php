<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;
use App\Models\Booking;
use App\Models\CustomerNotification;

class SendAppointmentReminders extends Command
{
    protected $signature   = 'reminders:send';
    protected $description = 'Send 24-hour and 3-hour appointment reminder notifications to customers.';

    public function handle(): void
    {
        $now = Carbon::now();

        // Find active bookings that have a time window (joined)
        $bookings = Booking::where('status', 'waiting_to_arrive')
            ->whereNotNull('time_window_id')
            ->with(['user', 'timeWindow', 'bookingPets.pet'])
            ->get();

        $sent24h = 0;
        $sent3h  = 0;

        foreach ($bookings as $booking) {
            if (!$booking->timeWindow || !$booking->user) {
                continue;
            }

            // Build the exact appointment datetime from date + time window start
            $appointmentAt = Carbon::parse(
                $booking->booking_date . ' ' . $booking->timeWindow->start_time
            );

            $minutesUntil = $now->diffInMinutes($appointmentAt, false);

            // Skip past appointments
            if ($minutesUntil <= 0) {
                continue;
            }

            $petName   = $booking->bookingPets->first()?->pet?->pet_name ?? 'your pet';
            $timeLabel = $booking->timeWindow->window_label;

            // ── 24-hour reminder: between 23h and 25h from now ──────────────
            if ($minutesUntil >= 1380 && $minutesUntil <= 1500) {
                $alreadySent = CustomerNotification::where('user_id', $booking->user->user_id)
                    ->where('booking_id', $booking->booking_id)
                    ->where('type', 'reminder_24h')
                    ->exists();

                if (!$alreadySent) {
                    CustomerNotification::create([
                        'user_id'    => $booking->user->user_id,
                        'booking_id' => $booking->booking_id,
                        'type'       => 'reminder_24h',
                        'message'    => "Reminder: {$petName}'s grooming appointment is tomorrow at {$timeLabel}. Please don't forget!",
                        'is_read'    => false,
                        'created_at' => now(),
                    ]);
                    $sent24h++;
                }
            }

            // ── 3-hour reminder: between 2h30m and 3h30m from now ──────────
            if ($minutesUntil >= 150 && $minutesUntil <= 210) {
                $alreadySent = CustomerNotification::where('user_id', $booking->user->user_id)
                    ->where('booking_id', $booking->booking_id)
                    ->where('type', 'reminder_3h')
                    ->exists();

                if (!$alreadySent) {
                    CustomerNotification::create([
                        'user_id'    => $booking->user->user_id,
                        'booking_id' => $booking->booking_id,
                        'type'       => 'reminder_3h',
                        'message'    => "Heads up! {$petName}'s grooming appointment is in about 3 hours at {$timeLabel}. See you soon!",
                        'is_read'    => false,
                        'created_at' => now(),
                    ]);
                    $sent3h++;
                }
            }
        }

        $this->info("Reminders sent — 24h: {$sent24h}, 3h: {$sent3h}");
    }
}
