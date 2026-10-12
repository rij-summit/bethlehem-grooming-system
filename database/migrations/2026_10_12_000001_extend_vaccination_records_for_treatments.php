<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vaccination_records', function (Blueprint $table) {
            $table->string('treatment_type', 20)->default('vaccine');
            $table->decimal('inventory_quantity', 10, 2)->nullable();
            $table->decimal('expected_price', 8, 2)->nullable();
            $table->decimal('expected_weight_kg', 8, 2)->nullable();
            $table->uuid('request_key')->nullable()->unique();
            $table->string('strength_concentration', 150)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vaccination_records', function (Blueprint $table) {
            $table->dropUnique(['request_key']);
            $table->dropColumn(['treatment_type', 'inventory_quantity', 'expected_price', 'expected_weight_kg', 'request_key', 'strength_concentration']);
        });
    }
};
