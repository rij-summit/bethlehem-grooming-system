<?php

namespace App\Services;

use App\Http\Controllers\EmailVerificationController;
use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Notifications\PasswordResetLinkNotification;
use App\Notifications\SetUpStaffPasswordNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

class PasswordResetService
{
    public function sendLink(string $email): void
    {
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [Str::lower($email)])
            ->first();

        if (! $user || ! $this->userIsEligible($user) || $user->requiresPasswordSetup()) {
            return;
        }

        EmailVerificationController::assertMailCanBeDelivered();

        $plainToken = Str::random(64);
        $tokenHash = hash('sha256', $plainToken);
        $previous = DB::transaction(function () use ($user, $tokenHash): ?array {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            if (! $lockedUser || ! $this->userIsEligible($lockedUser) || $lockedUser->requiresPasswordSetup()) {
                return null;
            }

            $existing = PasswordResetRequest::query()
                ->where('user_id', $lockedUser->getKey())
                ->lockForUpdate()
                ->first();
            $previousState = $existing?->only([
                'token_hash',
                'expires_at',
                'last_sent_at',
            ]);

            PasswordResetRequest::query()->updateOrCreate(
                ['user_id' => $lockedUser->getKey()],
                [
                    'token_hash' => $tokenHash,
                    'expires_at' => now()->addMinutes($this->ttlMinutes()),
                    'last_sent_at' => now(),
                ],
            );

            return [
                'had_existing_request' => $existing !== null,
                'state' => $previousState,
            ];
        });

        if ($previous === null) {
            return;
        }

        $frontendUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $resetUrl = $frontendUrl
            .'/pages/client/reset-password.html#token='.rawurlencode($plainToken);

        try {
            $user->notify(new PasswordResetLinkNotification($resetUrl));
        } catch (Throwable $exception) {
            DB::transaction(function () use ($user, $tokenHash, $previous): void {
                $request = PasswordResetRequest::query()
                    ->where('user_id', $user->getKey())
                    ->where('token_hash', $tokenHash)
                    ->lockForUpdate()
                    ->first();
                if (! $request) {
                    return;
                }

                if ($previous['had_existing_request']) {
                    $request->update($previous['state']);
                } else {
                    $request->delete();
                }
            });
            throw $exception;
        }
    }

    public function inspect(string $plainToken): array
    {
        $request = PasswordResetRequest::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();
        $user = $request?->user;

        if (! $request
            || now()->isAfter($request->expires_at)
            || ! $user
            || ! $this->userIsEligible($user)
            || $user->requiresPasswordSetup()) {
            return ['status' => 'invalid'];
        }

        return [
            'status' => 'valid',
            'email' => $this->maskEmail($user->email),
        ];
    }

    public function reset(string $plainToken, string $newPassword): array
    {
        return DB::transaction(function () use ($plainToken, $newPassword): array {
            $request = PasswordResetRequest::query()
                ->where('token_hash', hash('sha256', $plainToken))
                ->lockForUpdate()
                ->first();

            if (! $request) {
                return ['status' => 'invalid'];
            }

            $user = User::query()->whereKey($request->user_id)->lockForUpdate()->first();
            if ($user?->requiresPasswordSetup()) {
                return ['status' => 'invalid'];
            }

            if (now()->isAfter($request->expires_at)) {
                $request->delete();

                return ['status' => 'expired'];
            }

            if (! $user || ! $this->userIsEligible($user)) {
                $request->delete();

                return ['status' => 'invalid'];
            }

            if (Hash::check($newPassword, $user->password_hash)) {
                return ['status' => 'same_password'];
            }

            $user->password_hash = Hash::make($newPassword);
            $user->save();
            $user->tokens()->delete();
            $request->delete();

            return [
                'status' => 'reset',
                'completed_setup' => false,
                'user' => $user,
            ];
        });
    }

    public function inspectStaffSetup(string $plainToken): array
    {
        $request = PasswordResetRequest::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();
        $user = $request?->user;

        if (! $request || ! $user || ! $this->staffSetupIsEligible($user)) {
            return ['status' => 'invalid'];
        }

        if (now()->isAfter($request->expires_at)) {
            return ['status' => 'expired'];
        }

        return [
            'status' => 'valid',
            'email' => $this->maskEmail($user->email),
        ];
    }

    public function completeStaffSetup(string $plainToken, string $newPassword): array
    {
        return DB::transaction(function () use ($plainToken, $newPassword): array {
            $request = PasswordResetRequest::query()
                ->where('token_hash', hash('sha256', $plainToken))
                ->lockForUpdate()
                ->first();
            if (! $request) {
                return ['status' => 'invalid'];
            }

            $user = User::query()->whereKey($request->user_id)->lockForUpdate()->first();
            if (! $user || ! $this->staffSetupIsEligible($user)) {
                return ['status' => 'invalid'];
            }

            if (now()->isAfter($request->expires_at)) {
                return ['status' => 'expired'];
            }

            $user->password_hash = Hash::make($newPassword);
            $user->save();
            $user->tokens()->delete();
            $request->delete();

            return [
                'status' => 'completed',
                'user' => $user,
            ];
        });
    }

    public function resendExpiredStaffSetupLink(string $expiredPlainToken): array
    {
        EmailVerificationController::assertMailCanBeDelivered();

        $plainToken = Str::random(64);
        $tokenHash = hash('sha256', $plainToken);
        $result = DB::transaction(function () use ($expiredPlainToken, $tokenHash): array {
            $request = PasswordResetRequest::query()
                ->where('token_hash', hash('sha256', $expiredPlainToken))
                ->lockForUpdate()
                ->first();
            if (! $request) {
                return ['status' => 'invalid'];
            }

            $user = User::query()->whereKey($request->user_id)->lockForUpdate()->first();
            if (! $user || ! $this->staffSetupIsEligible($user)) {
                return ['status' => 'invalid'];
            }

            if (! now()->isAfter($request->expires_at)) {
                return ['status' => 'not_expired'];
            }

            $previousState = $request->only(['token_hash', 'expires_at', 'last_sent_at']);
            $request->update([
                'token_hash' => $tokenHash,
                'expires_at' => now()->addHours(24),
                'last_sent_at' => now(),
            ]);

            return [
                'status' => 'sent',
                'user' => $user,
                'previous_state' => $previousState,
            ];
        });

        if ($result['status'] !== 'sent') {
            return $result;
        }

        $frontendUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $setupUrl = $frontendUrl
            .'/pages/client/set-up-password.html#token='.rawurlencode($plainToken);

        try {
            $result['user']->notify(new SetUpStaffPasswordNotification($setupUrl));
        } catch (Throwable $exception) {
            DB::transaction(function () use ($result, $tokenHash): void {
                PasswordResetRequest::query()
                    ->where('user_id', $result['user']->getKey())
                    ->where('token_hash', $tokenHash)
                    ->lockForUpdate()
                    ->first()
                    ?->update($result['previous_state']);
            });
            throw $exception;
        }

        return ['status' => 'sent'];
    }

    private function userIsEligible(User $user): bool
    {
        return (bool) $user->is_active
            && ! (bool) $user->is_archived
            && $user->account_deleted_at === null
            && in_array($user->role, ['customer', 'staff', 'admin'], true);
    }

    private function staffSetupIsEligible(User $user): bool
    {
        return $this->userIsEligible($user)
            && $user->role === 'staff'
            && $user->requiresPasswordSetup();
    }

    private function ttlMinutes(): int
    {
        return max(1, (int) config('auth.passwords.users.expire', 15));
    }

    private function maskEmail(string $email): string
    {
        [$localPart, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($domain === '') {
            return 'your email address';
        }

        $visible = mb_substr($localPart, 0, 1);
        $maskedLength = max(3, mb_strlen($localPart) - 1);

        return $visible.str_repeat('*', $maskedLength).'@'.$domain;
    }
}
