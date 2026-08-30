<?php

namespace App\Providers;

use Carbon\Carbon as BaseCarbon;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
        RateLimiter::for('chatbot', function (Request $request) {
            $identity = $request->user()?->getAuthIdentifier() ?: $request->ip();

            return Limit::perMinute(15)
                ->by('chatbot:'.$identity)
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Too many chatbot messages. Please wait a moment and try again.',
                    'retry_after' => (int) ($headers['Retry-After'] ?? 60),
                ], 429, $headers));
        });

        RateLimiter::for('chatbot-feedback', function (Request $request) {
            return Limit::perMinute(30)->by('chatbot-feedback:'.$request->ip());
        });

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
