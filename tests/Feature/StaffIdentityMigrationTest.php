<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StaffIdentityMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->increments('user_id');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('username', 50)->nullable()->unique();
            $table->string('email', 150)->unique();
            $table->string('phone', 20)->unique();
            $table->string('password_hash');
            $table->string('role');
            $table->timestamp('email_verified_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_migration_replaces_the_legacy_staff_identity_on_the_same_record(): void
    {
        $passwordHash = Hash::make('ExistingStaff!234');
        $staffId = DB::table('users')->insertGetId([
            'first_name' => 'Staff',
            'last_name' => 'Bethlehem',
            'username' => null,
            'email' => 'staff@bethlehem.com',
            'phone' => '09170003001',
            'password_hash' => $passwordHash,
            'role' => 'staff',
            'email_verified_at' => null,
        ], 'user_id');

        $migration = require database_path(
            'migrations/2026_08_30_000001_add_staff_type_and_update_grooming_staff.php',
        );
        $migration->up();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseMissing('users', ['email' => 'staff@bethlehem.com']);
        $this->assertDatabaseHas('users', [
            'user_id' => $staffId,
            'first_name' => 'Grooming',
            'last_name' => 'Staff',
            'username' => 'groomingstaff',
            'email' => 'bethlehem.staff.test@gmail.com',
            'password_hash' => $passwordHash,
            'role' => 'staff',
            'staff_type' => 'grooming',
        ]);
        $this->assertNotNull(
            DB::table('users')->where('user_id', $staffId)->value('email_verified_at'),
        );
    }
}
