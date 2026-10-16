<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grooming_consents', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('booking_id')->unique();
            $table->foreign('booking_id')->references('booking_id')->on('bookings')->cascadeOnDelete();
            $table->boolean('grooming_consent');
            $table->boolean('sedation_consent');
            $table->string('signer_name', 200);
            $table->longText('signature_image');
            $table->json('signature_strokes');
            $table->timestamp('signed_at');
            $table->string('consent_version', 40);
            $table->json('agreement_snapshot');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grooming_consents');
    }
};
