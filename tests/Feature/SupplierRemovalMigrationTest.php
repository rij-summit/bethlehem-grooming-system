<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SupplierRemovalMigrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('suppliers');

        parent::tearDown();
    }

    public function test_supplier_removal_migration_drops_the_transaction_link_before_the_supplier_table(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->unsignedInteger('supplier_id')->autoIncrement();
        });
        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->unsignedInteger('transaction_id')->autoIncrement();
            $table->unsignedInteger('supplier_id')->nullable();
            $table->foreign('supplier_id')
                ->references('supplier_id')->on('suppliers')
                ->nullOnDelete();
        });

        $migration = require base_path(
            'database/migrations/2026_09_19_000000_remove_supplier_from_inventory.php',
        );
        $migration->up();

        $this->assertFalse(Schema::hasColumn('inventory_transactions', 'supplier_id'));
        $this->assertFalse(Schema::hasTable('suppliers'));
    }
}
