<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_email_challenges', function (Blueprint $table): void {
            $table->string('poll_token_hash', 64)
                ->nullable()
                ->unique()
                ->after('token_hash');
            $table->timestamp('approved_at')
                ->nullable()
                ->index()
                ->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('login_email_challenges', function (Blueprint $table): void {
            $table->dropUnique('login_email_challenges_poll_token_hash_unique');
            $table->dropIndex('login_email_challenges_approved_at_index');
            $table->dropColumn(['poll_token_hash', 'approved_at']);
        });
    }
};
