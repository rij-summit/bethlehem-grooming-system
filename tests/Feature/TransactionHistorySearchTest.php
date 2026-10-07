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
            $table->string('item_name')->nullable();
            $table->decimal('subtotal');
        });
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->increments('item_id');
            $table->string('item_name');
            $table->string('unit');
        });
        (require database_path('migrations/2026_07_05_100002_create_pos_transactions_table.php'))->up();
        (require database_path('migrations/2026_07_05_100003_create_pos_transaction_items_table.php'))->up();
        (require database_path('migrations/2026_10_07_100000_add_product_snapshot_to_pos_items.php'))->up();

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

    public function test_unified_history_filters_totals_search_and_pos_snapshots(): void
    {
        DB::table('inventory_items')->insert(['item_id' => 1, 'item_name' => 'Current Name', 'unit' => 'bottle']);
        DB::table('pos_transactions')->insert([
            'pos_id' => 1, 'cashier_id' => 1, 'total_amount' => 500, 'amount_tendered' => 1000,
            'change_amount' => 500, 'created_at' => '2026-10-03 13:00:00',
        ]);
        DB::table('pos_transaction_items')->insert([
            'pos_id' => 1, 'item_id' => 1, 'item_name' => 'Recorded Shampoo', 'unit' => 'bottle',
            'quantity' => 2, 'price_at_sale' => 250, 'subtotal' => 500,
        ]);
        $all = $this->search([]);
        $this->assertSame(3, $all['total']);
        $this->assertEquals(2200, $all['collection_total']);
        $this->assertEquals(1350, $all['date_totals']['2026-10-03']);
        $this->assertSame(['pos-1', 'grooming-1', 'grooming-2'], array_column($all['transactions'], 'key'));
        $grooming = $this->search(['transaction_type' => 'grooming_payment']);
        $this->assertSame(2, $grooming['total']);
        $this->assertEquals(1700, $grooming['collection_total']);
        $sales = $this->search(['transaction_type' => 'product_sale']);
        $this->assertSame(1, $sales['total']);
        $this->assertEquals(500, $sales['collection_total']);
        $sale = $sales['transactions'][0];
        $this->assertSame('Product Sale', $sale['transactionTypeLabel']);
        $this->assertSame('POS-000001', $sale['reference']);
        $this->assertSame('Gerald Senining', $sale['processedByName']);
        $this->assertSame('Recorded Shampoo', $sale['items'][0]['item_name']);
        $this->assertSame('250.00', $sale['items'][0]['price_at_sale']);
        $this->assertArrayNotHasKey('ownerName', $sale);
        $this->assertArrayNotHasKey('petName', $sale);
        $this->assertEquals(1000, $sale['amountPaid']);
        $this->assertEquals(500, $sale['changeGiven']);
        foreach (['pOs-000001', 'rEcOrDeD sHaMpOo', 'Shampoo'] as $term) {
            $this->assertSame(1, $this->search(['search' => $term])['total']);
        }
        $this->assertSame(0, $this->search(['search' => 'Current Name'])['total'], 'Search uses the recorded product snapshot');
        foreach ([['period' => 'day', 'date' => '2026-10-03'], ['period' => 'month', 'month' => '2026-10'], ['period' => 'week', 'week' => '2026-W40'], ['period' => 'year', 'year' => '2026']] as $filter) {
            $this->assertSame(1, $this->search(['transaction_type' => 'product_sale', ...$filter])['total']);
        }
        $this->assertSame(0, $this->search(['transaction_type' => 'product_sale', 'date' => '2026-10-04'])['total']);
    }

    public function test_collection_and_daily_totals_cover_the_full_filtered_history_across_pages(): void
    {
        foreach (range(1, 55) as $id) {
            DB::table('pos_transactions')->insert([
                'pos_id' => $id, 'cashier_id' => 1, 'total_amount' => 10, 'amount_tendered' => 20,
                'change_amount' => 10, 'created_at' => '2026-10-03 13:00:00',
            ]);
        }
        $first = $this->search(['transaction_type' => 'product_sale']);
        $second = $this->search(['transaction_type' => 'product_sale', 'page' => 2]);
        $this->assertCount(50, $first['transactions']);
        $this->assertCount(5, $second['transactions']);
        foreach ([$first, $second] as $page) {
            $this->assertSame(55, $page['total']);
            $this->assertEquals(550, $page['collection_total']);
            $this->assertEquals(550, $page['date_totals']['2026-10-03']);
            $this->assertSame(2, $page['last_page']);
        }
        $this->assertEmpty(array_intersect(array_column($first['transactions'], 'key'), array_column($second['transactions'], 'key')));
    }

    public function test_snapshot_migration_backfills_existing_names_without_changing_recorded_prices(): void
    {
        Schema::table('pos_transaction_items', fn (Blueprint $table) => $table->dropColumn(['item_name', 'unit']));
        DB::table('inventory_items')->insert(['item_id' => 1, 'item_name' => 'Legacy Shampoo', 'unit' => 'bottle']);
        DB::table('pos_transactions')->insert(['pos_id' => 1, 'cashier_id' => 1, 'total_amount' => 100,
            'amount_tendered' => 200, 'change_amount' => 100, 'created_at' => '2026-10-03 13:00:00']);
        DB::table('pos_transaction_items')->insert(['pos_id' => 1, 'item_id' => 1, 'quantity' => 2,
            'price_at_sale' => 50, 'subtotal' => 100]);
        (require database_path('migrations/2026_10_07_100000_add_product_snapshot_to_pos_items.php'))->up();
        DB::table('inventory_items')->where('item_id', 1)->update(['item_name' => 'Renamed Shampoo']);
        $line = $this->search(['transaction_type' => 'product_sale'])['transactions'][0]['items'][0];
        $this->assertSame('Legacy Shampoo', $line['item_name']);
        $this->assertSame('bottle', $line['unit']);
        $this->assertSame('50.00', $line['price_at_sale']);
        $this->assertSame('100.00', $line['subtotal']);
    }

    private function search(array $query): array
    {
        return (new PaymentController)->index(Request::create('/api/admin/transactions', 'GET', $query))->getData(true);
    }
}
