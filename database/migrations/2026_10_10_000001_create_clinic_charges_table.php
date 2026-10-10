<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_appointment_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('description', 200);
            $table->unsignedInteger('inventory_item_id')->nullable();
            $table->unsignedBigInteger('vaccination_record_id')->nullable()->unique();
            $table->uuid('request_key')->nullable()->unique();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('amount', 10, 2);
            $table->timestamps();
        });
        Schema::table('clinic_appointments', function (Blueprint $table) {
            $table->string('payment_method', 20)->nullable();
            $table->unsignedInteger('paid_by_user_id')->nullable();
            $table->timestamp('paid_at')->nullable();
        });
        // Preserve any amount already recorded on a visit awaiting payment.
        DB::table('clinic_charges')->insertUsing([
            'clinic_appointment_id', 'kind', 'description', 'quantity', 'unit_price', 'amount', 'created_at', 'updated_at',
        ], DB::table('clinic_appointments')->where('status', 'for_payment')->where('total_amount', '>', 0)
            ->select('id')->selectRaw("'existing', 'Existing clinic charges', 1, total_amount, total_amount, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP"));
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_charges');
        Schema::table('clinic_appointments', fn (Blueprint $table) => $table->dropColumn([
            'payment_method', 'paid_by_user_id', 'paid_at',
        ]));
    }
};
