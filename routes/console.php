<?php

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
