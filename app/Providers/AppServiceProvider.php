<?php

namespace App\Providers;

use Carbon\Carbon as BaseCarbon;
use Illuminate\Support\Carbon;
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
