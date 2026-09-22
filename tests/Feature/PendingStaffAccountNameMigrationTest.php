<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PendingStaffAccountNameMigrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('pending_staff_accounts');

        parent::tearDown();
    }

    public function test_migration_keeps_existing_pending_staff_accounts_compatible(): void
    {
        Schema::create('pending_staff_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('staff_type', 20);
        });

        $migration = require database_path(
            'migrations/2026_09_22_000000_add_names_to_pending_staff_accounts_table.php',
        );

        $migration->up();

        $this->assertTrue(Schema::hasColumn('pending_staff_accounts', 'first_name'));
        $this->assertTrue(Schema::hasColumn('pending_staff_accounts', 'last_name'));

        $migration->down();

        $this->assertFalse(Schema::hasColumn('pending_staff_accounts', 'first_name'));
        $this->assertFalse(Schema::hasColumn('pending_staff_accounts', 'last_name'));
    }
}
