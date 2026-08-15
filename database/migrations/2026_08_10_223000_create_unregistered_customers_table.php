<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unregistered_customers', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('middle_name', 5)->nullable();
            $table->string('phone', 20);
            $table->string('email', 150)->nullable();
            $table->unsignedInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->index('phone');
            $table->index('email');
            $table->foreign('created_by_user_id')
                ->references('user_id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unregistered_customers');
    }
};
