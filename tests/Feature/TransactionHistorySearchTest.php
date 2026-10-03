<?php

namespace Tests\Feature;

use App\Http\Controllers\PaymentController;
use App\Services\GroomingPaymentReadinessService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TransactionHistorySearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('first_name');
            $table->string('last_name');
        });
        Schema::create('bookings', function (Blueprint $table) {
            $table->increments('booking_id');
            $table->unsignedInteger('user_id');
            $table->string('booking_reference');
        });
        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id');
            $table->string('pet_name');
        });
        Schema::create('booking_pets', function (Blueprint $table) {
            $table->increments('booking_pet_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('pet_id');
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->increments('payment_id');
            $table->unsignedInteger('booking_id');
            $table->decimal('total_amount');
            $table->decimal('amount_tendered');
            $table->decimal('change_amount');
            $table->string('payment_method');
            $table->string('payment_status');
            $table->dateTime('paid_at');
        });
        Schema::create('grooming_payment_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('payment_id');
            $table->decimal('subtotal');
        });
        DB::table('users')->insert(['user_id' => 1, 'first_name' => 'Gerald', 'last_name' => 'Senining']);
        DB::table('pets')->insert(['pet_id' => 1, 'pet_name' => 'Hopper']);
        foreach ([1 => '2026-10-03', 2 => '2026-10-02', 3 => '2026-10-03'] as $id => $date) {
            DB::table('bookings')->insert(['booking_id' => $id, 'user_id' => 1, 'booking_reference' => 'BAC-'.$id]);
            DB::table('booking_pets')->insert(['booking_id' => $id, 'pet_id' => 1]);
            DB::table('payments')->insert([
                'payment_id' => $id, 'booking_id' => $id, 'total_amount' => 850,
                'amount_tendered' => 1000, 'change_amount' => 150, 'payment_method' => 'cash',
                'payment_status' => $id === 3 ? 'pending' : 'paid', 'paid_at' => $date.' 12:00:00',
            ]);
        }
        $this->mock(GroomingPaymentReadinessService::class)
            ->shouldReceive('summarize')->andReturn(['pets' => []]);
        // Prove the query works even when LIKE itself is case sensitive.
        DB::statement('PRAGMA case_sensitive_like = ON');
    }

    public function test_owner_pet_and_reference_search_ignore_case_and_preserve_filters(): void
    {
        foreach (['gErAlD', 'SENINING', 'GeRaLd SeNiNiNg', 'hOpPeR', 'bAc-1'] as $search) {
            $data = $this->search(['search' => $search, 'period' => 'day', 'date' => '2026-10-03']);
            $this->assertSame(1, $data['total'], $search);
            $this->assertSame('BAC-1', $data['transactions'][0]['reference']);
            $this->assertSame(850, $data['transactions'][0]['finalPrice']);
            $this->assertSame(1000, $data['transactions'][0]['amountPaid']);
            $this->assertSame(150, $data['transactions'][0]['changeGiven']);
        }
        $this->assertSame(0, $this->search(['search' => 'BAC-3'])['total'], 'Pending payments stay out of history');
        $this->assertSame(0, $this->search(['search' => 'missing'])['total']);
        $this->assertSame(0, $this->search(['search' => 'BAC-2', 'date' => '2026-10-03'])['total']);
        $this->assertSame(2, $this->search(['search' => 'HOPPER', 'period' => 'month', 'month' => '2026-10'])['total']);
        $this->assertSame(2, $this->search(['search' => 'HOPPER', 'period' => 'year', 'year' => '2026'])['total']);
        $this->assertSame(2, $this->search([])['total'], 'Unfiltered history is preserved');
    }

    private function search(array $query): array
    {
        return (new PaymentController)->index(Request::create('/api/admin/transactions', 'GET', $query))->getData(true);
    }
}
