<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\AdminBookingController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ClinicClosureController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\CustomerNotificationController;
use App\Http\Controllers\PetController;
use App\Http\Controllers\ReportController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/sign-in',  [AuthController::class, 'signIn']);

// ── PUBLIC ROUTES ─────────────────────────────────────
Route::get('/timeslots',      [BookingController::class,   'getTimeslots']);
Route::get('/clinic/status',  [ClinicClosureController::class, 'status']);

// ── PROTECTED ROUTES (token required) ────────────────
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me',      [AuthController::class, 'me']);

    // Pets
    Route::get('/pets',                    [PetController::class, 'index']);
    Route::post('/pets',                   [PetController::class, 'store']);
    Route::put('/pets/{id}',               [PetController::class, 'update']);
    Route::post('/pets/{id}/archive',      [PetController::class, 'archive']);
    Route::post('/pets/{id}/unarchive',    [PetController::class, 'unarchive']);

    // Booking
    Route::post('/booking/store',      [BookingController::class, 'store']);
    Route::get('/booking/history',     [BookingController::class, 'history']);
    Route::get('/booking/grooming-capacity', [BookingController::class, 'groomingCapacity']);
    Route::get('/booking/{id}',        [BookingController::class, 'show']);
    Route::post('/booking/cancel',     [BookingController::class, 'cancel']);
    Route::post('/booking/reschedule', [BookingController::class, 'reschedule']);

    // Admin — Customer management (staff can view, admin can manage)
    Route::get('/admin/customers',                          [CustomerController::class, 'index']);
    Route::get('/admin/customers/{id}',                      [CustomerController::class, 'show']);
    Route::post('/admin/customers/{id}/deactivate',         [CustomerController::class, 'deactivate']);
    Route::post('/admin/customers/{id}/reactivate',         [CustomerController::class, 'reactivate']);
    Route::post('/admin/customers/{id}/archive',            [CustomerController::class, 'archive']);
    Route::post('/admin/customers/{id}/unarchive',          [CustomerController::class, 'unarchive']);

    // Admin — Notifications
    Route::get('/admin/notifications',              [NotificationController::class, 'index']);
    Route::patch('/admin/notifications/read-all',   [NotificationController::class, 'markAllRead']);
    Route::patch('/admin/notifications/{id}/read',  [NotificationController::class, 'markRead']);

    // Customer — Notifications
    Route::get('/customer/notifications',              [CustomerNotificationController::class, 'index']);
    Route::patch('/customer/notifications/read-all',   [CustomerNotificationController::class, 'markAllRead']);
    Route::patch('/customer/notifications/{id}/read',  [CustomerNotificationController::class, 'markRead']);

    // Admin — Booking management
    Route::get('/admin/bookings',                          [AdminBookingController::class, 'index']);
    Route::get('/admin/bookings/archived',                 [AdminBookingController::class, 'archivedIndex']);
    Route::post('/admin/bookings/{id}/check-in',           [AdminBookingController::class, 'checkIn']);
    Route::post('/admin/bookings/{id}/start-grooming',     [AdminBookingController::class, 'startGrooming']);
    Route::post('/admin/bookings/{id}/mark-done',          [AdminBookingController::class, 'markDone']);
    Route::post('/admin/bookings/{id}/archive',            [AdminBookingController::class, 'archive']);
    Route::post('/admin/bookings/{id}/picked-up',          [AdminBookingController::class, 'markPickedUp']);
    Route::post('/admin/bookings/{id}/late-check-in',      [AdminBookingController::class, 'lateCheckIn']);

    // Admin — No-show list
    Route::get('/admin/bookings/no-shows',                 [AdminBookingController::class, 'noShowIndex']);

    // Admin — Payments
    Route::post('/admin/bookings/{id}/pay',                [PaymentController::class, 'store']);
    Route::post('/admin/bookings/{id}/pay-now',            [PaymentController::class, 'payNow']);
    Route::post('/admin/bookings/{id}/release',            [PaymentController::class, 'release']);
    Route::get('/admin/transactions',                      [PaymentController::class, 'index']);
    Route::get('/admin/reports/services-performed',         [ReportController::class, 'servicesPerformed']);
    Route::get('/admin/reports/customer-activity',          [ReportController::class, 'customerActivity']);

    // Admin — Clinic closures
    Route::post('/admin/clinic/stop-today',                [ClinicClosureController::class, 'stopToday']);
    Route::post('/admin/clinic/reopen-today',              [ClinicClosureController::class, 'reopenToday']);
    Route::get('/admin/clinic/blocked-dates',              [ClinicClosureController::class, 'blockedDates']);
    Route::post('/admin/clinic/blocked-dates',             [ClinicClosureController::class, 'addBlockedDate']);
    Route::delete('/admin/clinic/blocked-dates/{id}',      [ClinicClosureController::class, 'removeBlockedDate']);
});
