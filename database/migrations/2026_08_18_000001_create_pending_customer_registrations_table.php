<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_customer_registrations', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('username', 50)->nullable()->unique();
            $table->string('phone', 20)->unique();
            $table->string('email', 150)->unique();
            $table->string('password_hash');
            $table->string('email_verification_token', 64)->nullable()->unique();
            $table->timestamp('email_verification_expires_at')
                ->nullable()
                ->index('pending_registration_expiry_idx');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_customer_registrations');
    }
};
