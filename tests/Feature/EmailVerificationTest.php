<?php

namespace Tests\Feature;

use App\Models\LoginEmailChallenge;
use App\Models\PendingCustomerRegistration;
use App\Models\User;
use App\Notifications\ConfirmLoginNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('username', 50)->nullable()->unique();
            $table->string('phone', 20)->unique();
            $table->string('email', 150)->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('email_verification_token', 64)->nullable()->unique();
            $table->timestamp('email_verification_expires_at')->nullable();
            $table->string('password_hash');
            $table->string('role')->default('customer');
            $table->string('customer_tier')->default('new');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
        });

        Schema::create('pending_customer_registrations', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('username', 50)->nullable()->unique();
            $table->string('phone', 20)->unique();
            $table->string('email', 150)->unique();
            $table->string('password_hash');
            $table->string('email_verification_token', 64)->nullable()->unique();
            $table->timestamp('email_verification_expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('login_email_challenges', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->unique();
            $table->string('token_hash', 64)->unique();
            $table->string('poll_token_hash', 64)->nullable()->unique();
            $table->boolean('remember_me')->default(true);
            $table->timestamp('expires_at')->index();
            $table->timestamp('approved_at')->nullable()->index();
            $table->timestamps();
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
            $table->index(['tokenable_type', 'tokenable_id']);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('login_email_challenges');
        Schema::dropIfExists('pending_customer_registrations');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_registration_queues_a_verification_link_and_hashes_its_token(): void
    {
        Notification::fake();
        config()->set('app.frontend_url', 'https://clinic.example/bethlehem');

        $response = $this->postJson('/api/register', $this->registrationPayload());

        $response
            ->assertCreated()
            ->assertJsonPath('requires_verification', true)
            ->assertJsonPath('email_delivery_queued', true)
            ->assertJsonMissingPath('token');

        $this->assertDatabaseMissing('users', [
            'email' => 'new.customer@example.test',
        ]);
        $pendingRegistration = PendingCustomerRegistration::where(
            'email',
            'new.customer@example.test',
        )->firstOrFail();

        $plainToken = $this->verificationTokenSentTo($pendingRegistration);

        $this->assertSame(64, strlen($plainToken));
        $this->assertSame(
            hash('sha256', $plainToken),
            $pendingRegistration->fresh()->email_verification_token,
        );
        $this->assertNotSame(
            $plainToken,
            $pendingRegistration->fresh()->email_verification_token,
        );
    }

    public function test_verification_creates_the_customer_account_and_logs_it_in(): void
    {
        Notification::fake();

        $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $pendingRegistration = PendingCustomerRegistration::firstOrFail();
        $plainToken = $this->verificationTokenSentTo($pendingRegistration);

        $this->postJson('/api/email/verify', ['token' => $plainToken])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.email', 'new.customer@example.test')
            ->assertJsonPath('user.role', 'customer')
            ->assertJsonStructure(['token']);

        $this->assertDatabaseCount('pending_customer_registrations', 0);
        $user = User::where('email', 'new.customer@example.test')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('strong-password', $user->password_hash));
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_verification_marks_an_active_customer_verified_and_logs_them_in(): void
    {
        $plainToken = str_repeat('a', 64);
        $user = User::factory()->unverified()->create([
            'email_verification_token' => hash('sha256', $plainToken),
            'email_verification_expires_at' => now()->addHour(),
        ]);

        $this->postJson('/api/email/verify', ['token' => $plainToken])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.user_id', $user->user_id)
            ->assertJsonStructure(['token']);

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->email_verification_token);
        $this->assertNull($user->email_verification_expires_at);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_registration_remains_recoverable_when_notification_queueing_fails(): void
    {
        $this->mock(Dispatcher::class, function ($mock) {
            $mock->shouldReceive('send')
                ->once()
                ->andThrow(new \RuntimeException('Queue unavailable'));
        });

        $this->postJson('/api/register', $this->registrationPayload())
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('email_delivery_queued', false)
            ->assertJsonPath('requires_verification', true);

        $this->assertDatabaseMissing('users', [
            'email' => 'new.customer@example.test',
        ]);
        $pendingRegistration = PendingCustomerRegistration::where(
            'email',
            'new.customer@example.test',
        )->firstOrFail();
        $this->assertNull($pendingRegistration->email_verification_token);
        $this->assertNull($pendingRegistration->email_verification_expires_at);
    }

    public function test_first_release_plain_tokens_remain_usable_during_the_upgrade(): void
    {
        $plainToken = str_repeat('b', 64);
        $user = User::factory()->unverified()->create([
            'email_verification_token' => $plainToken,
            'email_verification_expires_at' => now()->addHour(),
        ]);

        $this->postJson('/api/email/verify', ['token' => $plainToken])
            ->assertOk();

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_expired_and_already_used_links_cannot_verify_twice(): void
    {
        $plainToken = str_repeat('c', 64);
        $user = User::factory()->unverified()->create([
            'email_verification_token' => hash('sha256', $plainToken),
            'email_verification_expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/email/verify', ['token' => $plainToken])
            ->assertStatus(422)
            ->assertJsonPath('expired', true);
        $this->assertNull($user->fresh()->email_verified_at);

        $user->update(['email_verification_expires_at' => now()->addHour()]);

        $this->postJson('/api/email/verify', ['token' => $plainToken])->assertOk();
        $this->postJson('/api/email/verify', ['token' => $plainToken])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_disabled_or_archived_accounts_cannot_verify(): void
    {
        foreach ([
            ['is_active' => false, 'is_archived' => false],
            ['is_active' => true, 'is_archived' => true],
        ] as $index => $state) {
            $plainToken = str_repeat((string) ($index + 1), 64);
            $user = User::factory()->unverified()->create([
                ...$state,
                'email_verification_token' => hash('sha256', $plainToken),
                'email_verification_expires_at' => now()->addHour(),
            ]);

            $this->postJson('/api/email/verify', ['token' => $plainToken])
                ->assertForbidden()
                ->assertJsonPath('code', 'account_disabled')
                ->assertJsonMissingPath('token');
            $this->assertNull($user->fresh()->email_verified_at);
        }
    }

    public function test_unverified_customers_cannot_sign_in_or_use_a_preexisting_token(): void
    {
        $user = User::factory()->unverified()->create([
            'password_hash' => Hash::make('strong-password'),
        ]);

        $this->postJson('/api/sign-in', [
            'identifier' => $user->email,
            'password' => 'strong-password',
        ])
            ->assertForbidden()
            ->assertJsonPath('email_not_verified', true);

        $token = $user->createToken('auth_token')->plainTextToken;
        $this->withToken($token)->getJson('/api/me')
            ->assertForbidden()
            ->assertJsonPath('code', 'email_not_verified');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_pending_registration_gets_verification_message_instead_of_signing_in(): void
    {
        PendingCustomerRegistration::create([
            'first_name' => 'Pending',
            'last_name' => 'Customer',
            'username' => 'pendingcustomer',
            'phone' => '09120000000',
            'email' => 'pending@example.test',
            'password_hash' => Hash::make('strong-password'),
        ]);

        $this->postJson('/api/sign-in', [
            'identifier' => 'pending@example.test',
            'password' => 'strong-password',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'email_not_verified')
            ->assertJsonPath('email_not_verified', true);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_verified_customer_login_requires_and_consumes_an_email_challenge(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'login-confirmation@example.test',
            'password_hash' => Hash::make('strong-password'),
        ]);

        $response = $this->postJson('/api/sign-in', [
            'identifier' => $user->email,
            'password' => 'strong-password',
            'remember' => false,
        ]);
        $response
            ->assertAccepted()
            ->assertJsonPath('success', true)
            ->assertJsonPath('code', 'login_confirmation_required')
            ->assertJsonPath('requires_login_confirmation', true)
            ->assertJsonPath('email_delivery_queued', true)
            ->assertJsonStructure(['login_poll_token'])
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('user');

        $code = $this->loginCodeSentTo($user);
        $plainPollToken = $response->json('login_poll_token');
        $this->assertIsString($plainPollToken);
        $this->assertSame(64, strlen($plainPollToken));
        $challenge = LoginEmailChallenge::where('user_id', $user->user_id)->firstOrFail();
        $this->assertSame(
            hash_hmac('sha256', $code, $plainPollToken),
            $challenge->token_hash,
        );
        $this->assertNotSame($code, $challenge->token_hash);
        $this->assertSame(hash('sha256', $plainPollToken), $challenge->poll_token_hash);
        $this->assertNotSame($plainPollToken, $challenge->poll_token_hash);
        $this->assertFalse($challenge->remember_me);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $firstSessionResponse = $this->postJson('/api/email/login/confirm', [
            'poll_token' => $plainPollToken,
            'code' => $code,
        ]);
        $firstSessionResponse
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('approved', true)
            ->assertJsonPath('session_established', true)
            ->assertJsonPath('remember_me', false)
            ->assertJsonPath('user.user_id', $user->user_id)
            ->assertJsonPath('user.role', 'customer')
            ->assertJsonStructure(['token']);

        $this->assertNotNull($challenge->fresh()->approved_at);
        $this->assertDatabaseCount('login_email_challenges', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);

        // A dropped first response remains recoverable until the browser
        // acknowledges that it safely stored the returned session.
        $secondSessionResponse = $this->postJson('/api/email/login/confirm', [
            'poll_token' => $plainPollToken,
            'code' => $code,
        ]);
        $secondSessionResponse
            ->assertOk()
            ->assertJsonPath('approved', true)
            ->assertJsonStructure(['token']);
        $this->assertDatabaseCount('login_email_challenges', 1);
        $this->assertDatabaseCount('personal_access_tokens', 2);

        $this->withToken($secondSessionResponse->json('token'))
            ->postJson('/api/email/login/complete', [
                'poll_token' => $plainPollToken,
            ])
            ->assertOk();

        $this->assertDatabaseCount('login_email_challenges', 0);

        $this->postJson('/api/email/login/confirm', [
            'poll_token' => $plainPollToken,
            'code' => $code,
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('token');
    }

    public function test_login_confirmation_email_is_distinct_from_signup_verification(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'password_hash' => Hash::make('strong-password'),
        ]);

        $this->postJson('/api/sign-in', [
            'identifier' => $user->email,
            'password' => 'strong-password',
        ])->assertAccepted();

        Notification::assertSentTo(
            $user,
            ConfirmLoginNotification::class,
            function (ConfirmLoginNotification $notification) use ($user): bool {
                $message = $notification->toMail($user);

                $this->assertSame(
                    'Your Sign-In Code - Bethlehem Animal Clinic',
                    $message->subject,
                );
                $this->assertNull($message->actionText);
                $this->assertNull($message->actionUrl);
                $this->assertMatchesRegularExpression('/^\d{6}$/', $notification->code);
                $this->assertStringContainsString(
                    "**{$notification->code}**",
                    implode(' ', $message->introLines),
                );
                $this->assertMatchesRegularExpression(
                    '/<strong\b[^>]*>'.preg_quote($notification->code, '/').'<\/strong>/',
                    (string) $message->render(),
                );
                $this->assertStringNotContainsString(
                    'verify your email address',
                    strtolower(implode(' ', $message->introLines)),
                );

                return true;
            },
        );
    }

    public function test_incorrect_login_code_keeps_the_challenge_available(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'password_hash' => Hash::make('strong-password'),
        ]);

        $response = $this->postJson('/api/sign-in', [
            'identifier' => $user->email,
            'password' => 'strong-password',
        ])->assertAccepted();

        $correctCode = $this->loginCodeSentTo($user);
        $incorrectCode = $correctCode === '000000' ? '000001' : '000000';

        $this->postJson('/api/email/login/confirm', [
            'poll_token' => $response->json('login_poll_token'),
            'code' => $incorrectCode,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'login_code_invalid')
            ->assertJsonMissingPath('token');

        $this->assertDatabaseHas('login_email_challenges', [
            'user_id' => $user->user_id,
            'approved_at' => null,
        ]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_code_is_bound_to_the_original_browser_token(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'password_hash' => Hash::make('strong-password'),
        ]);

        $this->postJson('/api/sign-in', [
            'identifier' => $user->email,
            'password' => 'strong-password',
        ])->assertAccepted();
        $code = $this->loginCodeSentTo($user);

        $this->postJson('/api/email/login/confirm', [
            'poll_token' => str_repeat('x', 64),
            'code' => $code,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'login_code_invalid')
            ->assertJsonMissingPath('token');

        $this->assertDatabaseHas('login_email_challenges', [
            'user_id' => $user->user_id,
            'approved_at' => null,
        ]);
    }

    public function test_login_code_preserves_leading_zeroes(): void
    {
        $code = '012345';
        $plainPollToken = str_repeat('p', 64);
        $user = User::factory()->create();
        LoginEmailChallenge::create([
            'user_id' => $user->user_id,
            'token_hash' => hash_hmac('sha256', $code, $plainPollToken),
            'poll_token_hash' => hash('sha256', $plainPollToken),
            'remember_me' => true,
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->postJson('/api/email/login/confirm', [
            'poll_token' => $plainPollToken,
            'code' => $code,
        ])
            ->assertOk()
            ->assertJsonPath('session_established', true)
            ->assertJsonStructure(['token']);
    }

    public function test_login_code_is_locked_after_five_incorrect_attempts(): void
    {
        $plainPollToken = str_repeat('l', 64);
        $user = User::factory()->create();
        LoginEmailChallenge::create([
            'user_id' => $user->user_id,
            'token_hash' => hash_hmac('sha256', '123456', $plainPollToken),
            'poll_token_hash' => hash('sha256', $plainPollToken),
            'remember_me' => true,
            'expires_at' => now()->addMinutes(15),
        ]);

        foreach (range(1, 4) as $attempt) {
            $this->postJson('/api/email/login/confirm', [
                'poll_token' => $plainPollToken,
                'code' => '000000',
            ])->assertStatus(422);
        }

        $this->postJson('/api/email/login/confirm', [
            'poll_token' => $plainPollToken,
            'code' => '000000',
        ])
            ->assertStatus(429)
            ->assertJsonPath('code', 'login_code_locked')
            ->assertJsonMissingPath('token');

        $this->postJson('/api/email/login/confirm', [
            'poll_token' => $plainPollToken,
            'code' => '123456',
        ])->assertStatus(429);

        $this->assertDatabaseHas('login_email_challenges', [
            'user_id' => $user->user_id,
            'approved_at' => null,
        ]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_expired_login_confirmation_cannot_create_a_session(): void
    {
        $code = '123456';
        $plainPollToken = str_repeat('e', 64);
        $user = User::factory()->create();
        LoginEmailChallenge::create([
            'user_id' => $user->user_id,
            'token_hash' => hash_hmac('sha256', $code, $plainPollToken),
            'poll_token_hash' => hash('sha256', $plainPollToken),
            'remember_me' => true,
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/email/login/confirm', [
            'poll_token' => $plainPollToken,
            'code' => $code,
        ])
            ->assertStatus(422)
            ->assertJsonPath('expired', true)
            ->assertJsonMissingPath('token');

        $this->assertDatabaseCount('login_email_challenges', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_disabled_customer_cannot_consume_a_login_confirmation(): void
    {
        $code = '654321';
        $plainPollToken = str_repeat('f', 64);
        $user = User::factory()->create(['is_active' => false]);
        LoginEmailChallenge::create([
            'user_id' => $user->user_id,
            'token_hash' => hash_hmac('sha256', $code, $plainPollToken),
            'poll_token_hash' => hash('sha256', $plainPollToken),
            'remember_me' => true,
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->postJson('/api/email/login/confirm', [
            'poll_token' => $plainPollToken,
            'code' => $code,
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'account_disabled')
            ->assertJsonMissingPath('token');

        $this->assertDatabaseCount('login_email_challenges', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_failed_login_confirmation_queueing_does_not_leave_a_challenge(): void
    {
        $user = User::factory()->create([
            'password_hash' => Hash::make('strong-password'),
        ]);
        $this->mock(Dispatcher::class, function ($mock) {
            $mock->shouldReceive('send')
                ->once()
                ->andThrow(new \RuntimeException('Queue unavailable'));
        });

        $this->postJson('/api/sign-in', [
            'identifier' => $user->email,
            'password' => 'strong-password',
        ])
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('token');

        $this->assertDatabaseCount('login_email_challenges', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_privileged_login_still_completes_after_password_confirmation(): void
    {
        Notification::fake();
        $staff = User::factory()->create([
            'role' => 'staff',
            'password_hash' => Hash::make('strong-password'),
        ]);

        $this->postJson('/api/sign-in', [
            'identifier' => $staff->email,
            'password' => 'strong-password',
        ])
            ->assertOk()
            ->assertJsonPath('user.role', 'staff')
            ->assertJsonStructure(['token'])
            ->assertJsonMissingPath('requires_login_confirmation');

        Notification::assertNothingSent();
        $this->assertDatabaseCount('login_email_challenges', 0);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_sign_in_keeps_other_sessions_and_logout_revokes_only_current_token(): void
    {
        $user = User::factory()->create([
            'email' => 'sessions@example.test',
            'password_hash' => Hash::make('strong-password'),
            'role' => 'admin',
        ]);

        $firstToken = $this->postJson('/api/sign-in', [
            'identifier' => $user->email,
            'password' => 'strong-password',
        ])->assertOk()->json('token');

        $secondToken = $this->postJson('/api/sign-in', [
            'identifier' => $user->email,
            'password' => 'strong-password',
        ])->assertOk()->json('token');

        $this->assertDatabaseCount('personal_access_tokens', 2);

        $this->withToken($firstToken)->postJson('/api/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->app['auth']->forgetGuards();
        $this->withToken($firstToken)->getJson('/api/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($secondToken)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.user_id', $user->user_id);
    }

    public function test_account_state_is_rechecked_on_every_authenticated_request(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;
        $user->update(['is_active' => false]);

        $this->withToken($token)->getJson('/api/me')
            ->assertForbidden()
            ->assertJsonPath('code', 'account_disabled');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_sign_in_accepts_the_documented_plus_63_phone_format(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'phone' => '09171234567',
            'password_hash' => Hash::make('strong-password'),
        ]);

        $this->postJson('/api/sign-in', [
            'identifier' => '+63'.substr($user->phone, 1),
            'password' => 'strong-password',
        ])
            ->assertAccepted()
            ->assertJsonPath('code', 'login_confirmation_required')
            ->assertJsonMissingPath('token');

        $this->assertDatabaseHas('login_email_challenges', [
            'user_id' => $user->user_id,
        ]);
    }

    public function test_sign_in_throttling_cannot_globally_lock_out_an_identifier(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'password_hash' => Hash::make('strong-password'),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10']);
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/sign-in', [
                'identifier' => $user->email,
                'password' => 'wrong-password',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/sign-in', [
            'identifier' => $user->email,
            'password' => 'wrong-password',
        ])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11'])
            ->postJson('/api/sign-in', [
                'identifier' => $user->email,
                'password' => 'strong-password',
            ])
            ->assertAccepted()
            ->assertJsonPath('requires_login_confirmation', true);
    }

    public function test_resend_is_generic_and_rotates_to_a_hashed_token_for_active_users(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->postJson('/api/email/resend', ['email' => strtoupper($user->email)])
            ->assertOk()
            ->assertJsonPath('success', true);

        $plainToken = $this->verificationTokenSentTo($user);
        $this->assertSame(hash('sha256', $plainToken), $user->fresh()->email_verification_token);

        Notification::fake();
        $this->postJson('/api/email/resend', ['email' => 'missing@example.test'])
            ->assertOk()
            ->assertJsonPath(
                'message',
                'If that email is registered and unverified, a new verification link has been sent.',
            );
        Notification::assertNothingSent();
    }

    public function test_resend_rotates_a_pending_registration_token(): void
    {
        Notification::fake();
        $pendingRegistration = PendingCustomerRegistration::create([
            'first_name' => 'Pending',
            'last_name' => 'Customer',
            'username' => 'pendingresend',
            'phone' => '09120000001',
            'email' => 'pending-resend@example.test',
            'password_hash' => Hash::make('strong-password'),
        ]);

        $this->postJson('/api/email/resend', [
            'email' => strtoupper($pendingRegistration->email),
        ])->assertOk();

        $plainToken = $this->verificationTokenSentTo($pendingRegistration);
        $this->assertSame(
            hash('sha256', $plainToken),
            $pendingRegistration->fresh()->email_verification_token,
        );
        $this->assertDatabaseCount('users', 0);
    }

    public function test_failed_resend_queueing_does_not_invalidate_the_previous_link(): void
    {
        $oldTokenHash = hash('sha256', str_repeat('d', 64));
        $oldExpiry = now()->addHour()->startOfSecond();
        $user = User::factory()->unverified()->create([
            'email_verification_token' => $oldTokenHash,
            'email_verification_expires_at' => $oldExpiry,
        ]);

        $this->mock(Dispatcher::class, function ($mock) {
            $mock->shouldReceive('send')
                ->once()
                ->andThrow(new \RuntimeException('Queue unavailable'));
        });

        $this->postJson('/api/email/resend', ['email' => $user->email])
            ->assertStatus(503)
            ->assertJsonPath('success', false);

        $user->refresh();
        $this->assertSame($oldTokenHash, $user->email_verification_token);
        $this->assertTrue($oldExpiry->equalTo($user->email_verification_expires_at));
    }

    public function test_resend_endpoint_is_rate_limited(): void
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->postJson('/api/email/resend', ['email' => 'missing@example.test'])
                ->assertOk();
        }

        $this->postJson('/api/email/resend', ['email' => 'missing@example.test'])
            ->assertTooManyRequests();
    }

    private function registrationPayload(): array
    {
        return [
            'first_name' => 'New',
            'last_name' => 'Customer',
            'username' => 'newcustomer',
            'phone' => '09123456789',
            'email' => 'NEW.CUSTOMER@example.test',
            'password' => 'strong-password',
            'password_confirmation' => 'strong-password',
        ];
    }

    private function verificationTokenSentTo(
        User|PendingCustomerRegistration $notifiable,
    ): string {
        $verificationUrl = null;

        Notification::assertSentTo(
            $notifiable,
            VerifyEmailNotification::class,
            function (VerifyEmailNotification $notification) use ($notifiable, &$verificationUrl) {
                $verificationUrl = $notification->toMail($notifiable)->actionUrl;

                return true;
            },
        );

        $this->assertIsString($verificationUrl);
        $this->assertStringStartsWith(
            rtrim((string) config('app.frontend_url'), '/').'/pages/client/verify-email.html#',
            $verificationUrl,
        );

        parse_str((string) parse_url($verificationUrl, PHP_URL_FRAGMENT), $fragment);
        $this->assertArrayHasKey('token', $fragment);

        return $fragment['token'];
    }

    private function loginCodeSentTo(User $user): string
    {
        $code = null;

        Notification::assertSentTo(
            $user,
            ConfirmLoginNotification::class,
            function (ConfirmLoginNotification $notification) use (&$code) {
                $code = $notification->code;

                return true;
            },
        );

        $this->assertIsString($code);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        return $code;
    }
}
