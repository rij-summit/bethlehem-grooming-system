<?php

use App\Http\Controllers\AdminBookingController;
use App\Http\Controllers\AdminClinicController;
use App\Http\Controllers\AdminSecurityController;
use App\Http\Controllers\AdminGroomingClinicReferralController;
use App\Http\Controllers\AdminGroomingMedicalConcernController;
use App\Http\Controllers\AdminPetProfileController;
use App\Http\Controllers\AdminVaccinationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\ClinicClosureController;
use App\Http\Controllers\ClinicSettingController;
use App\Http\Controllers\ClinicWalkinController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerNotificationController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\GroomingClinicReferralController;
use App\Http\Controllers\GroomingStoppedPaymentReviewController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\LoginEmailChallengeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PetController;
use App\Http\Controllers\PetGroomingMedicalConcernController;
use App\Http\Controllers\PetMedicalRecordController;
use App\Http\Controllers\PetVaccinationController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\WalkinController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/sign-in', [AuthController::class, 'signIn']);
Route::post('/password/forgot', [PasswordResetController::class, 'requestLink'])
    ->middleware('throttle:3,10');
Route::post('/password/reset/verify', [PasswordResetController::class, 'verifyLink'])
    ->middleware('throttle:10,1');
Route::post('/password/reset', [PasswordResetController::class, 'reset'])
    ->middleware('throttle:10,1');
Route::post('/email/verify', [EmailVerificationController::class, 'verify'])->middleware('throttle:10,1');
Route::post('/email/resend', [EmailVerificationController::class, 'resend'])->middleware('throttle:3,10');
Route::post('/email/login/confirm', [LoginEmailChallengeController::class, 'confirm'])
    ->middleware('throttle:10,1');
Route::post('/chatbot', [ChatbotController::class, 'chat']);

// ── PUBLIC ROUTES ─────────────────────────────────────
Route::get('/timeslots', [BookingController::class,   'getTimeslots']);
Route::get('/clinic/status', [ClinicClosureController::class, 'status']);
Route::get('/clinic/timeslots', [ClinicWalkinController::class, 'timeslots']);
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
Route::middleware(['auth:sanctum', 'account.usable'])->group(function () {

    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/email/login/complete', [LoginEmailChallengeController::class, 'complete']);
    Route::get('/me', [AuthController::class, 'me']);

    // Pets
    Route::get('/pets', [PetController::class, 'index']);
    Route::get('/pets/{id}', [PetController::class, 'show']);
    Route::get('/pets/{petId}/medical-records', [PetMedicalRecordController::class, 'index']);
    Route::get('/pets/{petId}/vaccinations', [PetVaccinationController::class, 'index']);
    Route::get('/pets/{petId}/medical-concerns', [PetGroomingMedicalConcernController::class, 'index']);
    Route::get('/pets/{petId}/medical-concerns/{publicId}', [PetGroomingMedicalConcernController::class, 'show']);
    Route::post('/pets/{petId}/medical-concerns/{publicId}/acknowledge', [PetGroomingMedicalConcernController::class, 'acknowledge']);
    Route::post('/pets/{petId}/medical-concerns/{publicId}/consent', [PetGroomingMedicalConcernController::class, 'consent']);
    Route::get('/pets/{petId}/grooming-clinic-referrals/{publicId}', [GroomingClinicReferralController::class, 'customerShow']);
    Route::post('/pets/{petId}/grooming-clinic-referrals/{publicId}/consent', [GroomingClinicReferralController::class, 'customerConsent']);
    Route::post('/pets', [PetController::class, 'store']);
    Route::put('/pets/{id}', [PetController::class, 'update']);
    Route::post('/pets/{id}/archive', [PetController::class, 'archive']);
    Route::post('/pets/{id}/unarchive', [PetController::class, 'unarchive']);

    // Admin — Pet management (update any customer's pet)
    Route::put('/admin/pets/{id}', [PetController::class, 'adminUpdate']);
    Route::get('/admin/pets/{petId}/profile', [AdminPetProfileController::class, 'show']);

    // Booking
    Route::post('/booking/store', [BookingController::class, 'store']);
    Route::get('/pre-registration/access', [BookingController::class, 'preRegistrationAccess']);
    Route::get('/booking/history', [BookingController::class, 'history']);
    Route::get('/booking/grooming-capacity', [BookingController::class, 'groomingCapacity']);
    Route::get('/booking/{id}', [BookingController::class, 'show']);
    Route::post('/booking/cancel', [BookingController::class, 'cancel']);
    Route::post('/booking/reschedule', [BookingController::class, 'reschedule']);

    // Customer — Clinic visit pre-registration
    Route::post('/clinic/pre-register', [ClinicWalkinController::class, 'preRegister']);

    // Admin — Customer management (staff can view, admin can manage)
    Route::get('/admin/dashboard/search', [CustomerController::class, 'dashboardSearch']);
    Route::get('/admin/customers', [CustomerController::class, 'index']);
    Route::post('/admin/customers/unregistered', [CustomerController::class, 'storeUnregistered']);
    Route::get('/admin/customers/unregistered/{id}', [CustomerController::class, 'showUnregistered']);
    Route::post('/admin/customers/unregistered/{id}/archive', [CustomerController::class, 'archiveUnregistered']);
    Route::delete('/admin/customers/unregistered/{id}', [CustomerController::class, 'destroyUnregistered']);
    Route::get('/admin/walk-in/customers', [CustomerController::class, 'walkInSearch']);
    Route::post('/admin/walk-in/customers/validate-new-owner', [CustomerController::class, 'validateWalkInOwner']);
    Route::post('/admin/customer-pets/{ownerType}/{ownerId}', [PetController::class, 'adminStore']);
    Route::get('/admin/customers/{id}', [CustomerController::class, 'show']);
    Route::post('/admin/customers/{id}/deactivate', [CustomerController::class, 'deactivate']);
    Route::post('/admin/customers/{id}/reactivate', [CustomerController::class, 'reactivate']);
    Route::post('/admin/customers/{id}/archive', [CustomerController::class, 'archive']);
    Route::post('/admin/customers/{id}/unarchive', [CustomerController::class, 'unarchive']);
    Route::delete('/admin/customers/{id}', [CustomerController::class, 'destroy']);

    // Customer — Notifications
    Route::get('/customer/notifications', [CustomerNotificationController::class, 'index']);
    Route::patch('/customer/notifications/read-all', [CustomerNotificationController::class, 'markAllRead']);
    Route::patch('/customer/notifications/{id}/read', [CustomerNotificationController::class, 'markRead']);

    // Grooming administration — available only to the project's staff/admin roles.
    Route::middleware('role:admin,staff')->group(function () {
        Route::get('/admin/notifications', [NotificationController::class, 'index']);
        Route::patch('/admin/notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::patch('/admin/notifications/{id}/read', [NotificationController::class, 'markRead']);

        Route::get('/admin/bookings', [AdminBookingController::class, 'index']);
        Route::get('/admin/bookings/archived', [AdminBookingController::class, 'archivedIndex']);
        Route::patch('/admin/clinic/settings/groomers-on-duty', [ClinicSettingController::class, 'updateGroomersOnDuty']);
        Route::post('/admin/bookings/{id}/check-in', [AdminBookingController::class, 'checkIn']);
        Route::post('/admin/bookings/{id}/sedation-consent', [AdminBookingController::class, 'recordSedationConsent']);
        Route::post('/admin/bookings/{id}/revert-check-in', [AdminBookingController::class, 'revertCheckIn']);
        Route::post('/admin/bookings/{id}/start-grooming', [AdminBookingController::class, 'startGrooming']);
        Route::post('/admin/bookings/{id}/revert-start-grooming', [AdminBookingController::class, 'revertStartGrooming']);
        Route::post('/admin/bookings/{id}/pets/{bookingPetId}/start-grooming', [AdminBookingController::class, 'startPetGrooming']);
        Route::post('/admin/bookings/{id}/pets/{bookingPetId}/mark-done', [AdminBookingController::class, 'markPetDone']);
        Route::post('/admin/bookings/{id}/mark-done', [AdminBookingController::class, 'markDone']);
        Route::post('/admin/bookings/{id}/cancel', [AdminBookingController::class, 'cancel']);
        Route::post('/admin/bookings/{id}/archive', [AdminBookingController::class, 'archive']);
        Route::post('/admin/bookings/{id}/picked-up', [AdminBookingController::class, 'markPickedUp']);
        Route::post('/admin/bookings/{id}/late-check-in', [AdminBookingController::class, 'lateCheckIn']);
        Route::get('/admin/bookings/no-shows', [AdminBookingController::class, 'noShowIndex']);

        Route::get('/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns', [AdminGroomingMedicalConcernController::class, 'index']);
        Route::post('/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns', [AdminGroomingMedicalConcernController::class, 'store']);
        Route::get('/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns/{concernId}', [AdminGroomingMedicalConcernController::class, 'show']);
        Route::patch('/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns/{concernId}', [AdminGroomingMedicalConcernController::class, 'update']);
        Route::post('/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns/{concernId}/notify-customer', [AdminGroomingMedicalConcernController::class, 'notifyCustomer']);
        Route::post('/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns/{concernId}/apply-recommended-action', [AdminGroomingMedicalConcernController::class, 'applyRecommendedAction']);
        Route::post('/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns/{concernId}/resume-grooming', [AdminGroomingMedicalConcernController::class, 'resumeGrooming']);
        Route::post('/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns/{concernId}/cancel', [AdminGroomingMedicalConcernController::class, 'cancel']);
        Route::post('/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns/{concernId}/resolve', [AdminGroomingMedicalConcernController::class, 'resolve']);

        Route::get('/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns/{concernId}/clinic-referral', [GroomingClinicReferralController::class, 'staffShow']);
        Route::post('/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns/{concernId}/clinic-referral', [GroomingClinicReferralController::class, 'store']);
        Route::post('/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns/{concernId}/clinic-referral/in-person-consent', [GroomingClinicReferralController::class, 'inPersonConsent']);

        Route::get('/admin/bookings/{bookingId}/pets/{bookingPetId}/stopped-payment-review', [GroomingStoppedPaymentReviewController::class, 'show']);
        Route::post('/admin/bookings/{bookingId}/pets/{bookingPetId}/medical-concerns/{concernId}/stopped-payment-review', [GroomingStoppedPaymentReviewController::class, 'store']);

        Route::post('/admin/bookings/{id}/pay', [PaymentController::class, 'store']);
        Route::post('/admin/bookings/{id}/pay-now', [PaymentController::class, 'payNow']);
        Route::post('/admin/bookings/{id}/release', [PaymentController::class, 'release']);
        Route::get('/admin/transactions', [PaymentController::class, 'index']);

        Route::post('/admin/walk-in', [WalkinController::class, 'store']);
    });

    Route::get('/admin/reports/services-performed', [ReportController::class, 'servicesPerformed']);
    Route::get('/admin/reports/customer-activity', [ReportController::class, 'customerActivity']);

    // Clinic administration — available only to the project's staff/admin roles.
    Route::middleware('role:admin,staff')->group(function () {
        Route::post('/admin/clinic-walk-in', [ClinicWalkinController::class, 'store']);

        Route::get('/admin/clinic-referrals', [AdminGroomingClinicReferralController::class, 'index']);
        Route::get('/admin/clinic-referrals/{publicId}', [AdminGroomingClinicReferralController::class, 'show']);
        Route::post('/admin/clinic-referrals/{publicId}/accept', [AdminGroomingClinicReferralController::class, 'accept']);

        Route::get('/admin/clinic-appointments', [AdminClinicController::class, 'index']);
        Route::get('/admin/clinic-appointments/archived', [AdminClinicController::class, 'archivedIndex']);
        Route::post('/admin/clinic-appointments/{id}/check-in', [AdminClinicController::class, 'checkIn']);
        Route::post('/admin/clinic-appointments/{id}/start-consultation', [AdminClinicController::class, 'startConsultation']);
        Route::post('/admin/clinic-appointments/{id}/finish-consultation', [AdminClinicController::class, 'finishConsultation']);
        Route::post('/admin/clinic-appointments/{id}/pay', [AdminClinicController::class, 'markPaid']);
        Route::post('/admin/clinic-appointments/{id}/cancel', [AdminClinicController::class, 'cancel']);
        Route::post('/admin/clinic-appointments/{id}/record', [AdminClinicController::class, 'saveRecord']);
        Route::post('/admin/clinic-appointments/{id}/attachments', [AdminClinicController::class, 'uploadAttachment']);
        Route::get('/admin/clinic-appointments/{id}/attachments/{attachmentId}/download', [AdminClinicController::class, 'downloadAttachment']);
        Route::delete('/admin/clinic-appointments/{id}/attachments/{attachmentId}', [AdminClinicController::class, 'deleteAttachment']);

        Route::get('/admin/pets/{petId}/vaccinations', [AdminVaccinationController::class, 'index']);
        Route::post('/admin/pets/{petId}/vaccinations', [AdminVaccinationController::class, 'store']);
        Route::get('/admin/pets/{petId}/vaccinations/{vaccinationId}', [AdminVaccinationController::class, 'show']);
        Route::patch('/admin/pets/{petId}/vaccinations/{vaccinationId}', [AdminVaccinationController::class, 'update']);
        Route::post('/admin/pets/{petId}/vaccinations/{vaccinationId}/publish', [AdminVaccinationController::class, 'publish']);
        Route::post('/admin/pets/{petId}/vaccinations/{vaccinationId}/void', [AdminVaccinationController::class, 'void']);
    });

    // Inventory, suppliers, and POS expose stock and financial data only to staff/admin roles.
    Route::middleware('role:admin,staff')->group(function () {
        // Admin — Inventory: Products
        Route::get('/inventory/items', [InventoryController::class, 'index']);
        Route::post('/inventory/items', [InventoryController::class, 'store']);
        Route::get('/inventory/items/{id}', [InventoryController::class, 'show']);
        Route::put('/inventory/items/{id}', [InventoryController::class, 'update']);
        Route::post('/inventory/items/{id}/deactivate', [InventoryController::class, 'deactivate']);
        Route::post('/inventory/items/{id}/reactivate', [InventoryController::class, 'reactivate']);

        // Admin — Inventory: Barcode & search
        Route::get('/inventory/search', [InventoryController::class, 'search']);
        Route::get('/inventory/barcode/{barcode}', [InventoryController::class, 'findByBarcode']);

        // Admin — Inventory: Stock movements
        Route::post('/inventory/stock-in', [InventoryController::class, 'stockIn']);
        Route::post('/inventory/stock-out', [InventoryController::class, 'stockOut']);

        // Admin — Inventory: History & alerts
        Route::get('/inventory/transactions', [InventoryController::class, 'transactions']);
        Route::get('/inventory/low-stock', [InventoryController::class, 'lowStock']);
        Route::get('/inventory/alerts/expiry', [InventoryController::class, 'expiryAlerts']);
        Route::get('/inventory/alerts/badge', [InventoryController::class, 'alertBadge']);
        Route::get('/inventory/summary', [InventoryController::class, 'summary']);

        // Admin — Suppliers
        Route::get('/inventory/suppliers', [SupplierController::class, 'index']);
        Route::post('/inventory/suppliers', [SupplierController::class, 'store']);
        Route::put('/inventory/suppliers/{id}', [SupplierController::class, 'update']);
        Route::post('/inventory/suppliers/{id}/deactivate', [SupplierController::class, 'deactivate']);

        // Admin — POS
        Route::post('/pos/transactions', [PosController::class, 'processSale']);
        Route::get('/pos/transactions', [PosController::class, 'getTransactions']);
        Route::get('/pos/transactions/{posId}', [PosController::class, 'getReceipt']);
    });

    // Administrator-only clinic availability and closure actions.
    Route::middleware('role:admin')->group(function () {
        Route::get('/admin/security/accounts', [AdminSecurityController::class, 'accounts']);
        Route::post('/admin/security/account/credential-change', [AdminSecurityController::class, 'requestOwnChange'])
            ->middleware('throttle:3,10');
        Route::post('/admin/security/staff/{staff}/credential-change', [AdminSecurityController::class, 'requestStaffChange'])
            ->middleware('throttle:3,10');
        Route::post('/admin/security/staff-accounts', [AdminSecurityController::class, 'requestStaffAccount'])
            ->middleware('throttle:3,10');
        Route::post('/admin/security/staff-accounts/{pendingStaff}/confirm', [AdminSecurityController::class, 'confirmStaffAccount'])
            ->middleware('throttle:10,1');
        Route::post('/admin/security/staff-accounts/{pendingStaff}/resend', [AdminSecurityController::class, 'resendStaffAccountCode'])
            ->middleware('throttle:3,10');
        Route::patch('/admin/security/staff/{staff}/status', [AdminSecurityController::class, 'updateStaffStatus'])
            ->middleware('throttle:10,1');
        Route::post('/admin/security/credential-changes/{change}/confirm', [AdminSecurityController::class, 'confirm'])
            ->middleware('throttle:10,1');
        Route::post('/admin/security/credential-changes/{change}/resend', [AdminSecurityController::class, 'resend'])
            ->middleware('throttle:3,10');

        Route::post('/admin/clinic/stop-today', [ClinicClosureController::class, 'stopToday']);
        Route::post('/admin/clinic/reopen-today', [ClinicClosureController::class, 'reopenToday']);
        Route::get('/admin/clinic/blocked-dates', [ClinicClosureController::class, 'blockedDates']);
        Route::post('/admin/clinic/blocked-dates', [ClinicClosureController::class, 'addBlockedDate']);
        Route::delete('/admin/clinic/blocked-dates/{id}', [ClinicClosureController::class, 'removeBlockedDate']);
        Route::get('/admin/clinic/settings/availability', [ClinicSettingController::class, 'availability']);
        Route::patch('/admin/clinic/settings/availability', [ClinicSettingController::class, 'updateAvailability']);
    });
});
