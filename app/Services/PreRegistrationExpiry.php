<?php

namespace App\Services;

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
            $count += DB::table($table)
                ->where('status', 'waiting_to_arrive')
                ->whereNull($checkIn)
                ->where($date, '<', now()->toDateString())
                ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
                ->update(['status' => 'expired']);
        }

        return $count;
    }
}
