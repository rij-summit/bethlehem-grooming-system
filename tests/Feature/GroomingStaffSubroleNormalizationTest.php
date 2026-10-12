<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GroomingStaffSubroleNormalizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['users', 'pending_staff_accounts'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->increments($name === 'users' ? 'user_id' : 'id');
                $table->string('role')->default('staff');
                $table->string('staff_type')->nullable();
                $table->string('staff_subrole')->nullable();
                $table->string('username')->nullable();
                $table->string('email');
                $table->string('password_hash');
                $table->boolean('is_active')->default(true);
                $table->boolean('is_archived')->default(false);
            });
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('pending_staff_accounts');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    public function test_migration_normalizes_only_legacy_grooming_subroles_without_changing_account_data(): void
    {
        $accounts = [
            ['staff', 'grooming', null, true, false],
            ['staff', 'grooming', null, false, false],
            ['staff', 'grooming', null, false, true],
            ['staff', 'clinic', 'veterinarian', true, false],
            ['staff', 'clinic', 'clinic_receptionist', true, false],
            ['staff', 'grooming', 'grooming_receptionist', true, false],
            ['staff', 'clinic', null, true, false],
            ['admin', 'grooming', null, true, false],
            ['customer', null, null, true, false],
        ];
        foreach ($accounts as $index => [$role, $type, $subrole, $active, $archived]) {
            DB::table('users')->insert([
                'user_id' => $index + 1,
                'role' => $role,
                'staff_type' => $type,
                'staff_subrole' => $subrole,
                'username' => 'staff'.$index,
                'email' => $index === 0 ? 'staff@bethlehem.com' : "account{$index}@example.test",
                'password_hash' => $index === 1 ? '!staff-password-setup-required!placeholder' : 'unchanged-hash',
                'is_active' => $active,
                'is_archived' => $archived,
            ]);
        }
        foreach ([['grooming', null], ['clinic', 'veterinarian'], ['grooming', 'grooming_receptionist']] as $index => [$type, $subrole]) {
            DB::table('pending_staff_accounts')->insert([
                'staff_type' => $type, 'staff_subrole' => $subrole,
                'email' => "pending{$index}@example.test", 'password_hash' => 'pending-hash',
            ]);
        }
        $users = DB::table('users')->orderBy('user_id')->get()->map(fn ($row) => (array) $row)->all();
        $pending = DB::table('pending_staff_accounts')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        foreach ([0, 1, 2] as $index) {
            $users[$index]['staff_subrole'] = 'grooming_receptionist';
        }
        $pending[0]['staff_subrole'] = 'grooming_receptionist';
        $migration = require database_path('migrations/2026_10_12_000003_normalize_grooming_receptionist_subroles.php');
        $migration->up();
        $migration->up();
        $migration->down();
        $this->assertSame($users, DB::table('users')->orderBy('user_id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame($pending, DB::table('pending_staff_accounts')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
    }
}
