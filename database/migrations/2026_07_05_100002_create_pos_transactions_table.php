<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->unsignedInteger('pos_id')->autoIncrement();
            $table->unsignedInteger('cashier_id');
            $table->decimal('total_amount', 8, 2);
            $table->decimal('amount_tendered', 8, 2);
            $table->decimal('change_amount', 8, 2);
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('cashier_id')->references('user_id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_transactions');
    }
};
