<?php

namespace App\Jobs\Middleware;

use App\Http\Controllers\EmailVerificationController;
use App\Services\AuthenticationMailDelivery;
use Throwable;

class TrackAuthenticationMailDelivery
{
    public function __construct(public readonly string $receipt) {}

    public function handle(object $job, callable $next): void
    {
        try {
            // The worker must use the same deliverable configuration as the web app.
            EmailVerificationController::assertMailCanBeDelivered();
            $next($job);
            AuthenticationMailDelivery::record($this->receipt, 'sent');
        } catch (Throwable $exception) {
            AuthenticationMailDelivery::record($this->receipt, 'failed');
            // Laravel retains the notification's existing attempts and backoff.
            throw $exception;
        }
    }
}
