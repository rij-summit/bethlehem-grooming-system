<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->string('reference_type', 30)->default('manual')->change();
        });

        Schema::create('grooming_payment_products', function (Blueprint $table) {
            $table->id();
            // Existing installations use payment_id; fresh installs use id.
            $table->unsignedBigInteger('payment_id')->index();
            $table->unsignedInteger('item_id');
            $table->string('item_name', 150);
            $table->unsignedInteger('quantity');
            $table->decimal('price_at_sale', 8, 2);
            $table->decimal('subtotal', 10, 2);
            $table->foreign('item_id')->references('item_id')->on('inventory_items');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grooming_payment_products');
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->enum('reference_type', ['manual', 'appointment', 'pos'])->default('manual')->change();
        });
    }
};
