<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('clinic_appointments', function (Blueprint $table) {
            $table->id();
            $table->string('appointment_reference', 30)->unique();
            $table->enum('appointment_type', ['walk_in', 'pre_registered'])->default('walk_in');
            $table->enum('status', [
                'waiting_to_arrive', 'checked_in', 'in_consultation',
                'for_payment', 'completed', 'cancelled', 'no_show',
            ])->default('checked_in');
            $table->unsignedSmallInteger('queue_number')->nullable();
            $table->date('appointment_date');

            $table->unsignedInteger('user_id')->nullable();
            $table->foreign('user_id')->references('user_id')->on('users')->onDelete('set null');
            $table->unsignedBigInteger('walkin_id')->nullable();
            $table->foreign('walkin_id')->references('id')->on('walkins')->onDelete('set null');
            $table->unsignedInteger('pet_id')->nullable();
            $table->foreign('pet_id')->references('pet_id')->on('pets')->onDelete('set null');

            $table->text('chief_complaint')->nullable();
            $table->decimal('total_amount', 10, 2)->nullable();
            $table->boolean('paid')->default(false);
            $table->text('notes')->nullable();

            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('consultation_started_at')->nullable();
            $table->timestamp('consultation_finished_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clinic_appointments');
    }
};
