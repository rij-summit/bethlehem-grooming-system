<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StaffSubroleMigrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('pending_staff_accounts');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_migration_adds_and_removes_nullable_staff_subroles(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id('user_id');
            $table->string('staff_type', 20)->nullable();
        });
        Schema::create('pending_staff_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('staff_type', 20);
        });

        $migration = require database_path(
            'migrations/2026_09_22_000001_add_staff_subrole_to_staff_accounts.php',
        );

        $migration->up();

        $this->assertTrue(Schema::hasColumn('users', 'staff_subrole'));
        $this->assertTrue(Schema::hasColumn('pending_staff_accounts', 'staff_subrole'));

        $migration->down();

        $this->assertFalse(Schema::hasColumn('users', 'staff_subrole'));
        $this->assertFalse(Schema::hasColumn('pending_staff_accounts', 'staff_subrole'));
    }
}
