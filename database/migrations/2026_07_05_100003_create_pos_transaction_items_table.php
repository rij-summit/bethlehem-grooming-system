<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_transaction_items', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('pos_id');
            $table->unsignedInteger('item_id');
            $table->decimal('quantity', 10, 2);
            $table->decimal('price_at_sale', 8, 2);
            $table->decimal('subtotal', 8, 2);

            $table->foreign('pos_id')->references('pos_id')->on('pos_transactions')->cascadeOnDelete();
            $table->foreign('item_id')->references('item_id')->on('inventory_items');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_transaction_items');
    }
};
