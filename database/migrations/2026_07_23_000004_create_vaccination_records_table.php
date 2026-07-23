<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vaccination_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('pet_id');
            $table->unsignedBigInteger('clinic_appointment_id')->nullable();
            $table->unsignedInteger('inventory_item_id')->nullable();

            $table->string('vaccine_name', 150);
            $table->string('product_name', 150)->nullable();
            $table->string('manufacturer', 150)->nullable();
            $table->string('batch_number', 100)->nullable();
            $table->date('administered_date');
            $table->date('next_due_date')->nullable();
            $table->date('product_expiry_date')->nullable();
            $table->decimal('dose_amount', 8, 3)->nullable();
            $table->string('dose_unit', 30)->nullable();
            $table->string('route', 50)->nullable();
            $table->string('administration_site', 100)->nullable();

            $table->unsignedInteger('administered_by_user_id')->nullable();
            $table->string('administered_by_name', 200)->nullable();
            $table->unsignedInteger('recorded_by_user_id')->nullable();
            $table->text('notes')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('published_by_user_id')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->unsignedInteger('voided_by_user_id')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();

            $table->foreign('pet_id')
                ->references('pet_id')
                ->on('pets')
                ->restrictOnDelete();
            $table->foreign('clinic_appointment_id')
                ->references('id')
                ->on('clinic_appointments')
                ->nullOnDelete();
            $table->foreign('inventory_item_id')
                ->references('item_id')
                ->on('inventory_items')
                ->nullOnDelete();
            $table->foreign('administered_by_user_id')
                ->references('user_id')
                ->on('users')
                ->nullOnDelete();
            $table->foreign('recorded_by_user_id')
                ->references('user_id')
                ->on('users')
                ->nullOnDelete();
            $table->foreign('published_by_user_id')
                ->references('user_id')
                ->on('users')
                ->nullOnDelete();
            $table->foreign('voided_by_user_id')
                ->references('user_id')
                ->on('users')
                ->nullOnDelete();

            $table->index(['pet_id', 'administered_date'], 'vaccination_pet_date_idx');
            $table->index(['pet_id', 'published_at'], 'vaccination_client_visibility_idx');
            $table->index('next_due_date', 'vaccination_next_due_idx');
            $table->index(
                ['pet_id', 'vaccine_name', 'administered_date'],
                'vaccination_duplicate_lookup_idx',
            );
            $table->index('clinic_appointment_id', 'vaccination_appointment_idx');
            $table->index('inventory_item_id', 'vaccination_inventory_item_idx');
            $table->index('administered_by_user_id', 'vaccination_administered_by_idx');
            $table->index('recorded_by_user_id', 'vaccination_recorded_by_idx');
            $table->index('published_by_user_id', 'vaccination_published_by_idx');
            $table->index('voided_by_user_id', 'vaccination_voided_by_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vaccination_records');
    }
};
