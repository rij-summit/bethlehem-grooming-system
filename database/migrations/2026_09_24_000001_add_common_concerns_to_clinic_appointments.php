<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinic_appointments', function (Blueprint $table) {
            $table->json('common_concerns')->nullable()->after('chief_complaint');
        });
    }

    public function down(): void
    {
        Schema::table('clinic_appointments', function (Blueprint $table) {
            $table->dropColumn('common_concerns');
        });
    }
};
