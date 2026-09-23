<?php

namespace Tests\Feature;

use App\Models\LoginEmailChallenge;
use App\Models\PendingStaffAccount;
use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Notifications\ConfirmLoginNotification;
use App\Notifications\SetUpStaffPasswordNotification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminStaffAccountManagementTest extends TestCase
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
            $table->string('phone', 20)->nullable()->unique();
            $table->string('password_hash');
            $table->string('role');
            $table->string('staff_type', 20)->nullable();
            $table->string('staff_subrole', 30)->nullable();
            $table->string('customer_tier')->default('new');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('email_verified_at')->nullable();
        });

        Schema::create('pending_customer_registrations', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('username', 50)->nullable()->unique();
            $table->string('email', 150)->unique();
            $table->string('phone', 20)->unique();
            $table->string('password_hash');
            $table->string('email_verification_token', 64)->nullable();
            $table->timestamp('email_verification_expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pending_staff_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('requested_by_user_id');
            $table->string('staff_type', 20);
            $table->string('staff_subrole', 30)->nullable();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('username', 50)->nullable()->unique();
            $table->string('email', 150)->unique();
            $table->string('password_hash');
            $table->string('code_hash');
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('last_sent_at');
            $table->timestamps();
        });

        Schema::create('password_reset_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id')->unique();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('last_sent_at');
            $table->timestamps();
        });

        Schema::create('login_email_challenges', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id')->unique();
            $table->string('token_hash', 64)->unique();
            $table->string('poll_token_hash', 64)->nullable()->unique();
            $table->boolean('remember_me')->default(true);
            $table->timestamp('expires_at')->index();
            $table->timestamp('approved_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('privileged_credential_changes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('requested_by_user_id');
            $table->unsignedInteger('target_user_id');
            $table->string('target_role');
            $table->string('code_hash')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('tokenable_type');
            $table->unsignedBigInteger('tokenable_id');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['tokenable_type', 'tokenable_id']);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('privileged_credential_changes');
        Schema::dropIfExists('login_email_challenges');
        Schema::dropIfExists('password_reset_requests');
        Schema::dropIfExists('pending_staff_accounts');
        Schema::dropIfExists('pending_customer_registrations');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_admin_creates_staff_with_a_single_use_password_setup_link(): void
    {
        Notification::fake();
        $admin = $this->createUser('admin', 'bethlehem.admin.test@gmail.com', 'Admin');
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/security/staff-accounts', [
            'staff_type' => 'clinic',
            'staff_subrole' => 'veterinarian',
            'first_name' => 'jOhN',
            'last_name' => 'sMiTh',
            'username' => 'Clinic_Staff',
            'email' => 'NEW.CLINIC.STAFF@example.test',
        ]);
        $response
            ->assertCreated()
            ->assertJsonPath('message', 'Staff account created')
            ->assertJsonPath('username', 'Clinic_Staff')
            ->assertJsonPath('staff.staff_type', 'clinic')
            ->assertJsonPath('staff.staff_subrole', 'veterinarian')
            ->assertJsonPath('staff.is_active', true)
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('password');

        $staff = User::query()->where('email', 'new.clinic.staff@example.test')->sole();
        $this->assertSame('John', $staff->first_name);
        $this->assertSame('Smith', $staff->last_name);
        $this->assertSame('staff', $staff->role);
        $this->assertSame('clinic', $staff->staff_type);
        $this->assertSame('veterinarian', $staff->staff_subrole);
        $this->assertSame('Clinic_Staff', $staff->username);
        $this->assertNull($staff->phone);
        $this->assertTrue($staff->requiresPasswordSetup());
        $this->assertDatabaseCount('pending_staff_accounts', 0);

        $plainToken = $this->staffSetupLinkSentTo($staff);
        $setupRequest = PasswordResetRequest::query()->sole();
        $this->assertSame(hash('sha256', $plainToken), $setupRequest->token_hash);
        $this->assertTrue(
            $setupRequest->expires_at->between(
                now()->addHours(23)->addMinutes(59),
                now()->addHours(24)->addSeconds(5),
            ),
        );

        $this->postJson('/api/sign-in', [
            'identifier' => 'Clinic_Staff',
            'password' => 'AnyPassword!234',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'password_setup_required');

        Sanctum::actingAs($staff);
        $this->getJson('/api/me')
            ->assertForbidden()
            ->assertJsonPath('code', 'password_setup_required');

        $this->postJson('/api/password/reset/verify', [
            'token' => $plainToken,
        ])->assertUnprocessable();

        $this->postJson('/api/staff/password-setup/complete', [
            'token' => $plainToken,
            'password' => 'CreatedPassword!234',
            'password_confirmation' => 'CreatedPassword!234',
        ])
            ->assertOk()
            ->assertJsonPath('completed_setup', true)
            ->assertJsonPath('user.role', 'staff')
            ->assertJsonStructure(['token']);

        $staff->refresh();
        $this->assertFalse($staff->requiresPasswordSetup());
        $this->assertTrue(Hash::check('CreatedPassword!234', $staff->password_hash));
        $this->assertDatabaseCount('password_reset_requests', 0);

        $this->postJson('/api/staff/password-setup/complete', [
            'token' => $plainToken,
            'password' => 'DifferentPassword!234',
            'password_confirmation' => 'DifferentPassword!234',
        ])->assertUnprocessable();
    }

    public function test_existing_email_cannot_create_a_duplicate_staff_account(): void
    {
        Notification::fake();
        $admin = $this->createUser('admin', 'bethlehem.admin.test@gmail.com', 'Admin');
        $this->createUser('staff', 'existing.staff@example.test');
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/security/staff-accounts', [
            'staff_type' => 'grooming',
            'first_name' => 'Grooming',
            'last_name' => 'Staff',
            'username' => 'anothergroomer',
            'email' => 'EXISTING.STAFF@example.test',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('pending_staff_accounts', 0);
        Notification::assertNothingSent();
    }

    public function test_staff_account_role_names_and_email_fields_are_required(): void
    {
        $admin = $this->createUser('admin', 'bethlehem.admin.test@gmail.com', 'Admin');
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/security/staff-accounts', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'staff_type',
                'first_name',
                'last_name',
                'email',
            ]);
    }

    public function test_existing_username_cannot_create_a_duplicate_staff_account(): void
    {
        Notification::fake();
        $admin = $this->createUser('admin', 'bethlehem.admin.test@gmail.com', 'Admin');
        $this->createUser('staff', 'existing.staff@example.test', 'ClinicStaff');
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/security/staff-accounts', [
            'staff_type' => 'clinic',
            'staff_subrole' => 'clinic_receptionist',
            'first_name' => 'Clinic',
            'last_name' => 'Staff',
            'username' => 'clinicstaff',
            'email' => 'different.staff@example.test',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username');

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('pending_staff_accounts', 0);
        Notification::assertNothingSent();
    }

    public function test_blank_username_is_generated_from_normalized_staff_names(): void
    {
        Notification::fake();
        $admin = $this->createUser('admin', 'bethlehem.admin.test@gmail.com', 'Admin');
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/security/staff-accounts', [
            'staff_type' => 'grooming',
            'first_name' => 'jOhN',
            'last_name' => 'sMiTh',
            'email' => 'generated.staff@example.test',
        ])->assertCreated();

        $staff = User::query()->where('email', 'generated.staff@example.test')->sole();
        $this->assertSame('John', $staff->first_name);
        $this->assertSame('Smith', $staff->last_name);
        $this->assertNull($staff->staff_subrole);
        $this->assertSame('JohnSmith', $staff->username);
        $this->assertTrue($staff->requiresPasswordSetup());
    }

    public function test_generated_username_must_be_unique(): void
    {
        Notification::fake();
        $admin = $this->createUser('admin', 'bethlehem.admin.test@gmail.com', 'Admin');
        $this->createUser('staff', 'existing.staff@example.test', 'JohnSmith');
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/security/staff-accounts', [
            'staff_type' => 'clinic',
            'staff_subrole' => 'veterinarian',
            'first_name' => 'john',
            'last_name' => 'smith',
            'email' => 'different.staff@example.test',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username');

        $this->assertDatabaseCount('pending_staff_accounts', 0);
        Notification::assertNothingSent();
    }

    public function test_staff_subrole_must_match_the_selected_staff_type(): void
    {
        Notification::fake();
        $admin = $this->createUser('admin', 'bethlehem.admin.test@gmail.com', 'Admin');
        Sanctum::actingAs($admin);

        $basePayload = [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'subrole.staff@example.test',
        ];

        $this->postJson('/api/admin/security/staff-accounts', $basePayload + [
            'staff_type' => 'clinic',
        ])->assertUnprocessable()->assertJsonValidationErrors('staff_subrole');

        $this->postJson('/api/admin/security/staff-accounts', $basePayload + [
            'staff_type' => 'clinic',
            'staff_subrole' => 'grooming_receptionist',
        ])->assertUnprocessable()->assertJsonValidationErrors('staff_subrole');

        $this->postJson('/api/admin/security/staff-accounts', $basePayload + [
            'staff_type' => 'grooming',
            'staff_subrole' => 'veterinarian',
        ])->assertUnprocessable()->assertJsonValidationErrors('staff_subrole');

        $this->assertDatabaseCount('pending_staff_accounts', 0);
        Notification::assertNothingSent();
    }

    public function test_legacy_pending_staff_account_uses_the_existing_role_label_name(): void
    {
        $admin = $this->createUser('admin', 'bethlehem.admin.test@gmail.com', 'Admin');
        Sanctum::actingAs($admin);
        $pending = PendingStaffAccount::query()->create([
            'requested_by_user_id' => $admin->user_id,
            'staff_type' => 'clinic',
            'username' => 'legacyclinic',
            'email' => 'legacy.clinic@example.test',
            'password_hash' => Hash::make('LegacyStaff!234'),
            'code_hash' => Hash::make('123456'),
            'failed_attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'last_sent_at' => now(),
        ]);

        $this->postJson("/api/admin/security/staff-accounts/{$pending->id}/confirm", [
            'code' => '123456',
        ])->assertCreated();

        $staff = User::query()->where('email', 'legacy.clinic@example.test')->sole();
        $this->assertSame('Clinic', $staff->first_name);
        $this->assertSame('Staff', $staff->last_name);
    }

    public function test_deactivated_staff_is_retained_and_cannot_sign_in_until_reactivated(): void
    {
        Notification::fake();
        $admin = $this->createUser('admin', 'bethlehem.admin.test@gmail.com', 'Admin');
        $staff = $this->createUser(
            'staff',
            'bethlehem.staff.test@gmail.com',
            'groomingstaff',
            'grooming',
        );
        $staff->createToken('admin_token');
        LoginEmailChallenge::query()->create([
            'user_id' => $staff->user_id,
            'token_hash' => hash('sha256', 'code'),
            'poll_token_hash' => hash('sha256', 'poll'),
            'expires_at' => now()->addMinutes(15),
        ]);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/security/staff/{$staff->user_id}/status", [
            'active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Staff account deactivated.')
            ->assertJsonPath('staff.is_active', false);

        $this->assertDatabaseHas('users', [
            'user_id' => $staff->user_id,
            'email' => 'bethlehem.staff.test@gmail.com',
            'is_active' => false,
        ]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $staff->user_id,
        ]);
        $this->assertDatabaseMissing('login_email_challenges', [
            'user_id' => $staff->user_id,
        ]);

        $this->postJson('/api/sign-in', [
            'identifier' => 'groomingstaff',
            'password' => 'CurrentStaff!234',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'account_disabled');

        $this->patchJson("/api/admin/security/staff/{$staff->user_id}/status", [
            'active' => true,
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Staff account reactivated.')
            ->assertJsonPath('staff.is_active', true);

        Notification::fake();
        $this->postJson('/api/sign-in', [
            'identifier' => 'groomingstaff',
            'password' => 'CurrentStaff!234',
        ])
            ->assertAccepted()
            ->assertJsonPath('requires_login_confirmation', true)
            ->assertJsonPath('email', 'bethlehem.staff.test@gmail.com');
        Notification::assertSentTo($staff->fresh(), ConfirmLoginNotification::class);
    }

    public function test_staff_cannot_create_or_change_the_status_of_staff_accounts(): void
    {
        $staff = $this->createUser('staff', 'staff@example.test');
        Sanctum::actingAs($staff);

        $this->postJson('/api/admin/security/staff-accounts', [
            'staff_type' => 'clinic',
            'first_name' => 'Another',
            'last_name' => 'Staff',
            'username' => 'anotherstaff',
            'email' => 'another.staff@example.test',
        ])->assertForbidden();

        $this->patchJson("/api/admin/security/staff/{$staff->user_id}/status", [
            'active' => false,
        ])->assertForbidden();
    }

    private function staffSetupLinkSentTo(User $staff): string
    {
        $plainToken = null;

        Notification::assertSentTo(
            $staff,
            SetUpStaffPasswordNotification::class,
            function (SetUpStaffPasswordNotification $notification) use ($staff, &$plainToken): bool {
                $message = $notification->toMail($staff);
                $this->assertSame(
                    'Set Up Your Password - Bethlehem Animal Clinic',
                    $message->subject,
                );
                $this->assertSame('Set Up Your Password', $message->actionText);
                $this->assertStringContainsString(
                    'expires in 24 hours',
                    implode(' ', [...$message->introLines, ...$message->outroLines]),
                );
                $this->assertStringContainsString(
                    '/pages/client/set-up-password.html#token=',
                    $notification->setupUrl,
                );
                parse_str(
                    (string) parse_url($notification->setupUrl, PHP_URL_FRAGMENT),
                    $fragment,
                );
                $plainToken = $fragment['token'] ?? null;

                return is_string($plainToken) && strlen($plainToken) === 64;
            },
        );

        return $plainToken;
    }

    private function createUser(
        string $role,
        string $email,
        ?string $username = null,
        ?string $staffType = null,
    ): User {
        return User::query()->create([
            'first_name' => $role === 'staff' ? 'Grooming' : 'Admin',
            'last_name' => 'Staff',
            'username' => $username,
            'email' => $email,
            'phone' => $role === 'admin' ? '09170002001' : null,
            'password_hash' => Hash::make(
                $role === 'admin' ? 'CurrentAdmin!234' : 'CurrentStaff!234',
            ),
            'role' => $role,
            'staff_type' => $staffType,
            'customer_tier' => 'new',
            'is_active' => true,
            'is_archived' => false,
            'email_verified_at' => now(),
        ]);
    }
}
