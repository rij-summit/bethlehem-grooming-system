<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinic_settings', function (Blueprint $table) {
            $table->time('clinic_open_time')->default('08:00:00')->after('groomers_on_duty');
            $table->time('clinic_close_time')->default('17:00:00')->after('clinic_open_time');
            $table->time('clinic_prereg_cutoff_time')->default('14:00:00')->after('clinic_close_time');
            $table->time('grooming_open_time')->default('08:00:00')->after('clinic_prereg_cutoff_time');
            $table->time('grooming_close_time')->default('17:00:00')->after('grooming_open_time');
            $table->time('grooming_prereg_cutoff_time')->default('14:00:00')->after('grooming_close_time');
        });
    }

    public function down(): void
    {
        Schema::table('clinic_settings', function (Blueprint $table) {
            $table->dropColumn([
                'clinic_open_time',
                'clinic_close_time',
                'clinic_prereg_cutoff_time',
                'grooming_open_time',
                'grooming_close_time',
                'grooming_prereg_cutoff_time',
            ]);
        });
    }
};
