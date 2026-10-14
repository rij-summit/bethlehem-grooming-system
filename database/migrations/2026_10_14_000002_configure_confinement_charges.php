<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinic_services', function (Blueprint $table) {
            // Existing catalogue rows require explicit business configuration; names imply no behavior.
            $table->string('charge_behavior', 30)->nullable();
        });
        Schema::create('clinic_confinement_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_confinement_id')->constrained()->restrictOnDelete();
            $table->foreignId('clinic_case_service_id')->unique()->constrained()->restrictOnDelete();
            $table->string('charge_behavior', 30);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_confinement_charges');
        Schema::table('clinic_services', fn (Blueprint $table) => $table->dropColumn('charge_behavior'));
    }
};
