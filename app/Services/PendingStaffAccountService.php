<?php

namespace App\Services;

use App\Http\Controllers\EmailVerificationController;
use App\Models\PendingCustomerRegistration;
use App\Models\PendingStaffAccount;
use App\Models\User;
use App\Notifications\VerifyStaffAccountEmailNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PendingStaffAccountService
{
    private const MAX_CODE_ATTEMPTS = 5;

    private const RESEND_COOLDOWN_SECONDS = 60;

    public function request(
        User $requester,
        string $staffType,
        string $username,
        string $email,
        string $password,
    ): array {
        $this->assertAdminIsEligible($requester);
        $normalizedUsername = trim($username);
        $normalizedEmail = Str::lower(trim($email));
        $this->assertEmailIsAvailable($normalizedEmail);
        $this->assertUsernameIsAvailable($normalizedUsername, $normalizedEmail);
        EmailVerificationController::assertMailCanBeDelivered();

        $plainCode = $this->generateCode();
        $pending = DB::transaction(function () use (
            $requester,
            $staffType,
            $normalizedUsername,
            $normalizedEmail,
            $password,
            $plainCode,
        ): PendingStaffAccount {
            PendingStaffAccount::query()
                ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
                ->delete();

            return PendingStaffAccount::query()->create([
                'requested_by_user_id' => $requester->user_id,
                'staff_type' => $staffType,
                'username' => $normalizedUsername,
                'email' => $normalizedEmail,
                'password_hash' => Hash::make($password),
                'code_hash' => Hash::make($plainCode),
                'failed_attempts' => 0,
                'expires_at' => now()->addMinutes($this->ttlMinutes()),
                'last_sent_at' => now(),
            ]);
        });

        try {
            $this->sendCode($pending, $plainCode);
        } catch (Throwable $exception) {
            $pending->delete();
            throw $exception;
        }

        return $this->challengePayload($pending);
    }

    public function confirm(
        User $requester,
        PendingStaffAccount $requestedAccount,
        string $plainCode,
    ): array {
        return DB::transaction(function () use ($requester, $requestedAccount, $plainCode): array {
            $pending = PendingStaffAccount::query()
                ->whereKey($requestedAccount->getKey())
                ->lockForUpdate()
                ->first();

            if (! $pending || $pending->requested_by_user_id !== $requester->user_id) {
                return ['status' => 'invalid'];
            }

            if (now()->isAfter($pending->expires_at)) {
                $pending->delete();

                return ['status' => 'expired'];
            }

            if (! Hash::check($plainCode, $pending->code_hash)) {
                $failedAttempts = $pending->failed_attempts + 1;
                if ($failedAttempts >= self::MAX_CODE_ATTEMPTS) {
                    $pending->delete();

                    return ['status' => 'locked', 'attempts_remaining' => 0];
                }

                $pending->update(['failed_attempts' => $failedAttempts]);

                return [
                    'status' => 'invalid_code',
                    'attempts_remaining' => self::MAX_CODE_ATTEMPTS - $failedAttempts,
                ];
            }

            if (! $this->adminIsEligible($requester) || ! $this->emailIsAvailable($pending->email)) {
                $pending->delete();

                return ['status' => 'email_unavailable'];
            }
            if (! $pending->username || ! $this->usernameIsAvailable($pending->username, $pending->email)) {
                $pending->delete();

                return ['status' => 'username_unavailable'];
            }

            $label = $this->staffLabel($pending->staff_type);
            [$firstName, $lastName] = explode(' ', $label, 2);
            $staff = User::query()->create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'username' => $pending->username,
                'email' => $pending->email,
                'phone' => null,
                'password_hash' => $pending->password_hash,
                'role' => 'staff',
                'staff_type' => $pending->staff_type,
                'customer_tier' => 'new',
                'is_active' => true,
                'is_archived' => false,
                'email_verified_at' => now(),
            ]);
            $pending->delete();

            return [
                'status' => 'created',
                'staff' => $staff,
                'staff_label' => $label,
            ];
        });
    }

    public function resend(User $requester, PendingStaffAccount $requestedAccount): array
    {
        $this->assertAdminIsEligible($requester);
        EmailVerificationController::assertMailCanBeDelivered();

        return DB::transaction(function () use ($requester, $requestedAccount): array {
            $pending = PendingStaffAccount::query()
                ->whereKey($requestedAccount->getKey())
                ->lockForUpdate()
                ->first();
            if (! $pending || $pending->requested_by_user_id !== $requester->user_id) {
                return ['status' => 'invalid'];
            }

            if (! $this->emailIsAvailable($pending->email)
                || ! $pending->username
                || ! $this->usernameIsAvailable($pending->username, $pending->email)) {
                $pending->delete();

                return ['status' => 'invalid'];
            }

            $availableAt = $pending->last_sent_at->copy()->addSeconds(self::RESEND_COOLDOWN_SECONDS);
            if (now()->isBefore($availableAt)) {
                return [
                    'status' => 'cooldown',
                    'retry_after' => max(1, (int) ceil(now()->diffInSeconds($availableAt))),
                ];
            }

            $plainCode = $this->generateCode();
            $pending->update([
                'code_hash' => Hash::make($plainCode),
                'failed_attempts' => 0,
                'expires_at' => now()->addMinutes($this->ttlMinutes()),
                'last_sent_at' => now(),
            ]);
            $this->sendCode($pending, $plainCode);

            return ['status' => 'resent'] + $this->challengePayload($pending);
        });
    }

    private function sendCode(PendingStaffAccount $pending, string $plainCode): void
    {
        Notification::route('mail', $pending->email)->notify(
            new VerifyStaffAccountEmailNotification(
                $plainCode,
                $this->staffLabel($pending->staff_type),
            ),
        );
    }

    private function challengePayload(PendingStaffAccount $pending): array
    {
        $staffLabel = $this->staffLabel($pending->staff_type);

        return [
            'pending_staff_id' => $pending->getKey(),
            'purpose' => "Verify {$staffLabel} email",
            'staff_label' => $staffLabel,
            'confirmation_email' => $this->maskEmail($pending->email),
            'expires_at' => $pending->expires_at->toIso8601String(),
        ];
    }

    private function assertEmailIsAvailable(string $email): void
    {
        if (! $this->emailIsAvailable($email)) {
            throw ValidationException::withMessages([
                'email' => 'That email address is already in use.',
            ]);
        }
    }

    private function assertUsernameIsAvailable(string $username, string $email): void
    {
        if (! $this->usernameIsAvailable($username, $email)) {
            throw ValidationException::withMessages([
                'username' => 'That username is already in use.',
            ]);
        }
    }

    private function usernameIsAvailable(string $username, string $email): bool
    {
        $normalizedUsername = Str::lower($username);
        if (User::query()->whereRaw('LOWER(username) = ?', [$normalizedUsername])->exists()) {
            return false;
        }

        if (PendingCustomerRegistration::query()
            ->whereRaw('LOWER(username) = ?', [$normalizedUsername])
            ->exists()) {
            return false;
        }

        return ! PendingStaffAccount::query()
            ->whereRaw('LOWER(username) = ?', [$normalizedUsername])
            ->whereRaw('LOWER(email) <> ?', [Str::lower($email)])
            ->exists();
    }

    private function emailIsAvailable(string $email): bool
    {
        if (User::query()->whereRaw('LOWER(email) = ?', [Str::lower($email)])->exists()) {
            return false;
        }

        return ! PendingCustomerRegistration::query()
            ->whereRaw('LOWER(email) = ?', [Str::lower($email)])
            ->exists();
    }

    private function assertAdminIsEligible(User $admin): void
    {
        if (! $this->adminIsEligible($admin)) {
            throw ValidationException::withMessages([
                'account' => 'This administrator account cannot create staff accounts.',
            ]);
        }
    }

    private function adminIsEligible(User $admin): bool
    {
        return $admin->role === 'admin' && $admin->is_active && ! $admin->is_archived;
    }

    private function staffLabel(string $staffType): string
    {
        return $staffType === 'clinic' ? 'Clinic Staff' : 'Grooming Staff';
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function ttlMinutes(): int
    {
        return max(
            1,
            (int) config('app.privileged_credential_change_code_ttl_minutes', 10),
        );
    }

    private function maskEmail(string $email): string
    {
        [$localPart, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $visible = mb_substr($localPart, 0, 1);

        return $visible.str_repeat('*', max(3, mb_strlen($localPart) - 1)).'@'.$domain;
    }
}
