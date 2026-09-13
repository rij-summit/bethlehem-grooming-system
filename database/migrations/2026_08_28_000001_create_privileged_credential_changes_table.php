<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privileged_credential_changes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('requested_by_user_id');
            $table->unsignedInteger('target_user_id');
            $table->string('target_role', 20);
            $table->string('new_username', 50)->nullable();
            $table->string('new_password_hash')->nullable();
            $table->boolean('changes_password')->default(false);
            $table->string('code_hash')->nullable();
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('confirmed_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('requested_by_user_id', 'credential_changes_requester_fk')
                ->references('user_id')
                ->on('users')
                ->cascadeOnDelete();
            $table->foreign('target_user_id', 'credential_changes_target_fk')
                ->references('user_id')
                ->on('users')
                ->cascadeOnDelete();
            $table->index(
                ['target_user_id', 'confirmed_at'],
                'credential_changes_target_pending_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privileged_credential_changes');
    }
};
