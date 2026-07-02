<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Allow walk-in bookings that have no registered user account
            $table->unsignedInteger('user_id')->nullable()->change();

            // Link to the walk-in guest record (null for online bookings)
            $table->unsignedBigInteger('walkin_id')->nullable()->after('user_id');
            $table->foreign('walkin_id')->references('id')->on('walkins')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['walkin_id']);
            $table->dropColumn('walkin_id');
            $table->unsignedInteger('user_id')->nullable(false)->change();
        });
    }
};
