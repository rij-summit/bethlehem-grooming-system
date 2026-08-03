<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicateRecordsExist = DB::table('clinic_records')
            ->select('clinic_appointment_id')
            ->groupBy('clinic_appointment_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        $duplicateVitalsExist = DB::table('clinic_vitals')
            ->select('clinic_appointment_id')
            ->groupBy('clinic_appointment_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicateRecordsExist || $duplicateVitalsExist) {
            throw new RuntimeException(
                'Clinic record or vitals duplicates must be resolved explicitly before uniqueness can be enforced.',
            );
        }

        Schema::table('clinic_records', function (Blueprint $table) {
            $table->unique('clinic_appointment_id', 'cr_appt_uq');
        });
        Schema::table('clinic_vitals', function (Blueprint $table) {
            $table->unique('clinic_appointment_id', 'cv_appt_uq');
        });
    }

    public function down(): void
    {
        Schema::table('clinic_vitals', function (Blueprint $table) {
            $table->dropUnique('cv_appt_uq');
        });
        Schema::table('clinic_records', function (Blueprint $table) {
            $table->dropUnique('cr_appt_uq');
        });
    }
};
