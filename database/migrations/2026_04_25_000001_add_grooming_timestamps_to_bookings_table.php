<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('dropped_off_at')->nullable()->after('archived_at');
            $table->timestamp('grooming_started_at')->nullable()->after('dropped_off_at');
            $table->timestamp('grooming_finished_at')->nullable()->after('grooming_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['dropped_off_at', 'grooming_started_at', 'grooming_finished_at']);
        });
    }
};
