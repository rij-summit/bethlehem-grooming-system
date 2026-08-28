<?php

namespace Tests\Feature;

use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Notifications\PasswordResetLinkNotification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->increments('user_id');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('username', 50)->nullable()->unique();
            $table->string('email')->unique();
            $table->string('phone')->unique();
            $table->string('password_hash');
            $table->string('role');
            $table->string('customer_tier')->default('new');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('account_deleted_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
        });

        Schema::create('password_reset_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id')->unique();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('last_sent_at');
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
        Schema::dropIfExists('password_reset_requests');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_active_user_receives_a_one_time_password_reset_link_and_can_set_a_new_password(): void
    {
        Notification::fake();
        $user = $this->createUser('customer');
        $user->createToken('auth_token');

        $this->postJson('/api/password/forgot', [
            'email' => ' CUSTOMER@EXAMPLE.TEST ',
        ])
            ->assertAccepted()
            ->assertJsonPath(
                'message',
                'If an account exists for that email, a password reset link has been sent.',
            );

        $plainToken = $this->resetTokenSentTo($user);
        $resetRequest = PasswordResetRequest::query()->sole();
        $this->assertSame(hash('sha256', $plainToken), $resetRequest->token_hash);
        $this->assertNotSame($plainToken, $resetRequest->token_hash);
        $this->assertTrue(
            $resetRequest->expires_at->between(
                now()->addMinutes(14),
                now()->addMinutes(15)->addSeconds(5),
            ),
        );

        $this->postJson('/api/password/reset/verify', [
            'token' => $plainToken,
        ])
            ->assertOk()
            ->assertJsonPath('email', 'c*******@example.test');

        $this->postJson('/api/password/reset', [
            'token' => $plainToken,
            'password' => 'CurrentPass!234',
            'password_confirmation' => 'CurrentPass!234',
        ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Choose a password that is different from the current password.',
            );

        $this->postJson('/api/password/reset', [
            'token' => $plainToken,
            'password' => 'UpdatedPass!234',
            'password_confirmation' => 'UpdatedPass!234',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Your password has been reset successfully.');

        $this->assertTrue(Hash::check('UpdatedPass!234', $user->fresh()->password_hash));
        $this->assertDatabaseCount('password_reset_requests', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->postJson('/api/password/reset', [
            'token' => $plainToken,
            'password' => 'AnotherPass!234',
            'password_confirmation' => 'AnotherPass!234',
        ])->assertUnprocessable();
    }

    public function test_unknown_and_disabled_accounts_receive_the_same_generic_response_without_email(): void
    {
        Notification::fake();
        $disabled = $this->createUser('admin', 'disabled@example.test', false);

        foreach (['missing@example.test', 'disabled@example.test'] as $email) {
            $this->postJson('/api/password/forgot', ['email' => $email])
                ->assertAccepted()
                ->assertJsonPath(
                    'message',
                    'If an account exists for that email, a password reset link has been sent.',
                );
        }

        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_requests', 0);
        $this->assertFalse($disabled->is_active);
    }

    public function test_expired_password_reset_link_cannot_change_the_password(): void
    {
        Notification::fake();
        $user = $this->createUser('staff', 'staff@example.test');

        $this->postJson('/api/password/forgot', [
            'email' => $user->email,
        ])->assertAccepted();
        $plainToken = $this->resetTokenSentTo($user);
        PasswordResetRequest::query()->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/password/reset/verify', [
            'token' => $plainToken,
        ])->assertUnprocessable();

        $this->postJson('/api/password/reset', [
            'token' => $plainToken,
            'password' => 'UpdatedPass!234',
            'password_confirmation' => 'UpdatedPass!234',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('expired', true);

        $this->assertTrue(Hash::check('CurrentPass!234', $user->fresh()->password_hash));
        $this->assertDatabaseCount('password_reset_requests', 0);
    }

    public function test_password_reset_requires_a_strong_confirmed_password(): void
    {
        $this->postJson('/api/password/reset', [
            'token' => str_repeat('a', 64),
            'password' => 'weak',
            'password_confirmation' => 'different',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    private function resetTokenSentTo(User $user): string
    {
        $plainToken = null;

        Notification::assertSentTo(
            $user,
            PasswordResetLinkNotification::class,
            function (PasswordResetLinkNotification $notification) use ($user, &$plainToken): bool {
                $message = $notification->toMail($user);
                $this->assertSame(
                    'Reset Your Password - Bethlehem Animal Clinic',
                    $message->subject,
                );
                $this->assertSame('Reset Password', $message->actionText);
                $this->assertStringContainsString(
                    'expires in 15 minutes',
                    implode(' ', [...$message->introLines, ...$message->outroLines]),
                );
                $this->assertStringContainsString(
                    '/pages/client/reset-password.html#token=',
                    $notification->resetUrl,
                );
                $this->assertStringNotContainsString(
                    'six-digit',
                    strtolower(implode(' ', $message->introLines)),
                );

                parse_str(
                    (string) parse_url($notification->resetUrl, PHP_URL_FRAGMENT),
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
        string $email = 'customer@example.test',
        bool $active = true,
    ): User {
        return User::query()->create([
            'first_name' => ucfirst($role),
            'last_name' => 'Bethlehem',
            'username' => ucfirst($role),
            'email' => $email,
            'phone' => '09'.random_int(100000000, 999999999),
            'password_hash' => Hash::make('CurrentPass!234'),
            'role' => $role,
            'customer_tier' => 'new',
            'is_active' => $active,
            'is_archived' => false,
            'account_deleted_at' => null,
            'email_verified_at' => now(),
        ]);
    }
}
