<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->string('medicine_type', 40)->nullable();
            $table->string('dose_unit', 30)->nullable();
            $table->string('strength_concentration', 150)->nullable();
            $table->string('pricing_method', 20)->default('flat');
        });
        // Preserve existing prices; do not infer clinical dose units from stock units.
        DB::table('inventory_items')->where('category', 'medicine')
            ->update(['medicine_type' => 'general_medication']);

        Schema::create('inventory_item_weight_prices', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('item_id');
            $table->decimal('min_weight_kg', 8, 2);
            $table->decimal('max_weight_kg', 8, 2)->nullable();
            $table->decimal('price', 8, 2);
            $table->timestamps();
            $table->foreign('item_id')->references('item_id')->on('inventory_items')->cascadeOnDelete();
            $table->unique(['item_id', 'min_weight_kg']);
        });

        Schema::table('clinic_charges', function (Blueprint $table) {
            // Snapshots remain readable after prices or product information change.
            $table->decimal('pet_weight_kg', 8, 2)->nullable();
            $table->decimal('min_weight_kg', 8, 2)->nullable();
            $table->decimal('max_weight_kg', 8, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clinic_charges', fn (Blueprint $table) => $table->dropColumn([
            'pet_weight_kg', 'min_weight_kg', 'max_weight_kg',
        ]));
        Schema::dropIfExists('inventory_item_weight_prices');
        Schema::table('inventory_items', fn (Blueprint $table) => $table->dropColumn([
            'medicine_type', 'dose_unit', 'strength_concentration', 'pricing_method',
        ]));
    }
};
