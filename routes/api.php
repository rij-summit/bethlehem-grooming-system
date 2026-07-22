<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\AdminBookingController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ClinicClosureController;
use App\Http\Controllers\ClinicSettingController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\CustomerNotificationController;
use App\Http\Controllers\PetController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\WalkinController;
use App\Http\Controllers\ClinicWalkinController;
use App\Http\Controllers\AdminClinicController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ChatbotController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/sign-in',  [AuthController::class, 'signIn']);
Route::post('/chatbot', [ChatbotController::class, 'chat',]);

// ── PUBLIC ROUTES ─────────────────────────────────────
Route::get('/timeslots',      [BookingController::class,   'getTimeslots']);
Route::get('/clinic/status',  [ClinicClosureController::class, 'status']);
Route::get('/system/clock', function () {
    $testClockActive = app()->environment(['local', 'testing']) && filled(config('app.test_now'));

    return response()->json([
        'success' => true,
        'now' => now()->toIso8601String(),
        'today' => now()->toDateString(),
        'timezone' => config('app.timezone'),
        'test_clock_active' => $testClockActive,
    ]);
});

// ── PROTECTED ROUTES (token required) ────────────────
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me',      [AuthController::class, 'me']);

    // Pets
    Route::get('/pets',                    [PetController::class, 'index']);
    Route::get('/pets/{id}',               [PetController::class, 'show']);
    Route::post('/pets',                   [PetController::class, 'store']);
    Route::put('/pets/{id}',               [PetController::class, 'update']);
    Route::post('/pets/{id}/archive',      [PetController::class, 'archive']);
    Route::post('/pets/{id}/unarchive',    [PetController::class, 'unarchive']);

    // Admin — Pet management (update any customer's pet)
    Route::put('/admin/pets/{id}',         [PetController::class, 'adminUpdate']);

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
    Route::post('/admin/bookings/{id}/pets/{bookingPetId}/start-grooming', [AdminBookingController::class, 'startPetGrooming']);
    Route::post('/admin/bookings/{id}/pets/{bookingPetId}/mark-done', [AdminBookingController::class, 'markPetDone']);
    Route::post('/admin/bookings/{id}/mark-done',          [AdminBookingController::class, 'markDone']);
    Route::post('/admin/bookings/{id}/cancel',             [AdminBookingController::class, 'cancel']);
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

    // Admin — Walk-in registration (grooming)
    Route::post('/admin/walk-in', [WalkinController::class, 'store']);

    // Clinic administration — available only to the project's staff/admin roles.
    Route::middleware('role:admin,staff')->group(function () {
        Route::post('/admin/clinic-walk-in', [ClinicWalkinController::class, 'store']);

        Route::get('/admin/clinic-appointments',                                    [AdminClinicController::class, 'index']);
        Route::post('/admin/clinic-appointments/{id}/check-in',                     [AdminClinicController::class, 'checkIn']);
        Route::post('/admin/clinic-appointments/{id}/start-consultation',           [AdminClinicController::class, 'startConsultation']);
        Route::post('/admin/clinic-appointments/{id}/finish-consultation',          [AdminClinicController::class, 'finishConsultation']);
        Route::post('/admin/clinic-appointments/{id}/pay',                          [AdminClinicController::class, 'markPaid']);
        Route::post('/admin/clinic-appointments/{id}/cancel',                       [AdminClinicController::class, 'cancel']);
        Route::post('/admin/clinic-appointments/{id}/record',                       [AdminClinicController::class, 'saveRecord']);
        Route::post('/admin/clinic-appointments/{id}/attachments',                  [AdminClinicController::class, 'uploadAttachment']);
        Route::get('/admin/clinic-appointments/{id}/attachments/{attachmentId}/download', [AdminClinicController::class, 'downloadAttachment']);
        Route::delete('/admin/clinic-appointments/{id}/attachments/{attachmentId}', [AdminClinicController::class, 'deleteAttachment']);
    });

    // Admin — Inventory: Products
    Route::get('/inventory/items',                         [InventoryController::class, 'index']);
    Route::post('/inventory/items',                        [InventoryController::class, 'store']);
    Route::get('/inventory/items/{id}',                    [InventoryController::class, 'show']);
    Route::put('/inventory/items/{id}',                    [InventoryController::class, 'update']);
    Route::post('/inventory/items/{id}/deactivate',        [InventoryController::class, 'deactivate']);
    Route::post('/inventory/items/{id}/reactivate',        [InventoryController::class, 'reactivate']);

    // Admin — Inventory: Barcode & search
    Route::get('/inventory/search',                        [InventoryController::class, 'search']);
    Route::get('/inventory/barcode/{barcode}',             [InventoryController::class, 'findByBarcode']);

    // Admin — Inventory: Stock movements
    Route::post('/inventory/stock-in',                     [InventoryController::class, 'stockIn']);
    Route::post('/inventory/stock-out',                    [InventoryController::class, 'stockOut']);

    // Admin — Inventory: History & alerts
    Route::get('/inventory/transactions',                  [InventoryController::class, 'transactions']);
    Route::get('/inventory/low-stock',                     [InventoryController::class, 'lowStock']);
    Route::get('/inventory/alerts/expiry',                 [InventoryController::class, 'expiryAlerts']);
    Route::get('/inventory/alerts/badge',                  [InventoryController::class, 'alertBadge']);
    Route::get('/inventory/summary',                       [InventoryController::class, 'summary']);

    // Admin — Suppliers
    Route::get('/inventory/suppliers',                     [SupplierController::class, 'index']);
    Route::post('/inventory/suppliers',                    [SupplierController::class, 'store']);
    Route::put('/inventory/suppliers/{id}',                [SupplierController::class, 'update']);
    Route::post('/inventory/suppliers/{id}/deactivate',    [SupplierController::class, 'deactivate']);

    // Admin — POS
    Route::post('/pos/transactions',                  [PosController::class, 'processSale']);
    Route::get('/pos/transactions',                   [PosController::class, 'getTransactions']);
    Route::get('/pos/transactions/{posId}',           [PosController::class, 'getReceipt']);

    // Administrator-only clinic availability and settings actions.
    Route::middleware('role:admin')->group(function () {
        Route::post('/admin/clinic/stop-today',                 [ClinicClosureController::class, 'stopToday']);
        Route::post('/admin/clinic/reopen-today',               [ClinicClosureController::class, 'reopenToday']);
        Route::get('/admin/clinic/blocked-dates',               [ClinicClosureController::class, 'blockedDates']);
        Route::post('/admin/clinic/blocked-dates',              [ClinicClosureController::class, 'addBlockedDate']);
        Route::delete('/admin/clinic/blocked-dates/{id}',       [ClinicClosureController::class, 'removeBlockedDate']);
        Route::patch('/admin/clinic/settings/groomers-on-duty', [ClinicSettingController::class, 'updateGroomersOnDuty']);
    });
});
