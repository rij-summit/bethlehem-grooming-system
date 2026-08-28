<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('privileged_credential_changes')) {
            return;
        }

        Schema::table('privileged_credential_changes', function (Blueprint $table): void {
            if (! Schema::hasColumn('privileged_credential_changes', 'code_hash')) {
                $table->string('code_hash')->nullable();
            }
            if (! Schema::hasColumn('privileged_credential_changes', 'failed_attempts')) {
                $table->unsignedTinyInteger('failed_attempts')->default(0);
            }
            if (! Schema::hasColumn('privileged_credential_changes', 'last_sent_at')) {
                $table->timestamp('last_sent_at')->nullable();
            }
        });

        if (Schema::hasColumn('privileged_credential_changes', 'token_hash')) {
            Schema::table('privileged_credential_changes', function (Blueprint $table): void {
                $table->dropUnique('privileged_credential_changes_token_hash_unique');
                $table->dropColumn('token_hash');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('privileged_credential_changes')) {
            return;
        }

        if (! Schema::hasColumn('privileged_credential_changes', 'token_hash')) {
            Schema::table('privileged_credential_changes', function (Blueprint $table): void {
                $table->string('token_hash', 64)->nullable()->unique();
            });
        }

        Schema::table('privileged_credential_changes', function (Blueprint $table): void {
            if (Schema::hasColumn('privileged_credential_changes', 'code_hash')) {
                $table->dropColumn('code_hash');
            }
            if (Schema::hasColumn('privileged_credential_changes', 'failed_attempts')) {
                $table->dropColumn('failed_attempts');
            }
            if (Schema::hasColumn('privileged_credential_changes', 'last_sent_at')) {
                $table->dropColumn('last_sent_at');
            }
        });
    }
};
