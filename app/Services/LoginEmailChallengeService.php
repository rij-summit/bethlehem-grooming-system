<?php

namespace App\Services;

use App\Http\Controllers\EmailVerificationController;
use App\Models\LoginEmailChallenge;
use App\Models\User;
use App\Notifications\ConfirmLoginNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class LoginEmailChallengeService
{
    public function send(User $user, bool $rememberMe): string
    {
        EmailVerificationController::assertMailCanBeDelivered();

        $plainPollToken = Str::random(64);
        $pollTokenHash = hash('sha256', $plainPollToken);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $tokenHash = hash_hmac('sha256', $code, $plainPollToken);
        $previousChallenge = DB::transaction(function () use (
            $user,
            $rememberMe,
            $tokenHash,
            $pollTokenHash,
        ): ?array {
            $lockedUser = User::query()
                ->lockForUpdate()
                ->findOrFail($user->getKey());

            if ($lockedUser->role !== 'customer'
                || ! $lockedUser->email_verified_at
                || ! $lockedUser->is_active
                || $lockedUser->is_archived) {
                throw new \RuntimeException('This account is not eligible for login confirmation.');
            }

            $challenge = LoginEmailChallenge::query()
                ->where('user_id', $lockedUser->user_id)
                ->lockForUpdate()
                ->first();
            $previous = $challenge?->only([
                'token_hash',
                'poll_token_hash',
                'remember_me',
                'expires_at',
                'approved_at',
            ]);

            LoginEmailChallenge::query()->updateOrCreate(
                ['user_id' => $lockedUser->user_id],
                [
                    'token_hash' => $tokenHash,
                    'poll_token_hash' => $pollTokenHash,
                    'remember_me' => $rememberMe,
                    'expires_at' => now()->addMinutes(max(
                        1,
                        (int) config('app.login_confirmation_ttl_minutes', 15),
                    )),
                    'approved_at' => null,
                ],
            );

            return $previous;
        });

        try {
            $user->notify(new ConfirmLoginNotification($code));
        } catch (Throwable $exception) {
            DB::transaction(function () use ($user, $tokenHash, $previousChallenge): void {
                $challenge = LoginEmailChallenge::query()
                    ->where('user_id', $user->user_id)
                    ->where('token_hash', $tokenHash)
                    ->lockForUpdate()
                    ->first();

                if (! $challenge) {
                    return;
                }

                if ($previousChallenge) {
                    $challenge->update($previousChallenge);
                } else {
                    $challenge->delete();
                }
            });

            throw $exception;
        }

        return $plainPollToken;
    }
}
