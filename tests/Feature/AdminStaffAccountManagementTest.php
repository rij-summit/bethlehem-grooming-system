<?php

namespace Tests\Feature;

use App\Models\LoginEmailChallenge;
use App\Models\PendingStaffAccount;
use App\Models\User;
use App\Notifications\ConfirmLoginNotification;
use App\Notifications\VerifyStaffAccountEmailNotification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Notifications\AnonymousNotifiable;
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
            $table->string('username', 50)->nullable()->unique();
            $table->string('email', 150)->unique();
            $table->string('password_hash');
            $table->string('code_hash');
            $table->unsignedTinyInteger('failed_attempts')->default(0);
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
        Schema::dropIfExists('pending_staff_accounts');
        Schema::dropIfExists('pending_customer_registrations');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_admin_creates_a_clinic_staff_account_only_after_email_code_confirmation(): void
    {
        Notification::fake();
        $admin = $this->createUser('admin', 'bethlehem.admin.test@gmail.com', 'Admin');
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/security/staff-accounts', [
            'staff_type' => 'clinic',
            'username' => 'clinicstaff',
            'email' => 'NEW.CLINIC.STAFF@example.test',
            'password' => 'ClinicStaff!234',
            'password_confirmation' => 'ClinicStaff!234',
        ]);
        $response
            ->assertAccepted()
            ->assertJsonPath('purpose', 'Verify Clinic Staff email')
            ->assertJsonPath('staff_label', 'Clinic Staff')
            ->assertJsonMissingPath('code');

        $this->assertDatabaseMissing('users', ['email' => 'new.clinic.staff@example.test']);
        $pending = PendingStaffAccount::query()->sole();
        $this->assertSame('clinicstaff', $pending->username);
        $this->assertSame('new.clinic.staff@example.test', $pending->email);
        $this->assertNotSame('ClinicStaff!234', $pending->password_hash);
        $this->assertTrue(Hash::check('ClinicStaff!234', $pending->password_hash));

        $code = $this->staffAccountCodeSentTo(
            'new.clinic.staff@example.test',
            'Clinic Staff',
        );
        $this->assertNotSame($code, $pending->code_hash);
        $this->assertTrue(Hash::check($code, $pending->code_hash));

        $this->postJson("/api/admin/security/staff-accounts/{$pending->id}/confirm", [
            'code' => $code,
        ])
            ->assertCreated()
            ->assertJsonPath('message', 'Clinic Staff account created.')
            ->assertJsonPath('staff.staff_type', 'clinic')
            ->assertJsonPath('staff.is_active', true);

        $staff = User::query()->where('email', 'new.clinic.staff@example.test')->sole();
        $this->assertSame('Clinic', $staff->first_name);
        $this->assertSame('Staff', $staff->last_name);
        $this->assertSame('staff', $staff->role);
        $this->assertSame('clinic', $staff->staff_type);
        $this->assertSame('clinicstaff', $staff->username);
        $this->assertNull($staff->phone);
        $this->assertTrue(Hash::check('ClinicStaff!234', $staff->password_hash));
        $this->assertDatabaseCount('pending_staff_accounts', 0);
    }

    public function test_existing_email_cannot_create_a_duplicate_staff_account(): void
    {
        Notification::fake();
        $admin = $this->createUser('admin', 'bethlehem.admin.test@gmail.com', 'Admin');
        $this->createUser('staff', 'existing.staff@example.test');
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/security/staff-accounts', [
            'staff_type' => 'grooming',
            'username' => 'anothergroomer',
            'email' => 'EXISTING.STAFF@example.test',
            'password' => 'GroomingStaff!234',
            'password_confirmation' => 'GroomingStaff!234',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('pending_staff_accounts', 0);
        Notification::assertNothingSent();
    }

    public function test_staff_account_role_username_email_and_password_fields_are_required(): void
    {
        $admin = $this->createUser('admin', 'bethlehem.admin.test@gmail.com', 'Admin');
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/security/staff-accounts', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'staff_type',
                'username',
                'email',
                'password',
                'password_confirmation',
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
            'username' => 'clinicstaff',
            'email' => 'different.staff@example.test',
            'password' => 'AnotherStaff!234',
            'password_confirmation' => 'AnotherStaff!234',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username');

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('pending_staff_accounts', 0);
        Notification::assertNothingSent();
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
            'username' => 'anotherstaff',
            'email' => 'another.staff@example.test',
            'password' => 'AnotherStaff!234',
            'password_confirmation' => 'AnotherStaff!234',
        ])->assertForbidden();

        $this->patchJson("/api/admin/security/staff/{$staff->user_id}/status", [
            'active' => false,
        ])->assertForbidden();
    }

    private function staffAccountCodeSentTo(string $email, string $staffLabel): string
    {
        $code = null;

        Notification::assertSentOnDemand(
            VerifyStaffAccountEmailNotification::class,
            function (
                VerifyStaffAccountEmailNotification $notification,
                array $channels,
                AnonymousNotifiable $notifiable,
            ) use ($email, $staffLabel, &$code): bool {
                $message = $notification->toMail($notifiable);
                $mailText = implode(' ', $message->introLines);

                $this->assertSame($email, $notifiable->routes['mail']);
                $this->assertContains('mail', $channels);
                $this->assertSame($staffLabel, $notification->staffLabel);
                $this->assertSame(
                    "Verify {$staffLabel} Email - Bethlehem Animal Clinic",
                    $message->subject,
                );
                $this->assertStringContainsString(
                    "administrator requested a {$staffLabel} account",
                    $mailText,
                );
                $this->assertStringContainsString(
                    "six-digit email verification code is: {$notification->code}",
                    $mailText,
                );
                $this->assertNull($message->actionText);
                $this->assertNull($message->actionUrl);
                $code = $notification->code;

                return preg_match('/^\d{6}$/', $code) === 1;
            },
        );

        return $code;
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
