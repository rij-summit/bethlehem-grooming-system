<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_confinements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_appointment_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('clinic_case_service_id')->unique()->constrained()->restrictOnDelete();
            $table->timestamp('admitted_at');
            $table->timestamp('discharged_at')->nullable()->index();
            $table->text('reason');
            $table->text('notes')->nullable();
            $table->text('discharge_note')->nullable();
            $table->unsignedInteger('attending_veterinarian_id')->nullable();
            $table->foreign('attending_veterinarian_id')->references('user_id')->on('users')->nullOnDelete();
            $table->string('attending_veterinarian_name', 200)->nullable();
            foreach (['admitted_by_user_id', 'discharged_by_user_id'] as $column) {
                $table->unsignedInteger($column)->nullable();
                $table->foreign($column)->references('user_id')->on('users')->restrictOnDelete();
            }
            $table->timestamps();
        });
        Schema::create('clinic_confinement_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_confinement_id')->constrained()->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->timestamp('recorded_at');
            $table->decimal('temperature_c', 4, 1)->nullable();
            $table->unsignedSmallInteger('heart_rate_bpm')->nullable();
            $table->unsignedSmallInteger('respiratory_rate_bpm')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('recorded_by_user_id');
            $table->foreign('recorded_by_user_id')->references('user_id')->on('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['clinic_confinement_id', 'recorded_at'], 'clinic_confinement_monitoring_timeline_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_confinement_observations');
        Schema::dropIfExists('clinic_confinements');
    }
};
