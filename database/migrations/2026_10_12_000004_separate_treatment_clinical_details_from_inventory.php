<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', fn (Blueprint $table) => $table->dropColumn('dose_unit'));
        Schema::table('vaccination_records', function (Blueprint $table) {
            $table->dropForeign(['administered_by_user_id']);
            $table->dropIndex('vaccination_administered_by_idx');
            $table->dropColumn(['administered_by_user_id', 'administered_by_name']);
            // Incomplete drafts may be saved; publication validates these facts.
            $table->string('vaccine_name', 150)->nullable()->change();
            $table->date('administered_date')->nullable()->change();
        });
        // Keep clinical doses, legacy stock quantities, and units exactly as stored.
    }

    public function down(): void
    {
        Schema::table('inventory_items', fn (Blueprint $table) => $table->string('dose_unit', 30)->nullable());
        Schema::table('vaccination_records', function (Blueprint $table) {
            $table->unsignedInteger('administered_by_user_id')->nullable();
            $table->string('administered_by_name', 200)->nullable();
            $table->foreign('administered_by_user_id')->references('user_id')->on('users')->nullOnDelete();
            $table->index('administered_by_user_id', 'vaccination_administered_by_idx');
        });
    }
};
