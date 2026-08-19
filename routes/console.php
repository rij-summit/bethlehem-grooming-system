<?php

use App\Models\PendingCustomerRegistration;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Flag no-show bookings every 5 minutes
Schedule::command('bookings:flag-no-shows')->everyFiveMinutes();

// Send 24-hour and 3-hour appointment reminders every 30 minutes
Schedule::command('reminders:send')->everyThirtyMinutes();

// Send hourly pickup reminders for released bookings
Schedule::command('pickups:remind')->hourly();

// Repair safe workflow drift and surface unresolved stopped-grooming reviews.
Schedule::command('bookings:reconcile-workflows')
    ->dailyAt('06:00')
    ->withoutOverlapping();

// Remove expired bearer-token rows after their configured lifetime.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Pending signups are not customer accounts and are retained only long enough
// for email verification and resend recovery.
Schedule::call(function (): void {
    PendingCustomerRegistration::query()
        ->where('updated_at', '<', now()->subDays(max(
            1,
            (int) config('app.pending_registration_retention_days', 7),
        )))
        ->delete();
})
    ->name('pending-customer-registrations:prune')
    ->daily()
    ->withoutOverlapping();
