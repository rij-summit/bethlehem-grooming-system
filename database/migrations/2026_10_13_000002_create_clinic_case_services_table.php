<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_case_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_appointment_id')->constrained()->restrictOnDelete();
            $table->foreignId('clinic_service_id')->constrained()->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->enum('status', ['ordered', 'performed', 'cancelled']);
            $table->decimal('quantity', 10, 2)->default(1);
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('performed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            foreach (['recorded_by_user_id', 'performed_by_user_id', 'cancelled_by_user_id'] as $column) {
                $table->unsignedInteger($column)->nullable();
                $table->foreign($column)->references('user_id')->on('users')->restrictOnDelete();
            }
            $table->decimal('applied_price', 10, 2)->nullable();
            // Historical billing metadata only; configuration stays in the catalog.
            $table->json('service_snapshot')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['clinic_appointment_id', 'status']);
        });
        Schema::table('clinic_charges', function (Blueprint $table) {
            $table->foreignId('clinic_case_service_id')->nullable()->unique()
                ->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clinic_charges', function (Blueprint $table) {
            $table->dropForeign(['clinic_case_service_id']);
            $table->dropUnique(['clinic_case_service_id']);
            $table->dropColumn('clinic_case_service_id');
        });
        Schema::dropIfExists('clinic_case_services');
    }
};
