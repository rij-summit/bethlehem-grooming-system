<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grooming_clinic_referrals', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id');
            $table->uuid('request_token')->nullable();

            $table->unsignedBigInteger('grooming_medical_concern_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('booking_pet_id');
            $table->unsignedInteger('pet_id');
            $table->unsignedBigInteger('clinic_appointment_id')->nullable();

            $table->string('status', 30);
            $table->string('urgency', 20);
            $table->text('referral_reason');
            $table->text('customer_explanation');

            $table->boolean('consent_required')->default(true);
            $table->unsignedBigInteger('consent_response_id')->nullable();

            $table->unsignedInteger('owner_user_id_at_referral')->nullable();
            $table->string('owner_name_at_referral', 200);

            $table->unsignedInteger('referred_by_user_id')->nullable();
            $table->string('referred_by_name', 200);
            $table->timestamp('referred_at')->useCurrent();

            $table->timestamp('customer_notified_at')->nullable();
            $table->text('emergency_without_consent_reason')->nullable();

            $table->unsignedInteger('accepted_by_user_id')->nullable();
            $table->string('accepted_by_name', 200)->nullable();
            $table->timestamp('accepted_at')->nullable();

            $table->unsignedInteger('clinic_review_started_by_user_id')->nullable();
            $table->string('clinic_review_started_by_name', 200)->nullable();
            $table->timestamp('clinic_review_started_at')->nullable();

            $table->string('grooming_clearance_status', 30)->default('pending');

            $table->unsignedInteger('cancelled_by_user_id')->nullable();
            $table->string('cancelled_by_name', 200)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('customer_cancellation_summary')->nullable();

            $table->unsignedInteger('resolved_by_user_id')->nullable();
            $table->string('resolved_by_name', 200)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('internal_resolution_notes')->nullable();
            $table->text('customer_resolution_summary')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->unique('public_id', 'gcr_public_uq');
            $table->unique('request_token', 'gcr_token_uq');
            $table->unique('grooming_medical_concern_id', 'gcr_concern_uq');
            $table->unique('clinic_appointment_id', 'gcr_appt_uq');
            $table->unique('consent_response_id', 'gcr_consent_uq');

            $table->index(['booking_id', 'status'], 'gcr_booking_status_idx');
            $table->index(['booking_pet_id', 'status'], 'gcr_bp_status_idx');
            $table->index(['pet_id', 'referred_at'], 'gcr_pet_referred_idx');
            $table->index(
                ['status', 'urgency', 'referred_at'],
                'gcr_status_urgency_idx',
            );
            $table->index(
                ['referred_by_user_id', 'referred_at'],
                'gcr_referrer_idx',
            );
            $table->index(
                ['owner_user_id_at_referral', 'referred_at'],
                'gcr_owner_idx',
            );
            $table->index('grooming_clearance_status', 'gcr_clearance_idx');
            $table->index(
                ['booking_pet_id', 'booking_id', 'pet_id'],
                'gcr_bp_context_idx',
            );
            $table->index(
                [
                    'grooming_medical_concern_id',
                    'booking_id',
                    'booking_pet_id',
                    'pet_id',
                ],
                'gcr_concern_context_idx',
            );
            $table->index(
                ['consent_response_id', 'grooming_medical_concern_id'],
                'gcr_consent_context_idx',
            );

            $table->foreign('booking_id', 'gcr_booking_fk')
                ->references('booking_id')
                ->on('bookings')
                ->restrictOnUpdate()
                ->restrictOnDelete();
            $table->foreign('pet_id', 'gcr_pet_fk')
                ->references('pet_id')
                ->on('pets')
                ->restrictOnUpdate()
                ->restrictOnDelete();
            $table->foreign(
                ['booking_pet_id', 'booking_id', 'pet_id'],
                'gcr_booking_pet_fk',
            )
                ->references(['booking_pet_id', 'booking_id', 'pet_id'])
                ->on('booking_pets')
                ->restrictOnUpdate()
                ->restrictOnDelete();
            $table->foreign(
                [
                    'grooming_medical_concern_id',
                    'booking_id',
                    'booking_pet_id',
                    'pet_id',
                ],
                'gcr_concern_context_fk',
            )
                ->references(['id', 'booking_id', 'booking_pet_id', 'pet_id'])
                ->on('grooming_medical_concerns')
                ->restrictOnUpdate()
                ->restrictOnDelete();
            $table->foreign(
                ['consent_response_id', 'grooming_medical_concern_id'],
                'gcr_consent_context_fk',
            )
                ->references(['id', 'concern_id'])
                ->on('grooming_medical_concern_responses')
                ->restrictOnUpdate()
                ->restrictOnDelete();
            $table->foreign('clinic_appointment_id', 'gcr_appt_fk')
                ->references('id')
                ->on('clinic_appointments')
                ->restrictOnUpdate()
                ->restrictOnDelete();

            $table->foreign('owner_user_id_at_referral', 'gcr_owner_fk')
                ->references('user_id')
                ->on('users')
                ->restrictOnUpdate()
                ->nullOnDelete();
            $table->foreign('referred_by_user_id', 'gcr_referred_by_fk')
                ->references('user_id')
                ->on('users')
                ->restrictOnUpdate()
                ->nullOnDelete();
            $table->foreign('accepted_by_user_id', 'gcr_accepted_by_fk')
                ->references('user_id')
                ->on('users')
                ->restrictOnUpdate()
                ->nullOnDelete();
            $table->foreign(
                'clinic_review_started_by_user_id',
                'gcr_review_started_by_fk',
            )
                ->references('user_id')
                ->on('users')
                ->restrictOnUpdate()
                ->nullOnDelete();
            $table->foreign('cancelled_by_user_id', 'gcr_cancelled_by_fk')
                ->references('user_id')
                ->on('users')
                ->restrictOnUpdate()
                ->nullOnDelete();
            $table->foreign('resolved_by_user_id', 'gcr_resolved_by_fk')
                ->references('user_id')
                ->on('users')
                ->restrictOnUpdate()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grooming_clinic_referrals');
    }
};
