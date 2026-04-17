<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\AdminBookingController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\CustomerController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/admin/login', [AuthController::class, 'adminLogin']);

// ── PUBLIC BOOKING ROUTES ─────────────────────────────
Route::get('/timeslots', [BookingController::class, 'getTimeslots']);

// ── PROTECTED ROUTES (token required) ────────────────
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me',      [AuthController::class, 'me']);

    // Pets
    Route::get('/pets', [BookingController::class, 'getPets']);

    // Booking
    Route::post('/booking/store',      [BookingController::class, 'store']);
    Route::get('/booking/history',     [BookingController::class, 'history']);
    Route::get('/booking/{id}',        [BookingController::class, 'show']);
    Route::post('/booking/cancel',     [BookingController::class, 'cancel']);
    Route::post('/booking/reschedule', [BookingController::class, 'reschedule']);

    // Admin — Customer management (admin role only)
    Route::get('/admin/customers',                          [CustomerController::class, 'index']);
    Route::post('/admin/customers/{id}/deactivate',         [CustomerController::class, 'deactivate']);
    Route::post('/admin/customers/{id}/reactivate',         [CustomerController::class, 'reactivate']);
    Route::post('/admin/customers/{id}/archive',            [CustomerController::class, 'archive']);
    Route::post('/admin/customers/{id}/unarchive',          [CustomerController::class, 'unarchive']);

    // Admin — Notifications
    Route::get('/admin/notifications',              [NotificationController::class, 'index']);
    Route::patch('/admin/notifications/read-all',   [NotificationController::class, 'markAllRead']);
    Route::patch('/admin/notifications/{id}/read',  [NotificationController::class, 'markRead']);

    // Admin — Booking management
    Route::get('/admin/bookings',                          [AdminBookingController::class, 'index']);
    Route::get('/admin/bookings/archived',                 [AdminBookingController::class, 'archivedIndex']);
    Route::post('/admin/bookings/{id}/check-in',           [AdminBookingController::class, 'checkIn']);
    Route::post('/admin/bookings/{id}/start-grooming',     [AdminBookingController::class, 'startGrooming']);
    Route::post('/admin/bookings/{id}/mark-done',          [AdminBookingController::class, 'markDone']);
    Route::post('/admin/bookings/{id}/archive',            [AdminBookingController::class, 'archive']);
});