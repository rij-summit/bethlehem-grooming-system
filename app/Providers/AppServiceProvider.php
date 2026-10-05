<?php

namespace App\Providers;

use Carbon\Carbon as BaseCarbon;
use Illuminate\Support\Carbon;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (['registration-verify' => [10, 1], 'registration-resend' => [3, 10]] as $name => [$attempts, $minutes]) {
            RateLimiter::for($name, fn ($request) => Limit::perMinutes($minutes, $attempts)
                ->by($request->ip())
                ->response(fn ($request, $headers) => response()->json([
                    'success' => false,
                    'message' => $name === 'registration-resend'
                        ? 'Please wait before requesting another code.'
                        : 'Too many attempts. Please request a new verification code.',
                ], 429, $headers)));
        }

        $testNow = config('app.test_now');

        if (! $this->app->environment(['local', 'testing']) || blank($testNow)) {
            return;
        }

        try {
            $now = Carbon::parse($testNow, config('app.timezone', 'Asia/Manila'));

            Carbon::setTestNow($now);
            BaseCarbon::setTestNow($now);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
