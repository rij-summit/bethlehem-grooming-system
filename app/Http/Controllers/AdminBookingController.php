<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\Booking;
use App\Models\BookingService;
use App\Models\ClinicClosure;
use App\Models\Notification;
use App\Models\CustomerNotification;
use App\Models\Payment;

class AdminBookingController extends Controller
{
    private const MAX_CAPACITY = 20;
    private const INTAKE_STATUSES = [
        'checked_in',
        'in_progress',
        'for_payment',
        'for_pickup',
        'released',
    ];

    private function dailyIntakeCount(string $date): int
    {
        return Booking::where(function ($query) use ($date) {
                $query->whereDate('dropped_off_at', $date)
                    ->orWhere(function ($fallback) use ($date) {
                        $fallback->whereNull('dropped_off_at')
                            ->where('booking_date', $date)
                            ->whereIn('status', self::INTAKE_STATUSES);
                    });
            })
            ->whereIn('status', self::INTAKE_STATUSES)
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->sum('number_of_pets');
    }

    // ── GET BOOKINGS (split by status, filterable by date) ────────────
    public function index(Request $request)
    {
        $today         = Carbon::today();
        $selectedDate  = $request->query('date', $today->toDateString());
        $includeFuture = $request->boolean('include_future', false);

        // Validate the date; fall back to today if malformed
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
            $selectedDate = $today->toDateString();
        }

        $rangeStart = Carbon::parse($selectedDate)->startOfDay();
        if ($rangeStart->lt($today)) {
            $rangeStart = $today->copy();
        }

        $maxAheadDate = $today->copy()->addDays(3);
        $rangeEnd = $includeFuture ? $maxAheadDate : $rangeStart->copy();
        if ($rangeStart->gt($rangeEnd)) {
            $rangeStart = $rangeEnd->copy();
        }

        // Incoming: dashboard sees only the selected day; appointments can opt in to today + future.
        $incoming = Booking::whereBetween('booking_date', [
                $rangeStart->toDateString(),
                $rangeEnd->toDateString(),
            ])
            ->where('status', 'waiting_to_arrive')
            ->with(['user', 'timeWindow', 'bookingPets.pet', 'bookingServices.service'])
            ->orderByRaw("CASE WHEN booking_date = ? THEN 0 ELSE 1 END", [$rangeStart->toDateString()])
            ->orderBy('booking_date', 'asc')
            ->get()
            ->map(fn($b) => $this->formatBooking($b));

        // DATE GUARD TEMPORARILY DISABLED FOR TESTING
        // Removed booking_date filter from active states so cards stay visible
        // regardless of the selected date. Restore ->where('booking_date', $selectedDate)
        // on each query below when re-enabling the date guard for production.
        $queued = Booking::where('status', 'checked_in')
            ->with(['user', 'timeWindow', 'bookingPets.pet', 'bookingServices.service'])
            ->orderBy('queue_number', 'asc')
            ->get()
            ->map(fn($b) => $this->formatBooking($b));

        $inProgress = Booking::where('status', 'in_progress')
            ->with(['user', 'timeWindow', 'bookingPets.pet', 'bookingServices.service'])
            ->orderBy('queue_number', 'asc')
            ->get()
            ->map(fn($b) => $this->formatBooking($b));

        $forPayment = Booking::where('status', 'for_payment')
            ->with(['user', 'timeWindow', 'bookingPets.pet', 'bookingServices.service'])
            ->orderBy('queue_number', 'asc')
            ->get()
            ->map(fn($b) => $this->formatBooking($b));

        $released = Booking::where('status', 'released')
            ->with(['user', 'timeWindow', 'bookingPets.pet', 'bookingServices.service'])
            ->orderBy('queue_number', 'asc')
            ->get()
            ->map(fn($b) => $this->formatBooking($b));

        // Summary metrics (always based on today, not the filter date)
        $todayCompletedCount = Booking::whereDate('grooming_finished_at', $today->toDateString())
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->count();
        $todayIntakeCount = $this->dailyIntakeCount($today->toDateString());

        $weekStart  = Carbon::now()->startOfWeek()->toDateString();
        $weekEnd    = Carbon::now()->endOfWeek()->toDateString();
        $weekCount  = Booking::whereBetween('booking_date', [$weekStart, $weekEnd])
            ->whereNotIn('status', ['cancelled'])
            ->count();
        $revenueToday = Payment::whereDate('paid_at', $today->toDateString())
            ->where('payment_status', 'paid')
            ->sum('total_amount');
        $revenuePaymentCount = Payment::whereDate('paid_at', $today->toDateString())
            ->where('payment_status', 'paid')
            ->count();
        $noShowWeekCount = Booking::whereBetween('booking_date', [$weekStart, $weekEnd])
            ->where('status', 'no_show')
            ->count();
        $noShowWeekRate = $weekCount > 0
            ? round(($noShowWeekCount / $weekCount) * 100, 1)
            : 0;

        return response()->json([
            'success'         => true,
            'incomingList'    => $incoming->values(),
            'queuedList'      => $queued->values(),
            'inProgressList'  => $inProgress->values(),
            'forPaymentList'  => $forPayment->values(),
            'releasedList'    => $released->values(),
            'summary'         => [
                'today'               => $todayCompletedCount,
                'week'                => $weekCount,
                'revenueToday'        => (float) $revenueToday,
                'revenuePaymentCount' => $revenuePaymentCount,
                'noShowWeek'          => $noShowWeekCount,
                'noShowWeekRate'      => $noShowWeekRate,
            ],
            'recentActivity'  => $this->recentActivity(),
            'capacity'       => [
                'current' => $todayIntakeCount,
                'max'     => self::MAX_CAPACITY,
            ],
        ]);
    }

    // ── CHECK IN ──────────────────────────────────────────
    // waiting_to_arrive → checked_in, assigns queue number
    public function checkIn($id)
    {
        $booking = Booking::with(['user', 'timeWindow', 'bookingPets.pet'])->find($id);

        if (!$booking) {
            return response()->json(['success' => false, 'message' => 'Booking not found.'], 404);
        }

        if ($booking->status !== 'waiting_to_arrive') {
            return response()->json(['success' => false, 'message' => 'Booking is not in waiting status.'], 422);
        }

        // DATE GUARD TEMPORARILY DISABLED FOR TESTING
        // if ($booking->booking_date !== Carbon::today()->toDateString()) {
        //     return response()->json(['success' => false, 'message' => 'Check-in is only allowed on the day of the appointment.'], 422);
        // }

        $queueNumber = Booking::where('booking_date', $booking->booking_date)
            ->whereNotIn('status', ['cancelled', 'waiting_to_arrive'])
            ->count() + 1;

        $booking->update([
            'status'         => 'checked_in',
            'queue_number'   => $queueNumber,
            'dropped_off_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Customer checked in successfully.',
        ]);
    }

    // ── START GROOMING ────────────────────────────────────
    // checked_in → in_progress
    public function startGrooming($id)
    {
        $booking = Booking::find($id);

        if (!$booking) {
            return response()->json(['success' => false, 'message' => 'Booking not found.'], 404);
        }

        if ($booking->status !== 'checked_in') {
            return response()->json(['success' => false, 'message' => 'Booking must be checked in first.'], 422);
        }

        $booking->load('user', 'bookingPets.pet');

        $booking->update([
            'status'             => 'in_progress',
            'grooming_started_at' => now(),
        ]);

        // Notify the customer that grooming has started
        if ($booking->user) {
            $petName = $this->petNames($booking);
            CustomerNotification::create([
                'user_id'    => $booking->user->user_id,
                'booking_id' => $booking->booking_id,
                'type'       => 'grooming_started',
                'message'    => "Great news! Grooming has started for {$petName}. We'll let you know as soon as they're ready for pickup!",
                'is_read'    => false,
                'created_at' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Grooming session started.',
        ]);
    }

    // ── MARK DONE ─────────────────────────────────────────
    // in_progress → for_payment  (or archived if already paid early)
    public function markDone($id)
    {
        $booking = Booking::with('user')->find($id);

        if (!$booking) {
            return response()->json(['success' => false, 'message' => 'Booking not found.'], 404);
        }

        if ($booking->status !== 'in_progress') {
            return response()->json(['success' => false, 'message' => 'Booking must be in progress first.'], 422);
        }

        $booking->load('bookingPets.pet');
        $ownerName = trim(($booking->user?->first_name ?? '') . ' ' . ($booking->user?->last_name ?? ''));
        $petName   = $this->petNames($booking);
        $petVerb   = $this->hasMultiplePets($booking) ? 'are' : 'is';

        // Always notify the customer that their pet is ready for pickup
        if ($booking->user) {
            CustomerNotification::create([
                'user_id'    => $booking->user->user_id,
                'booking_id' => $booking->booking_id,
                'type'       => 'ready_for_pickup',
                'message'    => "{$petName} {$petVerb} all done and looking fabulous! Please come to the clinic to pick them up.",
                'is_read'    => false,
                'created_at' => now(),
            ]);
        }

        // Early-payment path: already paid, move to released (To Be Picked Up)
        if ($booking->paid) {
            $booking->update([
                'status'               => 'released',
                'grooming_finished_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Grooming done. Customer notified for pickup (early payment on file).',
            ]);
        }

        // Normal path: move to for_payment and notify admin
        $booking->update([
            'status'              => 'for_payment',
            'grooming_finished_at' => now(),
        ]);

        // Admin notification — front-desk knows to collect payment
        Notification::create([
            'type'       => 'payment_due',
            'booking_id' => $booking->booking_id,
            'message'    => "Grooming done for {$ownerName}. Pet is ready — please collect payment.",
            'is_read'    => false,
            'created_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Grooming done. Customer notified for pickup and payment.',
        ]);
    }

    // ── MARK PICKED UP ────────────────────────────────────
    // released → archived + customer notification
    public function markPickedUp($id)
    {
        $booking = Booking::with(['user', 'bookingPets.pet'])->find($id);

        if (!$booking) {
            return response()->json(['success' => false, 'message' => 'Booking not found.'], 404);
        }

        if ($booking->status !== 'released') {
            return response()->json(['success' => false, 'message' => 'Booking must be in Released status.'], 422);
        }

        $petName = $this->petNames($booking);
        $petVerb = $this->hasMultiplePets($booking) ? 'have' : 'has';

        $booking->update([
            'status'      => 'archived',
            'archived_at' => now(),
        ]);

        if ($booking->user) {
            CustomerNotification::create([
                'user_id'    => $booking->user->user_id,
                'booking_id' => $booking->booking_id,
                'type'       => 'picked_up',
                'message'    => "{$petName} {$petVerb} been released. Thank you for visiting Bethlehem Animal Clinic!",
                'is_read'    => false,
                'created_at' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Booking marked as picked up and archived.',
        ]);
    }

    // ── ARCHIVE ───────────────────────────────────────────
    // for_pickup (legacy) → archived
    // Cancel a booking and remove it from active capacity.
    public function cancel($id)
    {
        $booking = Booking::find($id);

        if (!$booking) {
            return response()->json(['success' => false, 'message' => 'Booking not found.'], 404);
        }

        if (in_array($booking->status, ['cancelled', 'archived', 'no_show'])) {
            return response()->json(['success' => false, 'message' => 'This booking cannot be cancelled.'], 422);
        }

        $booking->update([
            'status'              => 'cancelled',
            'cancellation_reason' => 'Cancelled by clinic staff.',
            'cancel_count'        => ($booking->cancel_count ?? 0) + 1,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Booking cancelled successfully.',
        ]);
    }

    public function archive($id)
    {
        $booking = Booking::find($id);

        if (!$booking) {
            return response()->json(['success' => false, 'message' => 'Booking not found.'], 404);
        }

        if (!in_array($booking->status, ['for_pickup', 'released'])) {
            return response()->json(['success' => false, 'message' => 'Only For Pickup or Released bookings can be archived here.'], 422);
        }

        $booking->update([
            'status'      => 'archived',
            'archived_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Booking archived successfully.',
        ]);
    }

    // ── NO-SHOW LIST ──────────────────────────────────────
    // GET /api/admin/bookings/no-shows
    public function noShowIndex()
    {
        $today = Carbon::today()->toDateString();

        $noShows = Booking::where('booking_date', $today)
            ->where('status', 'no_show')
            ->with(['user', 'timeWindow', 'bookingPets.pet', 'bookingServices.service'])
            ->orderBy('queue_number', 'asc')
            ->get()
            ->map(fn($b) => $this->formatBooking($b));

        return response()->json([
            'success'    => true,
            'noShowList' => $noShows->values(),
        ]);
    }

    // ── LATE CHECK-IN ─────────────────────────────────────
    // no_show → checked_in (same day only, before 5 PM, clinic not stopped)
    public function lateCheckIn($id)
    {
        $booking = Booking::find($id);

        if (!$booking) {
            return response()->json(['success' => false, 'message' => 'Booking not found.'], 404);
        }

        if ($booking->status !== 'no_show') {
            return response()->json(['success' => false, 'message' => 'Only no-show bookings can be late checked-in.'], 422);
        }

        $today = Carbon::today()->toDateString();

        if ($booking->booking_date !== $today) {
            return response()->json(['success' => false, 'message' => 'Late check-in is only available on the day of the booking.'], 422);
        }

        // Block if past 5 PM
        if (Carbon::now()->hour >= 17) {
            return response()->json(['success' => false, 'message' => 'Late check-in is no longer available after 5:00 PM.'], 422);
        }

        // Block if clinic stopped receiving today
        $stoppedToday = ClinicClosure::where('type', 'stop_today')
            ->where('start_date', $today)
            ->where('is_active', 1)
            ->exists();

        if ($stoppedToday) {
            return response()->json(['success' => false, 'message' => 'The clinic has stopped receiving for today.'], 422);
        }

        // Assign queue number at the back
        $queueNumber = Booking::where('booking_date', $today)
            ->whereNotIn('status', ['cancelled', 'waiting_to_arrive', 'no_show'])
            ->count() + 1;

        $booking->update([
            'status'         => 'checked_in',
            'queue_number'   => $queueNumber,
            'dropped_off_at' => now(),
        ]);

        return response()->json([
            'success'      => true,
            'message'      => 'Late check-in successful. Customer added to the back of the queue.',
            'queue_number' => $queueNumber,
        ]);
    }

    // ── GET ARCHIVED BOOKINGS ────────────────────────────
    public function archivedIndex(Request $request)
    {
        $search = $request->query('search', '');
        $date   = $request->query('date', '');

        $query = Booking::where('status', 'archived')
            ->with([
                'user',
                'timeWindow',
                'bookingPets.pet',
                'bookingServices.service',
                'payments' => fn($q) => $q
                    ->where('payment_status', 'paid')
                    ->orderBy('paid_at', 'desc'),
            ])
            ->orderBy('archived_at', 'desc');

        if ($date) {
            $query->where('booking_date', $date);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('booking_reference', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($q2) use ($search) {
                      $q2->where('first_name', 'like', "%{$search}%")
                         ->orWhere('last_name',  'like', "%{$search}%");
                  })
                  ->orWhereHas('bookingPets.pet', function ($q2) use ($search) {
                      $q2->where('pet_name', 'like', "%{$search}%");
                  });
            });
        }

        $archived = $query->get()->map(fn($b) => $this->formatArchivedBooking($b));

        return response()->json([
            'success'  => true,
            'archived' => $archived->values(),
            'total'    => $archived->count(),
        ]);
    }

    // ── FORMAT BOOKING FOR FRONTEND ───────────────────────
    private function formatBooking(Booking $booking): array
    {
        $user     = $booking->user;
        $window   = $booking->timeWindow;
        $bpets    = $booking->bookingPets ?? collect();
        $firstBp  = $bpets->first();
        $firstPet = $firstBp?->pet;
        $bpetsById = $bpets->keyBy('booking_pet_id');

        $petName = $firstPet?->pet_name ?? '—';
        if ($bpets->count() > 1) {
            $petName .= ' +' . ($bpets->count() - 1) . ' more';
        }

        $petType = ucfirst($firstPet?->species ?? 'Dog');
        $breed   = $firstPet?->breed ?? '—';

        // Build service label from booked services
        $bookedServices = $booking->bookingServices ?? collect();
        $serviceNames   = $bookedServices
            ->map(fn($bs) => $bs->service?->service_name)
            ->filter()
            ->unique()
            ->values();

        $serviceLabel = $serviceNames->isNotEmpty()
            ? $serviceNames->implode(', ')
            : 'Grooming';

        $paidPayment = $this->paidPaymentForBooking($booking);
        $paidTotal = $paidPayment
            ? (float) $paidPayment->total_amount
            : ((bool) $booking->paid && (float) $booking->total_amount > 0
                ? (float) $booking->total_amount
                : null);
        $bookedServicesTotal = round((float) $bookedServices->sum(
            fn($bs) => (float) ($bs->price_at_booking ?? 0),
        ), 2);
        $canUseSavedServicePrices = !$paidTotal
            || ($bookedServicesTotal > 0 && abs($bookedServicesTotal - round($paidTotal, 2)) <= 0.01);

        return [
            // Fields the card templates read directly
            'id'              => $booking->booking_id,
            'queueNumber'     => $booking->queue_number ?? 0,
            'ownerName'       => trim(($user?->first_name ?? '') . ' ' . ($user?->last_name ?? '')),
            'contactNumber'   => $user?->phone ?? '—',
            'petName'         => $petName,
            'petType'         => $petType,
            'breed'           => $breed,
            'petSize'         => $firstPet?->size,
            'size'            => $firstPet?->size,
            'serviceLabel'    => $serviceLabel,
            'appointmentDate' => $booking->booking_date,
            'appointmentTime' => $window?->window_label ?? '—',
            'dropOffTime'     => $booking->dropped_off_at
                ? \Carbon\Carbon::parse($booking->dropped_off_at)->format('g:i A')
                : null,
            'startedAt'       => $booking->grooming_started_at
                ? \Carbon\Carbon::parse($booking->grooming_started_at)->format('g:i A')
                : null,
            'completedAt'     => $booking->grooming_finished_at
                ? \Carbon\Carbon::parse($booking->grooming_finished_at)->format('g:i A')
                : null,
            'clientNotified'  => false,
            'paid'            => (bool) $booking->paid,
            'status'          => $this->mapStatus($booking->status),

            // Extra fields for the View Details modal
            'bookingReference' => $booking->booking_reference,
            'specialNotes'     => $booking->special_notes,
            'numberOfPets'     => $booking->number_of_pets,
            'paidAmount'       => $paidTotal,
            'paid_amount'      => $paidTotal,
            'payment'          => $paidPayment ? [
                'id'             => $paidPayment->payment_id ?? $paidPayment->id ?? null,
                'finalPrice'     => (float) $paidPayment->total_amount,
                'final_price'    => (float) $paidPayment->total_amount,
                'amountPaid'     => (float) $paidPayment->amount_tendered,
                'amount_paid'    => (float) $paidPayment->amount_tendered,
                'paymentMethod'  => $paidPayment->payment_method,
                'payment_method' => $paidPayment->payment_method,
                'paidAt'         => $paidPayment->paid_at
                    ? Carbon::parse($paidPayment->paid_at)->format('M j, Y g:i A')
                    : null,
                'paid_at'        => $paidPayment->paid_at,
            ] : null,
            'pets'             => $bpets->map(function ($bp) {
                $pet = $bp->pet;
                return [
                    'id'                  => $bp->booking_pet_id,
                    'bookingPetId'        => $bp->booking_pet_id,
                    'booking_pet_id'      => $bp->booking_pet_id,
                    'petId'               => $pet?->pet_id,
                    'pet_id'              => $pet?->pet_id,
                    'pet_name'            => $pet?->pet_name,
                    'name'                => $pet?->pet_name,
                    'petType'             => ucfirst($pet?->species ?? 'Dog'),
                    'pet_type'            => $pet?->species,
                    'petSize'             => $pet?->size,
                    'pet_size'            => $pet?->size,
                    'petName'             => $pet?->pet_name ?? '—',
                    'species'             => ucfirst($pet?->species ?? '—'),
                    'breed'               => $pet?->breed ?? '—',
                    'size'                => $pet?->size ?? '—',
                    'furType'             => $pet?->fur_type ?? '—',
                    'weight'              => $pet?->weight ? $pet->weight . ' kg' : '—',
                    'medicalConditions'   => $pet?->medical_conditions ?? null,
                    'specialInstructions' => $bp->special_instructions ?? null,
                ];
            })->values(),
            'services' => $bookedServices->map(function ($bs) use ($bpetsById, $paidTotal, $canUseSavedServicePrices, $bookedServices) {
                $bookingPet = $bpetsById->get($bs->booking_pet_id);
                $pet = $bookingPet?->pet;
                $savedPrice = (float) ($bs->price_at_booking ?? 0);
                $paidPrice = null;
                $paidPriceSource = null;

                if ($paidTotal && $bookedServices->count() === 1) {
                    $paidPrice = $paidTotal;
                    $paidPriceSource = 'payment_total';
                } elseif ($savedPrice > 0 && $canUseSavedServicePrices) {
                    $paidPrice = $savedPrice;
                    $paidPriceSource = $paidTotal ? 'service_line' : 'booking_price';
                }

                return [
                    'id'                 => $bs->booking_service_id,
                    'bookingServiceId'   => $bs->booking_service_id,
                    'booking_service_id' => $bs->booking_service_id,
                    'bookingPetId'       => $bs->booking_pet_id,
                    'booking_pet_id'     => $bs->booking_pet_id,
                    'petId'              => $pet?->pet_id,
                    'pet_id'             => $pet?->pet_id,
                    'petName'            => $pet?->pet_name ?? '',
                    'pet_name'           => $pet?->pet_name ?? '',
                    'serviceId'          => $bs->service?->service_id,
                    'service_id'         => $bs->service?->service_id,
                    'slug'               => $bs->service?->slug,
                    'serviceSlug'        => $bs->service?->slug,
                    'service_slug'       => $bs->service?->slug,
                    'serviceName'        => $bs->service?->service_name ?? 'Grooming Service',
                    'service_name'       => $bs->service?->service_name ?? 'Grooming Service',
                    'description'        => $bs->service?->description,
                    'name'               => $bs->service?->service_name ?? '—',
                    'priceAtBooking'     => $bs->price_at_booking,
                    'price_at_booking'   => $bs->price_at_booking,
                    'paidPrice'          => $paidPrice,
                    'paid_price'         => $paidPrice,
                    'paidPriceSource'    => $paidPriceSource,
                    'paid_price_source'  => $paidPriceSource,
                    'paymentTotal'       => $paidTotal,
                    'payment_total'      => $paidTotal,
                ];
            })->values(),
        ];
    }

    // Formats a booking record for the archive page
    private function formatArchivedBooking(Booking $booking): array
    {
        $base = $this->formatBooking($booking);

        $base['archivedAt'] = $booking->archived_at
            ? \Carbon\Carbon::parse($booking->archived_at)->format('M j, Y g:i A')
            : '—';

        return $base;
    }

    private function paidPaymentForBooking(Booking $booking): ?Payment
    {
        if ($booking->relationLoaded('payments')) {
            return $booking->payments
                ->where('payment_status', 'paid')
                ->sortByDesc(fn($payment) => $payment->paid_at
                    ? Carbon::parse($payment->paid_at)->timestamp
                    : 0)
                ->first();
        }

        return null;
    }

    private function recentActivity(): array
    {
        $payments = Payment::with(['booking.user', 'booking.bookingPets.pet'])
            ->where('payment_status', 'paid')
            ->whereNotNull('paid_at')
            ->orderBy('paid_at', 'desc')
            ->limit(6)
            ->get()
            ->map(function (Payment $payment) {
                $booking = $payment->booking;
                $ownerLastName = $booking?->user?->last_name ?: $this->ownerName($booking);

                return [
                    'id'        => 'payment-' . $payment->payment_id,
                    'type'      => 'payment',
                    'title'     => 'Payment collected',
                    'subtitle'  => "\u{20B1}" . number_format((float) $payment->total_amount) . ' - ' . ($ownerLastName ?: 'Customer'),
                    'time'      => $payment->paid_at,
                ];
            });

        $checkIns = Booking::with(['user', 'bookingPets.pet'])
            ->whereNotNull('dropped_off_at')
            ->orderBy('dropped_off_at', 'desc')
            ->limit(6)
            ->get()
            ->map(function (Booking $booking) {
                return [
                    'id'        => 'check-in-' . $booking->booking_id,
                    'type'      => 'queued',
                    'title'     => 'Checked in',
                    'subtitle'  => $this->petNames($booking) . ' - ' . ($this->ownerName($booking) ?: 'Customer'),
                    'time'      => $booking->dropped_off_at,
                ];
            });

        $groomingStarted = Booking::with(['user', 'bookingPets.pet', 'bookingServices.service'])
            ->whereNotNull('grooming_started_at')
            ->orderBy('grooming_started_at', 'desc')
            ->limit(6)
            ->get()
            ->map(function (Booking $booking) {
                return [
                    'id'        => 'grooming-started-' . $booking->booking_id,
                    'type'      => 'in_progress',
                    'title'     => 'Grooming started',
                    'subtitle'  => $this->petNames($booking) . ' - ' . $this->serviceLabel($booking),
                    'time'      => $booking->grooming_started_at,
                ];
            });

        $completed = Booking::with(['user', 'bookingPets.pet', 'bookingServices.service'])
            ->whereNotNull('grooming_finished_at')
            ->orderBy('grooming_finished_at', 'desc')
            ->limit(6)
            ->get()
            ->map(function (Booking $booking) {
                return [
                    'id'        => 'completed-' . $booking->booking_id,
                    'type'      => 'completed',
                    'title'     => 'Grooming Service Completed',
                    'subtitle'  => $this->petNames($booking) . ' - ' . $this->serviceLabel($booking),
                    'time'      => $booking->grooming_finished_at,
                ];
            });

        return $payments
            ->concat($checkIns)
            ->concat($groomingStarted)
            ->concat($completed)
            ->filter(fn($activity) => !empty($activity['time']))
            ->sortByDesc(fn($activity) => Carbon::parse($activity['time'])->timestamp)
            ->take(5)
            ->map(function ($activity) {
                $time = Carbon::parse($activity['time']);

                return [
                    ...$activity,
                    'createdAt' => $time->toIso8601String(),
                    'timeLabel' => $time->format('g:i A'),
                ];
            })
            ->values()
            ->all();
    }

    private function ownerName(?Booking $booking): string
    {
        if (!$booking?->user) {
            return '';
        }

        return trim(($booking->user->first_name ?? '') . ' ' . ($booking->user->last_name ?? ''));
    }

    private function petNames(Booking $booking): string
    {
        $names = ($booking->bookingPets ?? collect())
            ->map(fn($bookingPet) => $bookingPet->pet?->pet_name)
            ->filter()
            ->values();

        if ($names->isEmpty()) {
            return 'your pet';
        }

        if ($names->count() === 1) {
            return $names->first();
        }

        if ($names->count() === 2) {
            return $names->implode(' and ');
        }

        return $names->slice(0, -1)->implode(', ') . ', and ' . $names->last();
    }

    private function hasMultiplePets(Booking $booking): bool
    {
        return ($booking->bookingPets ?? collect())
            ->map(fn($bookingPet) => $bookingPet->pet?->pet_name)
            ->filter()
            ->unique()
            ->count() > 1;
    }

    private function serviceLabel(Booking $booking): string
    {
        $services = ($booking->bookingServices ?? collect())
            ->map(fn($bookingService) => $bookingService->service?->service_name)
            ->filter()
            ->unique()
            ->values();

        return $services->isNotEmpty() ? $services->implode(', ') : 'Grooming';
    }

    // Maps DB status values to the tab keys the frontend uses
    private function mapStatus(string $status): string
    {
        return match ($status) {
            'waiting_to_arrive' => 'incoming',
            'checked_in'        => 'queued',
            'in_progress'       => 'in-progress',
            'for_pickup'        => 'for-pickup',
            'for_payment'       => 'for-payment',
            'released'          => 'released',
            default             => $status,
        };
    }
}
