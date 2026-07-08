<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->unsignedInteger('transaction_id')->autoIncrement();
            $table->unsignedInteger('item_id');
            $table->enum('type', ['stock_in', 'stock_out']);
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_cost_at_time', 8, 2)->nullable();
            $table->decimal('selling_price_at_time', 8, 2)->nullable();
            $table->enum('reason', [
                // stock_in reasons
                'purchase',
                'return',
                // stock_out reasons
                'used',
                'sold',
                'expired',
                'damaged',
                // valid for both
                'adjustment',
            ]);
            $table->unsignedInteger('supplier_id')->nullable();
            $table->string('batch_number', 100)->nullable();
            $table->date('expiry_date')->nullable();
            $table->enum('reference_type', ['manual', 'appointment', 'pos'])->default('manual');
            $table->unsignedInteger('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('performed_by')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('item_id')
                  ->references('item_id')->on('inventory_items')
                  ->cascadeOnDelete();

            $table->foreign('supplier_id')
                  ->references('supplier_id')->on('suppliers')
                  ->nullOnDelete();

            $table->foreign('performed_by')
                  ->references('user_id')->on('users')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transactions');
    }
};
