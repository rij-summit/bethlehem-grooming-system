<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            // Legacy ledger entries retain their existing references and snapshots.
            $table->foreignId('vaccination_record_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropForeign(['vaccination_record_id']);
            $table->dropUnique(['vaccination_record_id']);
            $table->dropColumn('vaccination_record_id');
        });
    }
};
