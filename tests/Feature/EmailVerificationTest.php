<?php

namespace Tests\Feature;

use App\Models\PendingCustomerRegistration;
use App\Models\User;
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

    public function test_verification_creates_the_customer_account_and_removes_pending_registration(): void
    {
        Notification::fake();

        $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $pendingRegistration = PendingCustomerRegistration::firstOrFail();
        $plainToken = $this->verificationTokenSentTo($pendingRegistration);

        $this->postJson('/api/email/verify', ['token' => $plainToken])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonMissingPath('token');

        $this->assertDatabaseCount('pending_customer_registrations', 0);
        $user = User::where('email', 'new.customer@example.test')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('strong-password', $user->password_hash));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_verification_marks_an_active_customer_verified_without_logging_them_in(): void
    {
        $plainToken = str_repeat('a', 64);
        $user = User::factory()->unverified()->create([
            'email_verification_token' => hash('sha256', $plainToken),
            'email_verification_expires_at' => now()->addHour(),
        ]);

        $this->postJson('/api/email/verify', ['token' => $plainToken])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('user');

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->email_verification_token);
        $this->assertNull($user->email_verification_expires_at);
        $this->assertDatabaseCount('personal_access_tokens', 0);
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

    public function test_sign_in_keeps_other_sessions_and_logout_revokes_only_current_token(): void
    {
        $user = User::factory()->create([
            'email' => 'sessions@example.test',
            'password_hash' => Hash::make('strong-password'),
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
        $user = User::factory()->create([
            'phone' => '09171234567',
            'password_hash' => Hash::make('strong-password'),
        ]);

        $this->postJson('/api/sign-in', [
            'identifier' => '+63'.substr($user->phone, 1),
            'password' => 'strong-password',
        ])
            ->assertOk()
            ->assertJsonPath('user.user_id', $user->user_id);
    }

    public function test_sign_in_throttling_cannot_globally_lock_out_an_identifier(): void
    {
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
            ->assertOk();
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
}
