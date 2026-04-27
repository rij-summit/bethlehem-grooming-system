<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TimeWindowSeeder extends Seeder
{
    public function run(): void
    {
        $windows = [
            ['window_label' => '1', 'start_time' => '08:00:00', 'end_time' => '09:00:00'],
            ['window_label' => '2', 'start_time' => '09:00:00', 'end_time' => '10:00:00'],
            ['window_label' => '3', 'start_time' => '10:00:00', 'end_time' => '11:00:00'],
            ['window_label' => '4', 'start_time' => '11:00:00', 'end_time' => '12:00:00'],
            ['window_label' => '5', 'start_time' => '12:00:00', 'end_time' => '13:00:00'],
            ['window_label' => '6', 'start_time' => '13:00:00', 'end_time' => '14:00:00'],
            ['window_label' => '7', 'start_time' => '14:00:00', 'end_time' => '15:00:00'],
        ];

        foreach ($windows as $window) {
            DB::table('time_windows')->insertOrIgnore([
                'window_label' => $window['window_label'],
                'start_time'   => $window['start_time'],
                'end_time'     => $window['end_time'],
                'max_slots'    => 4,
                'is_active'    => true,
            ]);
        }
    }
}
