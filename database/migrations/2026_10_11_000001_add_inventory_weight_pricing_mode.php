<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing inclusive prices remain unchanged until explicitly refined in Inventory.
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->string('weight_pricing_mode', 20)->default('legacy');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', fn (Blueprint $table) => $table->dropColumn('weight_pricing_mode'));
    }
};
