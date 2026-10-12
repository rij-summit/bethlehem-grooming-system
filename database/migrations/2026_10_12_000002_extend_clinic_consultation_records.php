<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinic_records', function (Blueprint $table) {
            foreach (['relevant_history', 'reported_signs', 'physical_exam_findings', 'differential_diagnoses', 'working_diagnosis'] as $field) {
                $table->text($field)->nullable();
            }
            $table->unsignedInteger('primary_veterinarian_id')->nullable();
            $table->foreign('primary_veterinarian_id')->references('user_id')->on('users')->nullOnDelete();
            $table->string('primary_veterinarian_name')->nullable();
            $table->unsignedInteger('recorded_by_user_id')->nullable();
            $table->foreign('recorded_by_user_id')->references('user_id')->on('users')->nullOnDelete();
            $table->string('recorded_by_name')->nullable();
            $table->json('additional_veterinarians')->nullable();
            $table->json('diagnostics')->nullable();
            $table->json('prescriptions')->nullable();
        });
        DB::table('clinic_records')->update([
            'physical_exam_findings' => DB::raw('findings'),
            'working_diagnosis' => DB::raw('diagnosis'),
        ]);
    }

    public function down(): void
    {
        Schema::table('clinic_records', function (Blueprint $table) {
            $table->dropForeign(['primary_veterinarian_id']);
            $table->dropForeign(['recorded_by_user_id']);
            $table->dropColumn(['relevant_history', 'reported_signs', 'physical_exam_findings',
                'differential_diagnoses', 'working_diagnosis', 'primary_veterinarian_id',
                'primary_veterinarian_name', 'recorded_by_user_id', 'recorded_by_name',
                'additional_veterinarians', 'diagnostics', 'prescriptions']);
        });
    }
};
