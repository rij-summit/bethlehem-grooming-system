<?php

namespace App\Http\Controllers;

use App\Models\PendingCustomerRegistration;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class EmailVerificationController extends Controller
{
    // POST /api/email/verify  { token: "..." }
    public function verify(Request $request)
    {
        $data = $request->validate(['token' => 'required|string|size:64']);
        $plainToken = $data['token'];
        $tokenHash = hash('sha256', $plainToken);

        return DB::transaction(function () use ($plainToken, $tokenHash) {
            $pendingRegistration = PendingCustomerRegistration::query()
                ->where(function ($query) use ($plainToken, $tokenHash) {
                    $query->where('email_verification_token', $tokenHash)
                        ->orWhere('email_verification_token', $plainToken);
                })
                ->lockForUpdate()
                ->first();

            if ($pendingRegistration) {
                if ($pendingRegistration->email_verification_expires_at
                    && now()->isAfter($pendingRegistration->email_verification_expires_at)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This verification link has expired. Please request a new one.',
                        'expired' => true,
                        'email' => $pendingRegistration->email,
                    ], 422);
                }

                $conflictingUser = User::query()
                    ->where(function ($query) use ($pendingRegistration) {
                        $query->where('email', $pendingRegistration->email)
                            ->orWhere('phone', $pendingRegistration->phone);

                        if ($pendingRegistration->username) {
                            $query->orWhere('username', $pendingRegistration->username);
                        }
                    })
                    ->lockForUpdate()
                    ->exists();

                if ($conflictingUser) {
                    $pendingRegistration->delete();

                    return response()->json([
                        'success' => false,
                        'message' => 'These registration details are no longer available. Please register again.',
                    ], 422);
                }

                $user = User::create([
                    'first_name' => $pendingRegistration->first_name,
                    'last_name' => $pendingRegistration->last_name,
                    'username' => $pendingRegistration->username,
                    'email' => $pendingRegistration->email,
                    'phone' => $pendingRegistration->phone,
                    'password_hash' => $pendingRegistration->password_hash,
                    'role' => 'customer',
                    'customer_tier' => 'new',
                    'is_active' => true,
                    'is_archived' => false,
                    'email_verified_at' => now(),
                ]);
                $pendingRegistration->delete();

                $token = $user->createToken('auth_token')->plainTextToken;

                return response()->json([
                    'success' => true,
                    'message' => 'Email verified and account registered successfully.',
                    'token' => $token,
                    'user' => self::customerPayload($user),
                ]);
            }

            // Keep first-release links working while all newly-issued tokens
            // and already-issued unverified-user links finish their upgrade.
            $user = User::query()
                ->whereNull('email_verified_at')
                ->where(function ($query) use ($plainToken, $tokenHash) {
                    $query->where('email_verification_token', $tokenHash)
                        ->orWhere('email_verification_token', $plainToken);
                })
                ->lockForUpdate()
                ->first();

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or already-used verification link.',
                ], 422);
            }

            if (! $user->is_active || $user->is_archived) {
                return response()->json([
                    'success' => false,
                    'code' => 'account_disabled',
                    'message' => 'This account is disabled and cannot be verified. Please contact the clinic.',
                ], 403);
            }

            if ($user->email_verification_expires_at && now()->isAfter($user->email_verification_expires_at)) {
                return response()->json([
                    'success' => false,
                    'message' => 'This verification link has expired. Please request a new one.',
                    'expired' => true,
                    'email' => $user->email,
                ], 422);
            }

            $user->update([
                'email_verified_at' => now(),
                'email_verification_token' => null,
                'email_verification_expires_at' => null,
            ]);

            $response = [
                'success' => true,
                'message' => 'Email verified successfully.',
            ];

            if ($user->role === 'customer') {
                $response['token'] = $user->createToken('auth_token')->plainTextToken;
                $response['user'] = self::customerPayload($user);
            }

            return response()->json($response);
        });
    }

    // POST /api/email/resend  { email: "..." }
    public function resend(Request $request)
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);
        $data = $request->validate(['email' => 'required|email']);

        $registrant = PendingCustomerRegistration::query()
            ->where('email', $data['email'])
            ->first();

        $registrant ??= User::query()
            ->where('email', $data['email'])
            ->whereNull('email_verified_at')
            ->where('is_active', true)
            ->where('is_archived', false)
            ->first();

        if ($registrant) {
            try {
                self::sendVerificationEmail($registrant);
            } catch (Throwable $exception) {
                report($exception);

                return response()->json([
                    'success' => false,
                    'message' => 'Verification email could not be queued right now. Please try again later.',
                ], 503);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'If that email is registered and unverified, a new verification link has been sent.',
        ]);
    }

    // Shared helper used by register(), resend(), and legacy unverified users.
    // Only a SHA-256 digest is persisted, so a database read cannot be used as
    // a verification link.
    public static function sendVerificationEmail(
        User|PendingCustomerRegistration $registrant,
    ): void {
        self::assertMailCanBeDelivered();

        $plainToken = Str::random(64);
        $tokenHash = hash('sha256', $plainToken);
        $previousVerification = DB::transaction(function () use ($registrant, $tokenHash) {
            $lockedRegistrant = $registrant->newQuery()
                ->lockForUpdate()
                ->findOrFail($registrant->getKey());

            if ($lockedRegistrant instanceof User
                && ($lockedRegistrant->email_verified_at
                    || ! $lockedRegistrant->is_active
                    || $lockedRegistrant->is_archived)) {
                throw new \RuntimeException('This account is not eligible for a verification email.');
            }

            $previous = $lockedRegistrant->only([
                'email_verification_token',
                'email_verification_expires_at',
            ]);

            $lockedRegistrant->update([
                'email_verification_token' => $tokenHash,
                'email_verification_expires_at' => now()->addHours(
                    (int) config('app.email_verification_ttl_hours', 24),
                ),
            ]);

            return $previous;
        });
        $registrant->refresh();

        $frontendUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $verificationUrl = $frontendUrl
            .'/pages/client/verify-email.html#token='.rawurlencode($plainToken);

        try {
            $registrant->notify(new VerifyEmailNotification($verificationUrl));
        } catch (Throwable $exception) {
            // If queueing fails, keep the previous link usable. Delivery
            // failures after queueing are retried by the notification job.
            $registrant->newQuery()
                ->whereKey($registrant->getKey())
                ->where('email_verification_token', $tokenHash)
                ->update($previousVerification);
            throw $exception;
        }
    }

    public static function assertMailCanBeDelivered(): void
    {
        if (app()->environment(['local', 'testing'])) {
            return;
        }

        $mailer = (string) config('mail.default');
        $frontendUrl = (string) config('app.frontend_url');
        $fromAddress = Str::lower((string) config('mail.from.address'));
        $smtpHost = Str::lower((string) config('mail.mailers.smtp.host'));

        $invalidMailer = in_array($mailer, ['log', 'array'], true);
        $invalidFrontendUrl = parse_url($frontendUrl, PHP_URL_SCHEME) !== 'https';
        $placeholderSender = $fromAddress === ''
            || str_ends_with($fromAddress, '@example.com');
        $localSmtp = $mailer === 'smtp'
            && in_array($smtpHost, ['', '127.0.0.1', 'localhost'], true);

        if ($invalidMailer || $invalidFrontendUrl || $placeholderSender || $localSmtp) {
            throw new \RuntimeException(
                'Deployed email verification requires a deliverable mailer, HTTPS FRONTEND_URL, and verified sender.',
            );
        }
    }

    private static function customerPayload(User $user): array
    {
        return [
            'user_id' => $user->user_id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
        ];
    }
}
