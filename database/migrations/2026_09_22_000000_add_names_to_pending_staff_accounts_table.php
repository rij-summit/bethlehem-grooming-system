<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_staff_accounts', function (Blueprint $table): void {
            $table->string('first_name', 100)->nullable()->after('staff_type');
            $table->string('last_name', 100)->nullable()->after('first_name');
        });
    }

    public function down(): void
    {
        Schema::table('pending_staff_accounts', function (Blueprint $table): void {
            $table->dropColumn(['first_name', 'last_name']);
        });
    }
};
