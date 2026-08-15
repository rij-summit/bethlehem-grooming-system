<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grooming_medical_concerns', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id');
            $table->uuid('report_token')->nullable();

            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('booking_pet_id');
            $table->unsignedInteger('pet_id');

            $table->unsignedInteger('reported_by_user_id')->nullable();
            $table->string('reported_by_name', 200);
            $table->timestamp('reported_at');

            $table->string('category', 50);
            $table->string('severity', 20);
            $table->text('internal_description');
            $table->text('customer_message');
            $table->string('recommended_grooming_action', 40);
            $table->string('applied_grooming_action', 40)->nullable();
            $table->timestamp('action_applied_at')->nullable();
            $table->unsignedInteger('action_applied_by_user_id')->nullable();

            $table->string('status', 30)->default('open');
            $table->boolean('acknowledgment_required')->default(false);
            $table->boolean('consent_required')->default(false);
            $table->string('customer_response_status', 30)->default('not_required');
            $table->timestamp('customer_notified_at')->nullable();

            $table->unsignedBigInteger('clinic_appointment_id')->nullable();

            $table->text('customer_resolution_summary')->nullable();
            $table->text('internal_resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('resolved_by_user_id')->nullable();
            $table->string('resolved_by_name', 200)->nullable();
            $table->timestamps();

            $table->unique('public_id', 'gmc_public_id_uq');
            $table->unique('report_token', 'gmc_report_token_uq');
            $table->index(
                ['booking_pet_id', 'status'],
                'gmc_booking_pet_status_idx',
            );
            $table->index(
                ['booking_id', 'reported_at'],
                'gmc_booking_reported_idx',
            );
            $table->index(
                ['pet_id', 'reported_at'],
                'gmc_pet_reported_idx',
            );
            $table->index(['pet_id', 'status'], 'gmc_pet_status_idx');
            $table->index('clinic_appointment_id', 'gmc_clinic_appt_idx');
            $table->index(
                ['customer_response_status', 'status'],
                'gmc_response_status_idx',
            );

            $table->foreign(
                ['booking_pet_id', 'booking_id', 'pet_id'],
                'gmc_booking_pet_fk',
            )
                ->references(['booking_pet_id', 'booking_id', 'pet_id'])
                ->on('booking_pets')
                ->restrictOnUpdate()
                ->restrictOnDelete();
            $table->foreign('reported_by_user_id', 'gmc_reported_by_fk')
                ->references('user_id')
                ->on('users')
                ->nullOnDelete();
            $table->foreign('action_applied_by_user_id', 'gmc_action_by_fk')
                ->references('user_id')
                ->on('users')
                ->nullOnDelete();
            $table->foreign('resolved_by_user_id', 'gmc_resolved_by_fk')
                ->references('user_id')
                ->on('users')
                ->nullOnDelete();
            $table->foreign('clinic_appointment_id', 'gmc_clinic_appt_fk')
                ->references('id')
                ->on('clinic_appointments')
                ->restrictOnDelete();
        });

        Schema::create('grooming_medical_concern_responses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('concern_id');
            $table->unsignedInteger('responded_by_user_id')->nullable();
            $table->string('responded_by_name', 200);
            $table->string('response_kind', 30);
            $table->string('decision', 30);
            $table->text('statement_text');
            $table->string('statement_version', 50);
            $table->string('signature_name', 200)->nullable();
            $table->timestamp('responded_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(
                ['concern_id', 'responded_at'],
                'gmcr_concern_responded_idx',
            );
            $table->unique(
                ['concern_id', 'response_kind'],
                'gmcr_concern_kind_uq',
            );

            $table->foreign('concern_id', 'gmcr_concern_fk')
                ->references('id')
                ->on('grooming_medical_concerns')
                ->restrictOnDelete();
            $table->foreign('responded_by_user_id', 'gmcr_responded_by_fk')
                ->references('user_id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grooming_medical_concern_responses');
        Schema::dropIfExists('grooming_medical_concerns');
    }
};
