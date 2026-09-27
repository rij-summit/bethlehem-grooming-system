<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_services', function (Blueprint $table) {
            $table->decimal('price_min_at_booking', 8, 2)->nullable();
            $table->decimal('price_max_at_booking', 8, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('booking_services', function (Blueprint $table) {
            $table->dropColumn(['price_min_at_booking', 'price_max_at_booking']);
        });
    }
};
