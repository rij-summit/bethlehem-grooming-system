<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;

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

    // Booking
    Route::post('/booking/store',    [BookingController::class, 'store']);
    Route::get('/booking/history',   [BookingController::class, 'history']);
    Route::get('/booking/{id}',      [BookingController::class, 'show']);
    Route::post('/booking/cancel',   [BookingController::class, 'cancel']);
});