<?php

namespace App\Services;

use App\Jobs\Middleware\TrackAuthenticationMailDelivery;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class AuthenticationMailDelivery
{
    public static function queue(object $recipient, Notification $notification): string
    {
        $receipt = Str::random(64);
        self::record($receipt, 'queued');
        $notification->through([new TrackAuthenticationMailDelivery($receipt)]);

        try {
            $recipient->notify($notification);
        } catch (Throwable $exception) {
            Cache::forget(self::key($receipt));
            throw $exception;
        }

        return $receipt;
    }

    public static function record(string $receipt, string $status): void
    {
        Cache::put(self::key($receipt), $status, now()->addMinutes(max(
            15, (int) config('app.login_confirmation_ttl_minutes', 15),
        ) + 5));
    }

    public static function status(string $receipt): string
    {
        return Cache::get(self::key($receipt), 'unavailable');
    }

    private static function key(string $receipt): string
    {
        return 'auth-mail-delivery:'.hash('sha256', $receipt);
    }
}
