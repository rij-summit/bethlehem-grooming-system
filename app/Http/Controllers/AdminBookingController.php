<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\Booking;
use App\Models\BookingService;
use App\Models\ClinicClosure;
use App\Models\Notification;
use App\Models\CustomerNotification;

class AdminBookingController extends Controller
{
    private const MAX_CAPACITY = 20;

    // ── GET BOOKINGS (split by status, filterable by date) ────────────
    public function index(Request $request)
    {
        $today        = Carbon::today()->toDateString();
        $selectedDate = $request->query('date', $today);

        // Validate the date; fall back to today if malformed
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
            $selectedDate = $today;
        }

        $maxAheadDate = Carbon::parse($selectedDate)->addDays(3)->toDateString();

        // Incoming: waiting_to_arrive within selectedDate + 3 days
        $incoming = Booking::whereBetween('booking_date', [$selectedDate, $maxAheadDate])
            ->where('status', 'waiting_to_arrive')
            ->with(['user', 'timeWindow', 'bookingPets.pet', 'bookingServices.service'])
            ->orderByRaw("CASE WHEN booking_date = ? THEN 0 ELSE 1 END", [$selectedDate])
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
        $todayCount = Booking::where('booking_date', $today)
            ->whereNotIn('status', ['cancelled'])
            ->count();

        $weekStart  = Carbon::now()->startOfWeek()->toDateString();
        $weekEnd    = Carbon::now()->endOfWeek()->toDateString();
        $weekCount  = Booking::whereBetween('booking_date', [$weekStart, $weekEnd])
            ->whereNotIn('status', ['cancelled'])
            ->count();

        return response()->json([
            'success'         => true,
            'incomingList'    => $incoming->values(),
            'queuedList'      => $queued->values(),
            'inProgressList'  => $inProgress->values(),
            'forPaymentList'  => $forPayment->values(),
            'releasedList'    => $released->values(),
            'summary'         => [
                'today' => $todayCount,
                'week'  => $weekCount,
            ],
            'capacity'       => [
                'current' => $todayCount,
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
            $petName = $booking->bookingPets->first()?->pet?->pet_name ?? 'your pet';
            CustomerNotification::create([
                'user_id'    => $booking->user->user_id,
                'booking_id' => $booking->booking_id,
                'type'       => 'grooming_started',
                'message'    => "Great news! {$petName}'s grooming session has started. We'll let you know as soon as they're ready for pickup! 🐾",
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
        $petName   = $booking->bookingPets->first()?->pet?->pet_name ?? 'your pet';

        // Always notify the customer that their pet is ready for pickup
        if ($booking->user) {
            CustomerNotification::create([
                'user_id'    => $booking->user->user_id,
                'booking_id' => $booking->booking_id,
                'type'       => 'ready_for_pickup',
                'message'    => "🐾 {$petName} is all done and looking fabulous! Please come to the clinic to pick them up.",
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

        $petName = $booking->bookingPets->first()?->pet?->pet_name ?? 'your pet';

        $booking->update([
            'status'      => 'archived',
            'archived_at' => now(),
        ]);

        if ($booking->user) {
            CustomerNotification::create([
                'user_id'    => $booking->user->user_id,
                'booking_id' => $booking->booking_id,
                'type'       => 'picked_up',
                'message'    => "Your pet {$petName} has been released. Thank you for visiting Bethlehem Animal Clinic!",
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
            ->with(['user', 'timeWindow', 'bookingPets.pet', 'bookingServices.service'])
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

        return [
            // Fields the card templates read directly
            'id'              => $booking->booking_id,
            'queueNumber'     => $booking->queue_number ?? 0,
            'ownerName'       => trim(($user?->first_name ?? '') . ' ' . ($user?->last_name ?? '')),
            'contactNumber'   => $user?->phone ?? '—',
            'petName'         => $petName,
            'petType'         => $petType,
            'breed'           => $breed,
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
            'pets'             => $bpets->map(function ($bp) {
                $pet = $bp->pet;
                return [
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
            'services' => $bookedServices->map(fn($bs) => [
                'name'           => $bs->service?->service_name ?? '—',
                'priceAtBooking' => $bs->price_at_booking,
            ])->values(),
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
