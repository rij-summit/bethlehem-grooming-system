<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinic_appointments', function (Blueprint $table) {
            $table->unsignedInteger('window_id')->nullable()->after('appointment_date');
            $table->foreign('window_id')
                ->references('window_id')
                ->on('time_windows')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clinic_appointments', function (Blueprint $table) {
            $table->dropForeign(['window_id']);
            $table->dropColumn('window_id');
        });
    }
};
