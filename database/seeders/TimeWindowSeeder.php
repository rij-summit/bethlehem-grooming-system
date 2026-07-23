<?php

namespace Database\Seeders;

use App\Models\ClinicSetting;
use App\Services\AvailabilityTimeWindowService;
use Illuminate\Database\Seeder;

class TimeWindowSeeder extends Seeder
{
    public function run(): void
    {
        app(AvailabilityTimeWindowService::class)->sync(ClinicSetting::current());
    }
}
