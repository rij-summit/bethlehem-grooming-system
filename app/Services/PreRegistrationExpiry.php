<?php

namespace App\Services;

use App\Models\CustomerNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PreRegistrationExpiry
{
    public function expire(?int $userId = null): int
    {
        $count = 0;
        foreach ([
            ['bookings', 'booking_date', 'dropped_off_at'],
            ['clinic_appointments', 'appointment_date', 'checked_in_at'],
        ] as [$table, $date, $checkIn]) {
            if (! Schema::hasColumns($table, [$date, $checkIn])) {
                continue;
            }
            $unused = fn () => DB::table($table)
                ->where('status', 'waiting_to_arrive')
                ->whereNull($checkIn)
                ->where($date, '<', now()->toDateString())
                ->when($userId !== null, fn ($query) => $query->where('user_id', $userId));

            if ($table !== 'bookings') {
                $count += $unused()->update(['status' => 'expired']);
                continue;
            }

            // Lock and notify in the same transaction so overlapping expiry/read
            // requests cannot notify twice or race a reschedule/check-in.
            do {
                $expired = DB::transaction(function () use ($unused): int {
                    $bookings = $unused()->orderBy('booking_id')->limit(200)->lockForUpdate()->get();
                    if ($bookings->isEmpty()) return 0;
                    DB::table('bookings')->whereIn('booking_id', $bookings->pluck('booking_id'))->update(['status' => 'expired']);

                    $notifications = $bookings->whereNotNull('user_id')->map(function ($booking) {
                        $day = Carbon::parse($booking->booking_date);
                        $label = $day->format($day->year === now()->year ? 'M j' : 'M j, Y');
                        return ['user_id' => $booking->user_id, 'booking_id' => $booking->booking_id,
                            'type' => CustomerNotification::TYPE_GROOMING_PRE_REGISTRATION_EXPIRED,
                            'message' => "Your grooming pre-registration for {$label} was cancelled because the scheduled date passed without check-in.",
                            'is_read' => false, 'created_at' => now()];
                    })->all();
                    if ($notifications !== []) CustomerNotification::insert($notifications);

                    return $bookings->count();
                }, 5);
                $count += $expired;
            } while ($expired === 200);
        }

        return $count;
    }
}
