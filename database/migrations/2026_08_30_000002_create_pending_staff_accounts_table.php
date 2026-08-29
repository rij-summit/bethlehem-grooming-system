<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_staff_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('requested_by_user_id');
            $table->string('staff_type', 20);
            $table->string('email', 150)->unique();
            $table->string('password_hash');
            $table->string('code_hash');
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->dateTime('expires_at')->index();
            $table->dateTime('last_sent_at');
            $table->timestamps();

            $table->foreign('requested_by_user_id')
                ->references('user_id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_staff_accounts');
    }
};
