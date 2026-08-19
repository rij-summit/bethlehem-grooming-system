<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PrivilegedCredentialSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('username')->nullable();
            $table->string('email')->unique();
            $table->string('phone')->unique();
            $table->string('password_hash');
            $table->string('role');
            $table->string('customer_tier')->default('new');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('email_verified_at')->nullable();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('tokenable_type');
            $table->unsignedBigInteger('tokenable_id');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_privileged_password_rotation_is_interactive_and_revokes_sessions(): void
    {
        $admin = $this->createUser('admin', 'admin@example.test', '09170000101');
        $admin->createToken('admin_token');

        $this->artisan('account:rotate-privileged-password', [
            'identifier' => $admin->email,
        ])
            ->expectsQuestion('New password', 'NewStrong!234')
            ->expectsQuestion('Confirm new password', 'NewStrong!234')
            ->expectsOutput("Password rotated and active sessions revoked for {$admin->email}.")
            ->assertSuccessful();

        $this->assertTrue(Hash::check('NewStrong!234', $admin->fresh()->password_hash));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_password_rotation_refuses_customer_accounts(): void
    {
        $customer = $this->createUser('customer', 'customer@example.test', '09170000102');

        $this->artisan('account:rotate-privileged-password', [
            'identifier' => $customer->email,
        ])
            ->expectsOutput('No admin or staff account matched that identifier.')
            ->assertFailed();
    }

    public function test_seeders_contain_no_deterministic_privileged_passwords(): void
    {
        $seeders = file_get_contents(base_path('database/seeders/AdminSeeder.php'))
            .file_get_contents(base_path('database/seeders/StaffSeeder.php'));

        $this->assertStringNotContainsString('admin123', $seeders);
        $this->assertStringNotContainsString('staff123', $seeders);
        $this->assertStringContainsString('ADMIN_SEED_PASSWORD', file_get_contents(base_path('.env.example')));
        $this->assertStringContainsString('STAFF_SEED_PASSWORD', file_get_contents(base_path('.env.example')));
    }

    private function createUser(string $role, string $email, string $phone): User
    {
        return User::query()->create([
            'first_name' => ucfirst($role),
            'last_name' => 'Tester',
            'email' => $email,
            'phone' => $phone,
            'password_hash' => Hash::make('old-password'),
            'role' => $role,
            'customer_tier' => 'new',
            'is_active' => true,
            'is_archived' => false,
            'email_verified_at' => now(),
        ]);
    }
}
