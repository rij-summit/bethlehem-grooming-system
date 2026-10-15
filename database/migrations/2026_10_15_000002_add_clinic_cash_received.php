<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinic_appointments', function (Blueprint $table) {
            $table->decimal('cash_received', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clinic_appointments', fn (Blueprint $table) => $table->dropColumn('cash_received'));
    }
};
