<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_payments', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->morphs('payable');
            $table->string('context', 30);
            $table->string('flow', 30)->nullable();
            $table->string('reference')->unique();
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3)->default('PHP');
            $table->string('status', 20)->default('pending');
            $table->boolean('livemode');
            $table->string('checkout_session_id')->nullable()->unique();
            $table->string('provider_payment_id')->nullable()->unique();
            $table->string('provider_event_id')->nullable()->unique();
            $table->text('checkout_url')->nullable();
            $table->string('provider_method', 50)->nullable();
            $table->json('snapshot');
            $table->unsignedInteger('initiated_by')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('reconciliation_reason')->nullable();
            $table->timestamps();
            $table->index(['context', 'status']);
        });
        Schema::table('payments', function (Blueprint $table) {
            // Keep the existing legacy method values available for old records.
            $table->string('payment_method', 30)->default('cash')->change();
            $table->string('payment_provider', 30)->nullable();
            $table->string('provider_method', 50)->nullable();
            $table->unsignedBigInteger('gateway_payment_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['payment_provider', 'provider_method', 'gateway_payment_id']);
        });
        Schema::dropIfExists('gateway_payments');
    }
};
