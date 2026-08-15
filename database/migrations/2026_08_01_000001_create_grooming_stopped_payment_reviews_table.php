<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grooming_medical_concerns', function (Blueprint $table) {
            $table->unique(
                ['id', 'booking_id', 'booking_pet_id', 'pet_id'],
                'gmc_review_context_uq',
            );
        });

        Schema::create('grooming_stopped_payment_reviews', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('booking_pet_id');
            $table->unsignedInteger('pet_id');
            $table->unsignedBigInteger('grooming_medical_concern_id');

            $table->string('decision', 20);
            $table->decimal('original_pet_subtotal', 8, 2);
            $table->decimal('final_pet_charge', 8, 2);

            $table->text('internal_reason');
            $table->text('customer_explanation');

            $table->unsignedInteger('reviewed_by_user_id')->nullable();
            $table->string('reviewed_by_name', 200);
            $table->timestamp('reviewed_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();

            $table->unique('booking_pet_id', 'gspr_booking_pet_uq');
            $table->unique(
                'grooming_medical_concern_id',
                'gspr_concern_uq',
            );
            $table->index(
                ['booking_id', 'reviewed_at'],
                'gspr_booking_reviewed_idx',
            );
            $table->index(
                ['pet_id', 'reviewed_at'],
                'gspr_pet_reviewed_idx',
            );
            $table->index(
                ['reviewed_by_user_id', 'reviewed_at'],
                'gspr_reviewer_reviewed_idx',
            );
            $table->index('decision', 'gspr_decision_idx');

            $table->foreign('booking_id', 'gspr_booking_fk')
                ->references('booking_id')
                ->on('bookings')
                ->restrictOnUpdate()
                ->restrictOnDelete();
            $table->foreign('pet_id', 'gspr_pet_fk')
                ->references('pet_id')
                ->on('pets')
                ->restrictOnUpdate()
                ->restrictOnDelete();
            $table->foreign(
                ['booking_pet_id', 'booking_id', 'pet_id'],
                'gspr_booking_pet_ctx_fk',
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
                'gspr_concern_ctx_fk',
            )
                ->references(['id', 'booking_id', 'booking_pet_id', 'pet_id'])
                ->on('grooming_medical_concerns')
                ->restrictOnUpdate()
                ->restrictOnDelete();
            $table->foreign('reviewed_by_user_id', 'gspr_reviewed_by_fk')
                ->references('user_id')
                ->on('users')
                ->restrictOnUpdate()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grooming_stopped_payment_reviews');

        Schema::table('grooming_medical_concerns', function (Blueprint $table) {
            $table->dropUnique('gmc_review_context_uq');
        });
    }
};
