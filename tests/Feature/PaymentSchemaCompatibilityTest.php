<?php

namespace Tests\Feature;

use App\Models\Payment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaymentSchemaCompatibilityTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('payments');
        parent::tearDown();
    }

    public function test_payment_model_supports_fresh_install_id_without_optional_processed_by(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('booking_id');
            $table->decimal('total_amount', 10, 2);
            $table->decimal('amount_tendered', 10, 2)->nullable();
            $table->decimal('change_amount', 10, 2)->nullable();
            $table->string('payment_method');
            $table->string('payment_status');
            $table->text('notes')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();
        });

        $payment = Payment::create([
            'booking_id' => 99,
            'total_amount' => '0.00',
            'amount_tendered' => '0.00',
            'change_amount' => '0.00',
            'payment_method' => 'others',
            'payment_status' => 'paid',
            'notes' => 'Zero-total completion: no payment required.',
            'paid_at' => now(),
        ]);

        $this->assertSame('id', $payment->getKeyName());
        $this->assertSame(1, $payment->getKey());
        $this->assertSame('0.00', $payment->total_amount);
        $this->assertSame(1, Payment::query()->whereKey(1)->count());
        $this->assertHydratedKeyReadsDoNotHitSchema('id');
    }

    public function test_payment_model_supports_active_payment_id_and_processed_by(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->increments('payment_id');
            $table->unsignedInteger('booking_id');
            $table->decimal('total_amount', 8, 2);
            $table->decimal('amount_tendered', 8, 2)->nullable();
            $table->decimal('change_amount', 8, 2)->nullable();
            $table->string('payment_method');
            $table->string('payment_status');
            $table->text('notes')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->unsignedInteger('processed_by')->nullable();
            $table->dateTime('created_at')->nullable();
        });

        $payment = Payment::create([
            'booking_id' => 100,
            'total_amount' => '125.50',
            'amount_tendered' => '150.00',
            'change_amount' => '24.50',
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'processed_by' => 7,
            'paid_at' => now(),
        ]);

        $this->assertSame('payment_id', $payment->getKeyName());
        $this->assertSame(1, $payment->getKey());
        $this->assertSame(7, $payment->processed_by);
        $this->assertHydratedKeyReadsDoNotHitSchema('payment_id');
    }

    private function assertHydratedKeyReadsDoNotHitSchema(string $expectedKey): void
    {
        $payment = Payment::query()->firstOrFail();
        $queryCount = 0;

        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        for ($iteration = 0; $iteration < 25; $iteration++) {
            $this->assertSame($expectedKey, $payment->getKeyName());
            $this->assertSame(1, $payment->getKey());
        }

        $this->assertSame(0, $queryCount, 'Reading a hydrated payment key must not inspect the schema.');
    }
}
