<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            // Allow walk-in guest pets to exist without a registered user account
            $table->unsignedInteger('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->unsignedInteger('user_id')->nullable(false)->change();
        });
    }
};
