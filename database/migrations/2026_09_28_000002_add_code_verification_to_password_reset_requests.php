<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('password_reset_requests', function (Blueprint $table): void {
            $table->string('verified_token_hash', 64)->nullable()->unique();
            $table->unsignedTinyInteger('verification_attempts')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('password_reset_requests', function (Blueprint $table): void {
            $table->dropUnique(['verified_token_hash']);
            $table->dropColumn(['verified_token_hash', 'verification_attempts']);
        });
    }
};
