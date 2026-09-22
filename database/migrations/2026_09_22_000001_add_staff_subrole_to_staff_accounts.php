<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('staff_subrole', 30)->nullable()->after('staff_type');
        });

        Schema::table('pending_staff_accounts', function (Blueprint $table): void {
            $table->string('staff_subrole', 30)->nullable()->after('staff_type');
        });
    }

    public function down(): void
    {
        Schema::table('pending_staff_accounts', function (Blueprint $table): void {
            $table->dropColumn('staff_subrole');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('staff_subrole');
        });
    }
};
