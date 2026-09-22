<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinic_appointments', function (Blueprint $table) {
            $table->string('case_type', 30)->nullable()->after('appointment_type');
            $table->index(['status', 'appointment_date'], 'clinic_active_cases_status_date_idx');
            $table->index(['pet_id', 'status'], 'clinic_active_cases_pet_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('clinic_appointments', function (Blueprint $table) {
            $table->dropIndex('clinic_active_cases_status_date_idx');
            $table->dropIndex('clinic_active_cases_pet_status_idx');
            $table->dropColumn('case_type');
        });
    }
};
