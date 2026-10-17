<?php

use App\Models\ClinicSetting;
use App\Services\AvailabilityTimeWindowService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinic_settings', function (Blueprint $table) {
            $table->dropColumn(['clinic_prereg_cutoff_time', 'grooming_prereg_cutoff_time']);
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->time('arrival_end_time')->nullable();
        });
        app(AvailabilityTimeWindowService::class)->sync(ClinicSetting::query()->findOrFail(ClinicSetting::SINGLETON_ID));
    }

    public function down(): void
    {
        Schema::table('clinic_settings', function (Blueprint $table) {
            $table->time('clinic_prereg_cutoff_time')->default('14:00:00');
            $table->time('grooming_prereg_cutoff_time')->default('14:00:00');
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('arrival_end_time');
        });
    }
};
