<?php

use App\Models\ClinicSetting;
use App\Services\AvailabilityTimeWindowService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(AvailabilityTimeWindowService::class)->sync(ClinicSetting::current());
    }

    public function down(): void
    {
        // Generated windows may already be referenced by bookings or clinic
        // appointments, so a rollback intentionally preserves their records.
    }
};
