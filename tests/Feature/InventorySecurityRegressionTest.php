<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\InventoryStockMovementService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventorySecurityRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->string('phone')->unique();
            $table->string('password_hash');
            $table->string('role');
            $table->string('customer_tier')->default('new');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('email_verified_at')->nullable();
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->unsignedInteger('item_id')->autoIncrement();
            $table->string('item_name', 150);
            $table->string('barcode', 100)->nullable()->unique();
            $table->string('category');
            $table->string('unit', 50);
            $table->string('description', 255)->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('unit_cost', 8, 2)->default(0);
            $table->decimal('selling_price', 8, 2)->nullable();
            $table->decimal('quantity_on_hand', 10, 2)->default(0);
            $table->decimal('reorder_level', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->unsignedInteger('transaction_id')->autoIncrement();
            $table->unsignedInteger('item_id');
            $table->string('type');
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_cost_at_time', 8, 2)->nullable();
            $table->decimal('selling_price_at_time', 8, 2)->nullable();
            $table->string('reason');
            $table->string('batch_number', 100)->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('reference_type')->default('manual');
            $table->unsignedInteger('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('performed_by')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->unsignedInteger('pos_id')->autoIncrement();
            $table->unsignedInteger('cashier_id');
            $table->decimal('total_amount', 8, 2);
            $table->decimal('amount_tendered', 8, 2);
            $table->decimal('change_amount', 8, 2);
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('pos_id');
            $table->unsignedInteger('item_id');
            $table->decimal('quantity', 10, 2);
            $table->decimal('price_at_sale', 10, 2);
            $table->decimal('subtotal', 10, 2);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('pos_transaction_items');
        Schema::dropIfExists('pos_transactions');
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_every_inventory_and_pos_route_requires_staff_or_admin_role(): void
    {
        $expected = [
            ['GET', 'api/inventory/items'],
            ['POST', 'api/inventory/items'],
            ['GET', 'api/inventory/items/{id}'],
            ['PUT', 'api/inventory/items/{id}'],
            ['POST', 'api/inventory/items/{id}/deactivate'],
            ['POST', 'api/inventory/items/{id}/reactivate'],
            ['GET', 'api/inventory/search'],
            ['GET', 'api/inventory/barcode/{barcode}'],
            ['POST', 'api/inventory/stock-in'],
            ['POST', 'api/inventory/stock-out'],
            ['GET', 'api/inventory/transactions'],
            ['GET', 'api/inventory/low-stock'],
            ['GET', 'api/inventory/alerts/expiry'],
            ['GET', 'api/inventory/alerts/badge'],
            ['GET', 'api/inventory/summary'],
            ['POST', 'api/pos/transactions'],
            ['GET', 'api/pos/transactions'],
            ['GET', 'api/pos/transactions/{posId}'],
        ];

        $routes = collect(Route::getRoutes()->getRoutes());

        foreach ($expected as [$method, $uri]) {
            $route = $routes->first(
                fn (RoutingRoute $route) => $route->uri() === $uri
                    && in_array($method, $route->methods(), true),
            );

            $this->assertNotNull($route, "Expected protected route [{$method} {$uri}] is not registered.");
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
            $this->assertContains('role:admin,staff', $route->gatherMiddleware());
        }

        foreach ([
            'api/inventory/suppliers',
            'api/inventory/suppliers/{id}',
            'api/inventory/suppliers/{id}/deactivate',
        ] as $uri) {
            $this->assertNull(
                $routes->first(fn (RoutingRoute $route) => $route->uri() === $uri),
                "Supplier route [{$uri}] must not be registered.",
            );
        }
    }

    public function test_customer_is_forbidden_from_inventory_and_pos_endpoints(): void
    {
        Sanctum::actingAs($this->createUser('customer', '09170000001'));

        $this->getJson('/api/inventory/items')->assertForbidden();
        $this->postJson('/api/pos/transactions', [])->assertForbidden();
    }

    public function test_inventory_dashboard_summary_paginates_recent_transactions_ten_per_page(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000002'));

        $itemId = $this->createInventoryItem('Dashboard Pagination Product', 20, 100);

        foreach (range(1, 11) as $sequence) {
            $this->recordInventoryTransaction($itemId, 'stock_in', $sequence, [
                'created_at' => now()->subMinutes(11 - $sequence),
            ]);
        }

        $this->getJson('/api/inventory/summary?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'recent_transactions')
            ->assertJsonPath('recent_transactions.0.quantity', '1.00')
            ->assertJsonPath('recent_transactions_page', 2)
            ->assertJsonPath('recent_transactions_last_page', 2)
            ->assertJsonPath('recent_transactions_total', 11);
    }

    public function test_low_stock_alerts_paginate_ten_items_per_page(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000015'));

        foreach (range(1, 11) as $sequence) {
            $itemId = $this->createInventoryItem("Low Stock {$sequence}", 1, 2);
            DB::table('inventory_items')
                ->where('item_id', $itemId)
                ->update(['reorder_level' => 2]);
        }

        $this->getJson('/api/inventory/low-stock?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('page', 2)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('total', 11)
            ->assertJsonPath('count', 11);
    }

    public function test_expiry_alerts_paginate_the_calculated_records_ten_per_page(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000016'));

        foreach (range(1, 11) as $sequence) {
            $itemId = $this->createInventoryItem("Expiry Alert {$sequence}", 1, 0);
            $this->recordInventoryTransaction($itemId, 'stock_in', 1, [
                'batch_number' => "EXP-{$sequence}",
                'expiry_date' => now()->addDays($sequence)->toDateString(),
            ]);
        }

        $this->getJson('/api/inventory/alerts/expiry?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('page', 2)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('total', 11)
            ->assertJsonPath('count', 11);
    }

    public function test_transaction_history_supports_the_dashboard_ten_row_page_size(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000017'));

        $itemId = $this->createInventoryItem('Dashboard Transaction Page', 20, 0);
        foreach (range(1, 11) as $sequence) {
            $this->recordInventoryTransaction($itemId, 'stock_in', $sequence, [
                'created_at' => now()->subMinutes(11 - $sequence),
            ]);
        }

        $this->getJson('/api/inventory/transactions?page=2&per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.quantity', '1.00')
            ->assertJsonPath('page', 2)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('total', 11);
    }

    public function test_staff_can_process_a_pos_sale_under_the_inventory_role_contract(): void
    {
        Sanctum::actingAs($this->createUser('staff', '09170000014'));

        $itemId = $this->createInventoryItem('Staff POS Product', 1, 125.50);
        $this->recordInventoryTransaction($itemId, 'stock_in', 1, [
            'batch_number' => 'STAFF-POS-BATCH',
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $this->postJson('/api/pos/transactions', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 1,
            ]],
            'amount_tendered' => 200,
        ])->assertCreated()
            ->assertJsonPath('data.total_amount', '125.50')
            ->assertJsonPath('data.change_amount', '74.50');

        $this->assertDatabaseHas('inventory_items', [
            'item_id' => $itemId,
            'quantity_on_hand' => 0,
        ]);
        $this->assertDatabaseHas('inventory_transactions', [
            'item_id' => $itemId,
            'type' => 'stock_out',
            'reason' => 'sold',
            'reference_type' => 'pos',
        ]);
    }

    public function test_inventory_search_can_include_deactivated_products_when_requested(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000019'));

        $activeId = $this->createInventoryItem('Searchable Active Product', 0, 50);
        $inactiveId = $this->createInventoryItem('Searchable Deactivated Product', 0, 50);
        DB::table('inventory_items')->where('item_id', $inactiveId)->update(['is_active' => false]);

        $this->getJson('/api/inventory/search?q=Searchable')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.item_id', $activeId);

        $this->getJson('/api/inventory/search?q=Searchable&include_inactive=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['item_id' => $inactiveId, 'is_active' => false]);
    }

    public function test_product_has_no_misleading_expiry_and_stock_in_validates_batch_expiry(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000002'));

        $itemId = $this->postJson('/api/inventory/items', [
            'item_name' => 'Canine Vaccine',
            'category' => 'vaccine',
            'unit' => 'vial',
            'unit_cost' => 100,
            'selling_price' => 150,
            'reorder_level' => 2,
        ])->assertCreated()
            ->assertJsonMissingPath('data.expiry_date')
            ->json('data.item_id');

        $this->postJson('/api/inventory/stock-in', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 5,
                'reason' => 'purchase',
                'expiry_date' => now()->subDay()->toDateString(),
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.expiry_date']);

        $expiryDate = now()->addYear()->toDateString();

        $this->postJson('/api/inventory/stock-in', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 5,
                'reason' => 'purchase',
                'batch_number' => 'BATCH-001',
                'expiry_date' => $expiryDate,
            ]],
        ])->assertCreated();

        $this->assertDatabaseHas('inventory_items', [
            'item_id' => $itemId,
            'quantity_on_hand' => 5,
        ]);
        $this->assertTrue(
            DB::table('inventory_transactions')
                ->where('item_id', $itemId)
                ->where('type', 'stock_in')
                ->where('batch_number', 'BATCH-001')
                ->whereDate('expiry_date', $expiryDate)
                ->exists(),
        );

        $this->postJson('/api/inventory/stock-in', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 1.5,
                'reason' => 'purchase',
                'expiry_date' => now()->addYear()->toDateString(),
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.quantity']);
    }

    public function test_product_crud_requires_positive_prices_a_numeric_barcode_and_an_integer_minimum_stock(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000017'));

        $basePayload = [
            'item_name' => 'Validated Product',
            'category' => 'medicine',
            'unit' => 'piece',
        ];

        $this->postJson('/api/inventory/items', [
            ...$basePayload,
            'barcode' => 'ABC-123',
            'unit_cost' => 0,
            'selling_price' => 10.999,
            'reorder_level' => 1.5,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['barcode', 'unit_cost', 'selling_price', 'reorder_level']);

        $this->postJson('/api/inventory/items', $basePayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['unit_cost', 'selling_price', 'reorder_level']);

        $this->postJson('/api/inventory/items', [
            ...$basePayload,
            'barcode' => '0012345678901',
            'unit_cost' => 0,
            'selling_price' => 2.50,
            'reorder_level' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['unit_cost']);

        $this->postJson('/api/inventory/items', [
            ...$basePayload,
            'barcode' => '0012345678901',
            'unit_cost' => 1.25,
            'selling_price' => 0,
            'reorder_level' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['selling_price']);

        $this->postJson('/api/inventory/items', [
            ...$basePayload,
            'barcode' => '0012345678901',
            'unit_cost' => 1.25,
            'selling_price' => 2.50,
            'reorder_level' => 1.5,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['reorder_level']);

        $this->postJson('/api/inventory/items', [
            ...$basePayload,
            'barcode' => '0012345678901',
            'unit_cost' => 1.25,
            'selling_price' => 2.50,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['reorder_level']);

        $this->postJson('/api/inventory/items', [
            ...$basePayload,
            'barcode' => '00123456789012',
            'unit_cost' => 1.25,
            'selling_price' => 2.50,
            'reorder_level' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['barcode']);

        $itemId = $this->postJson('/api/inventory/items', [
            ...$basePayload,
            'barcode' => '0012345678901',
            'unit_cost' => 1.25,
            'selling_price' => 2.50,
            'reorder_level' => 0,
        ])->assertCreated()
            ->json('data.item_id');

        $this->assertDatabaseHas('inventory_items', [
            'item_id' => $itemId,
            'barcode' => '0012345678901',
        ]);

        $legacyItemId = $this->createInventoryItem('Legacy Price Product', 0, null);
        $this->putJson("/api/inventory/items/{$legacyItemId}", [
            ...$basePayload,
            'unit_cost' => 0,
            'selling_price' => null,
            'reorder_level' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['unit_cost', 'selling_price']);
    }

    public function test_product_list_returns_exactly_fifteen_items_per_page_and_the_filtered_total(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000018'));

        foreach (range(1, 16) as $number) {
            $this->createInventoryItem("Paged Product {$number}", 0, 10);
        }
        $this->createInventoryItem('Nonmatching Product', 0, 10);

        $this->getJson('/api/inventory/items?page=1')
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('total', 17)
            ->assertJsonPath('last_page', 2);

        $this->getJson('/api/inventory/items?q=Paged%20Product&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('total', 16)
            ->assertJsonPath('last_page', 2);
    }

    public function test_stock_movement_service_records_stock_in_with_a_batched_balance_projection(): void
    {
        $actor = $this->createUser('admin', '09170000015');
        $itemId = $this->createInventoryItem('Service Stock-In Product', 0, 75);

        $results = DB::transaction(fn () => app(InventoryStockMovementService::class)->receive(
            $actor,
            [[
                'item_id' => $itemId,
                'quantity' => 3,
                'reason' => 'purchase',
                'batch_number' => 'SERVICE-IN-BATCH',
                'expiry_date' => now()->addYear()->toDateString(),
            ]],
        ));

        $this->assertEquals(3.0, $results[0]['quantity_added']);
        $this->assertEquals(3.0, $results[0]['quantity_on_hand']);
        $this->assertEquals(3.0, $results[0]['unexpired_quantity']);
        $this->assertDatabaseHas('inventory_transactions', [
            'item_id' => $itemId,
            'type' => 'stock_in',
            'reason' => 'purchase',
            'batch_number' => 'SERVICE-IN-BATCH',
            'performed_by' => $actor->user_id,
        ]);
    }

    public function test_stock_movement_service_records_stock_out_and_preserves_the_ledger_reference(): void
    {
        $actor = $this->createUser('admin', '09170000016');
        $itemId = $this->createInventoryItem('Service Stock-Out Product', 3, 75);
        $this->recordInventoryTransaction($itemId, 'stock_in', 3, [
            'batch_number' => 'SERVICE-OUT-BATCH',
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $results = DB::transaction(fn () => app(InventoryStockMovementService::class)->remove(
            $actor,
            [[
                'item_id' => $itemId,
                'quantity' => 1,
                'reason' => 'used',
                'reference_type' => 'appointment',
                'reference_id' => 42,
            ]],
        ));

        $this->assertEquals(1.0, $results[0]['quantity_removed']);
        $this->assertEquals(2.0, $results[0]['quantity_on_hand']);
        $this->assertEquals(2.0, $results[0]['unexpired_quantity']);
        $this->assertDatabaseHas('inventory_transactions', [
            'item_id' => $itemId,
            'type' => 'stock_out',
            'reason' => 'used',
            'reference_type' => 'appointment',
            'reference_id' => 42,
            'performed_by' => $actor->user_id,
        ]);
    }

    public function test_inventory_pages_use_the_shared_api_base_and_fresh_script_versions(): void
    {
        $service = file_get_contents(base_path('scripts/services/inventory-service.js'));

        $this->assertStringContainsString('API.adminRequest(method, endpoint, body)', $service);
        $this->assertStringNotContainsString('127.0.0.1:8000', $service);

        foreach ([
            'inventory-dashboard.html',
            'inventory-items.html',
            'inventory-transactions.html',
            'pos.html',
            'stock-in.html',
            'stock-out.html',
        ] as $pageName) {
            $page = file_get_contents(base_path("pages/admin/inventory/{$pageName}"));

            $this->assertStringContainsString(
                'scripts/api.js?v=session-inactivity-20260828',
                $page,
                "{$pageName} must load the compatible shared API client.",
            );
            $expectedInventoryServiceVersion = match ($pageName) {
                'inventory-dashboard.html' => 'scripts/services/inventory-service.js?v=dashboard-pagination-20260919',
                'stock-in.html' => 'scripts/services/inventory-service.js?v=inventory-search-inactive-20260920',
                default => 'scripts/services/inventory-service.js?v=inventory-security-20260816',
            };
            $this->assertStringContainsString(
                $expectedInventoryServiceVersion,
                $page,
                "{$pageName} must invalidate the old localhost-only inventory client.",
            );
            $this->assertStringContainsString(
                'scripts/components/admin-sidebar.js?v=chatbot-safety-insights-20260830',
                $page,
                "{$pageName} must invalidate stale logout handling.",
            );
        }

        $inventoryDashboardPage = file_get_contents(base_path('pages/admin/inventory/inventory-dashboard.html'));
        $itemsPage = file_get_contents(base_path('pages/admin/inventory/inventory-items.html'));
        $posPage = file_get_contents(base_path('pages/admin/inventory/pos.html'));
        $stockInPage = file_get_contents(base_path('pages/admin/inventory/stock-in.html'));
        $stockOutPage = file_get_contents(base_path('pages/admin/inventory/stock-out.html'));
        $stockOutScript = file_get_contents(base_path('scripts/components/admin-stock-out.js'));

        $this->assertStringContainsString('scripts/api.js?v=session-inactivity-20260828', $inventoryDashboardPage);
        $this->assertStringContainsString('inventory-service.js?v=dashboard-pagination-20260919', $inventoryDashboardPage);
        $this->assertStringContainsString('admin-sidebar.js?v=chatbot-safety-insights-20260830', $inventoryDashboardPage);
        $this->assertStringContainsString('admin-inventory-dashboard.js?v=dashboard-pagination-20260919', $inventoryDashboardPage);
        $this->assertStringContainsString('admin-inventory-items.js?v=product-validation-pagination-20260918', $itemsPage);
        $this->assertStringContainsString('success-toast.js?v=success-toast-20260920', $stockInPage);
        $this->assertStringContainsString('inventory-service.js?v=inventory-search-inactive-20260920', $stockInPage);
        $this->assertStringContainsString('admin-stock-in.js?v=stock-in-deactivated-search-20260920', $stockInPage);
        $this->assertStringContainsString('admin-pos.js?v=fefo-expiry-20260816', $posPage);
        $this->assertStringContainsString('success-toast.js?v=success-toast-20260920', $stockOutPage);
        $this->assertStringContainsString('admin-stock-out.js?v=success-toast-20260920', $stockOutPage);
        $this->assertStringContainsString(
            'p.item_id === this.selected.item_id && p.reason === this.reason',
            $stockOutScript,
        );
        $this->assertStringContainsString('physicalQuantity - pendingQuantity', $stockOutScript);
        $this->assertStringNotContainsString('unexpired_quantity ?? 0', $stockOutScript);
        $this->assertStringNotContainsString('expired_quantity ?? 0', $stockOutScript);
    }

    public function test_exhausted_expired_batch_does_not_create_a_false_alert_after_restock(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000003'));

        $itemId = $this->createInventoryItem('FEFO Vaccine', 10, 150);
        $this->recordInventoryTransaction($itemId, 'stock_in', 5, [
            'batch_number' => 'OLD-EXPIRED',
            'expiry_date' => now()->subMonth()->toDateString(),
        ]);
        $this->recordInventoryTransaction($itemId, 'stock_out', 5, [
            'reason' => 'expired',
        ]);
        $this->recordInventoryTransaction($itemId, 'stock_in', 10, [
            'batch_number' => 'FRESH-BATCH',
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $this->getJson("/api/inventory/items/{$itemId}")
            ->assertOk()
            ->assertJsonPath('data.quantity_on_hand', '10.00')
            ->assertJsonPath('data.unexpired_quantity', 10)
            ->assertJsonPath('data.expired_quantity', 0);

        $this->getJson('/api/inventory/alerts/expiry')
            ->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/inventory/alerts/badge')
            ->assertOk()
            ->assertJsonPath('expiry_alert_count', 0);

        $this->getJson('/api/inventory/summary')
            ->assertOk()
            ->assertJsonPath('expiry_alert_count', 0);
    }

    public function test_manual_stock_out_uses_physical_stock_while_pos_rejects_expired_stock(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000004'));

        $itemId = $this->createInventoryItem('Expired Medicine', 5, 75);
        $this->recordInventoryTransaction($itemId, 'stock_in', 5, [
            'batch_number' => 'EXPIRED-ONLY',
            'expiry_date' => now()->subDay()->toDateString(),
        ]);

        $this->postJson('/api/inventory/stock-out', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 1,
                'reason' => 'used',
            ]],
        ])->assertCreated()
            ->assertJsonPath('data.0.quantity_on_hand', '4.00');

        $this->postJson('/api/pos/transactions', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 1,
                'price_at_sale' => 1,
            ]],
            'total_amount' => 1,
            'amount_tendered' => 100,
            'change_amount' => 99,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Insufficient unexpired stock for "Expired Medicine". Unexpired available: 0 piece; physical stock: 4.00 piece.');

        $this->assertDatabaseCount('pos_transactions', 0);
        $this->postJson('/api/inventory/stock-out', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 4,
                'reason' => 'expired',
            ]],
        ])->assertCreated()
            ->assertJsonPath('data.0.unexpired_quantity', 0);

        $this->assertDatabaseHas('inventory_items', [
            'item_id' => $itemId,
            'quantity_on_hand' => 0,
        ]);
        $this->assertDatabaseHas('inventory_transactions', [
            'item_id' => $itemId,
            'type' => 'stock_out',
            'reason' => 'expired',
            'quantity' => 4,
        ]);
    }

    public function test_pos_uses_server_prices_totals_and_change_even_when_client_values_are_tampered(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000005'));

        $itemId = $this->createInventoryItem('Priced Product', 3, 125.50);
        $this->recordInventoryTransaction($itemId, 'stock_in', 3, [
            'batch_number' => 'SALE-BATCH',
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $tamperedPayload = [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 2,
                'price_at_sale' => 1,
            ]],
            'total_amount' => 2,
            'amount_tendered' => 2,
            'change_amount' => 0,
        ];

        $this->postJson('/api/pos/transactions', $tamperedPayload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Amount tendered is less than the server-calculated total of ₱251.00.');
        $this->assertDatabaseCount('pos_transactions', 0);

        $tamperedPayload['amount_tendered'] = 300;
        $tamperedPayload['change_amount'] = 298;

        $response = $this->postJson('/api/pos/transactions', $tamperedPayload)
            ->assertCreated()
            ->assertJsonPath('data.total_amount', '251.00')
            ->assertJsonPath('data.amount_tendered', '300.00')
            ->assertJsonPath('data.change_amount', '49.00')
            ->assertJsonPath('data.items.0.price_at_sale', '125.50')
            ->assertJsonPath('data.items.0.subtotal', '251.00');

        $posId = $response->json('data.pos_id');
        $this->assertDatabaseHas('pos_transactions', [
            'pos_id' => $posId,
            'total_amount' => 251,
            'amount_tendered' => 300,
            'change_amount' => 49,
        ]);
        $this->assertDatabaseHas('pos_transaction_items', [
            'pos_id' => $posId,
            'item_id' => $itemId,
            'quantity' => 2,
            'price_at_sale' => 125.50,
            'subtotal' => 251,
        ]);
        $this->assertDatabaseHas('inventory_transactions', [
            'item_id' => $itemId,
            'type' => 'stock_out',
            'reason' => 'sold',
            'selling_price_at_time' => 125.50,
            'reference_type' => 'pos',
            'reference_id' => $posId,
        ]);
    }

    public function test_manual_stock_out_uses_remaining_physical_stock_after_fresh_stock_is_used(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000006'));

        $itemId = $this->createInventoryItem('Mixed Expiry Stock', 10, 40);
        $this->recordInventoryTransaction($itemId, 'stock_in', 5, [
            'batch_number' => 'EXPIRED-MIX',
            'expiry_date' => now()->subDay()->toDateString(),
        ]);
        $this->recordInventoryTransaction($itemId, 'stock_in', 5, [
            'batch_number' => 'FRESH-MIX',
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $this->postJson('/api/inventory/stock-out', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 5,
                'reason' => 'used',
            ]],
        ])->assertCreated()
            ->assertJsonPath('data.0.quantity_on_hand', '5.00')
            ->assertJsonPath('data.0.unexpired_quantity', 0);

        $this->postJson('/api/inventory/stock-out', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 1,
                'reason' => 'used',
            ]],
        ])->assertCreated()
            ->assertJsonPath('data.0.quantity_on_hand', '4.00');

        $this->getJson("/api/inventory/items/{$itemId}")
            ->assertOk()
            ->assertJsonPath('data.unexpired_quantity', 0)
            ->assertJsonPath('data.expired_quantity', 4);
    }

    public function test_pos_rejects_an_item_without_a_server_selling_price(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000007'));

        $itemId = $this->createInventoryItem('Unpriced Product', 1, null);
        $this->recordInventoryTransaction($itemId, 'stock_in', 1, [
            'batch_number' => 'UNPRICED-BATCH',
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $this->postJson('/api/pos/transactions', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 1,
                'price_at_sale' => 0,
            ]],
            'total_amount' => 0,
            'amount_tendered' => 100,
            'change_amount' => 100,
        ])->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'A selling price must be set for "Unpriced Product" before it can be sold.',
            );

        $this->assertDatabaseCount('pos_transactions', 0);
        $this->assertDatabaseHas('inventory_items', [
            'item_id' => $itemId,
            'quantity_on_hand' => 1,
        ]);
    }

    public function test_historical_unsafe_sale_is_reconciled_without_reviving_an_expired_alert(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000008'));

        $itemId = $this->createInventoryItem('Historical Sale Stock', 10, 90);
        $this->recordInventoryTransaction($itemId, 'stock_in', 5, [
            'batch_number' => 'HISTORICAL-EXPIRED',
            'expiry_date' => now()->subMonth()->toDateString(),
        ]);
        $this->recordInventoryTransaction($itemId, 'stock_out', 5, [
            'reason' => 'sold',
        ]);
        $this->recordInventoryTransaction($itemId, 'stock_in', 10, [
            'batch_number' => 'HISTORICAL-FRESH',
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $this->getJson("/api/inventory/items/{$itemId}")
            ->assertOk()
            ->assertJsonPath('data.unexpired_quantity', 10)
            ->assertJsonPath('data.expired_quantity', 0)
            ->assertJsonPath('data.historically_unsafe_quantity', 5);

        $this->getJson('/api/inventory/alerts/expiry')
            ->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_expiry_alert_reports_the_earliest_remaining_batch(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000011'));

        $itemId = $this->createInventoryItem('Out-of-order Expiry Stock', 5, 90);
        $this->recordInventoryTransaction($itemId, 'stock_in', 3, [
            'batch_number' => 'RECEIVED-FIRST',
            'expiry_date' => now()->addDays(20)->toDateString(),
        ]);
        $expiredDate = now()->subDay()->toDateString();
        $this->recordInventoryTransaction($itemId, 'stock_in', 2, [
            'batch_number' => 'RECEIVED-LATER-EXPIRED',
            'expiry_date' => $expiredDate,
        ]);

        $this->getJson('/api/inventory/alerts/expiry')
            ->assertOk()
            ->assertJsonPath('data.0.earliest_expiry', $expiredDate)
            ->assertJsonPath('data.0.is_expired', true)
            ->assertJsonPath('data.0.expiring_batches.0.batch_number', 'RECEIVED-LATER-EXPIRED');
    }

    public function test_manual_expired_writeoff_uses_physical_stock(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000009'));

        $itemId = $this->createInventoryItem('Writeoff Stock', 5, 25);
        $this->recordInventoryTransaction($itemId, 'stock_in', 2, [
            'batch_number' => 'WRITEOFF-EXPIRED',
            'expiry_date' => now()->subDay()->toDateString(),
        ]);
        $this->recordInventoryTransaction($itemId, 'stock_in', 3, [
            'batch_number' => 'WRITEOFF-FRESH',
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $this->postJson('/api/inventory/stock-out', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 3,
                'reason' => 'expired',
            ]],
        ])->assertCreated()
            ->assertJsonPath('data.0.quantity_on_hand', '2.00');

        $this->postJson('/api/inventory/stock-out', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 2,
                'reason' => 'damaged',
            ]],
        ])->assertCreated();

        $this->assertDatabaseHas('inventory_items', [
            'item_id' => $itemId,
            'quantity_on_hand' => 0,
        ]);
    }

    public function test_pos_rejects_a_server_calculated_total_that_exceeds_decimal_capacity(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000010'));

        $itemId = $this->createInventoryItem('Bulk Product', 10000, 125.50);
        $this->recordInventoryTransaction($itemId, 'stock_in', 10000, [
            'batch_number' => 'BULK-BATCH',
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $this->postJson('/api/pos/transactions', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 10000,
                'price_at_sale' => 1,
            ]],
            'total_amount' => 1,
            'amount_tendered' => 999999.99,
            'change_amount' => 999998.99,
        ])->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'The sale exceeds the maximum supported transaction amount of ₱999,999.99.',
            );

        $this->assertDatabaseCount('pos_transactions', 0);
    }

    public function test_stock_and_money_inputs_cannot_create_subcent_ledger_drift(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000012'));

        $itemId = $this->createInventoryItem('Precision-safe Product', 1, 25);
        $this->recordInventoryTransaction($itemId, 'stock_in', 1, [
            'batch_number' => 'PRECISION-BATCH',
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $this->postJson('/api/inventory/stock-in', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 0.015,
                'reason' => 'purchase',
                'unit_cost' => 1.005,
                'expiry_date' => now()->addYear()->toDateString(),
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.quantity', 'items.0.unit_cost']);

        $this->postJson('/api/inventory/stock-out', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 0.015,
                'reason' => 'used',
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.quantity']);

        $this->postJson('/api/pos/transactions', [
            'items' => [[
                'item_id' => $itemId,
                'quantity' => 0.015,
                'price_at_sale' => 25,
            ]],
            'amount_tendered' => 25.001,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.quantity', 'amount_tendered']);

        $this->assertDatabaseHas('inventory_items', [
            'item_id' => $itemId,
            'quantity_on_hand' => 1,
        ]);
        $this->assertDatabaseMissing('inventory_transactions', [
            'item_id' => $itemId,
            'type' => 'stock_out',
        ]);
        $this->assertDatabaseCount('pos_transactions', 0);
    }

    public function test_mixed_writeoffs_are_safe_regardless_of_client_row_order(): void
    {
        Sanctum::actingAs($this->createUser('admin', '09170000013'));

        $itemId = $this->createInventoryItem('Mixed Writeoff Order', 5, 25);
        $this->recordInventoryTransaction($itemId, 'stock_in', 2, [
            'batch_number' => 'ORDER-EXPIRED',
            'expiry_date' => now()->subDay()->toDateString(),
        ]);
        $this->recordInventoryTransaction($itemId, 'stock_in', 3, [
            'batch_number' => 'ORDER-FRESH',
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $this->postJson('/api/inventory/stock-out', [
            'items' => [
                [
                    'item_id' => $itemId,
                    'quantity' => 3,
                    'reason' => 'damaged',
                ],
                [
                    'item_id' => $itemId,
                    'quantity' => 2,
                    'reason' => 'expired',
                ],
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('inventory_items', [
            'item_id' => $itemId,
            'quantity_on_hand' => 0,
        ]);
        $this->assertDatabaseHas('inventory_transactions', [
            'item_id' => $itemId,
            'type' => 'stock_out',
            'reason' => 'expired',
            'quantity' => 2,
        ]);
        $this->assertDatabaseHas('inventory_transactions', [
            'item_id' => $itemId,
            'type' => 'stock_out',
            'reason' => 'damaged',
            'quantity' => 3,
        ]);
    }

    private function createUser(string $role, string $phone): User
    {
        return User::query()->create([
            'first_name' => ucfirst($role),
            'last_name' => 'Inventory Tester',
            'email' => "{$role}.inventory@example.test",
            'phone' => $phone,
            'password_hash' => bcrypt('password'),
            'role' => $role,
            'customer_tier' => 'new',
            'is_active' => true,
            'is_archived' => false,
            'email_verified_at' => now(),
        ]);
    }

    private function createInventoryItem(string $name, float $quantity, ?float $sellingPrice): int
    {
        return (int) DB::table('inventory_items')->insertGetId([
            'item_name' => $name,
            'category' => 'medicine',
            'unit' => 'piece',
            'unit_cost' => 50,
            'selling_price' => $sellingPrice,
            'quantity_on_hand' => $quantity,
            'reorder_level' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'item_id');
    }

    private function recordInventoryTransaction(
        int $itemId,
        string $type,
        float $quantity,
        array $overrides = [],
    ): int {
        return (int) DB::table('inventory_transactions')->insertGetId([
            'item_id' => $itemId,
            'type' => $type,
            'quantity' => $quantity,
            'reason' => $type === 'stock_in' ? 'purchase' : 'adjustment',
            'reference_type' => 'manual',
            'created_at' => now(),
            ...$overrides,
        ], 'transaction_id');
    }
}
