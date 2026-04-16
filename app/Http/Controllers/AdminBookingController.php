<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\Booking;

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
            ->with(['user', 'timeWindow', 'bookingPets.pet'])
            ->orderByRaw("CASE WHEN booking_date = ? THEN 0 ELSE 1 END", [$selectedDate])
            ->orderBy('booking_date', 'asc')
            ->get()
            ->map(fn($b) => $this->formatBooking($b));

        // Queued, In-Progress, For Pickup: filtered by selectedDate
        $queued = Booking::where('booking_date', $selectedDate)
            ->where('status', 'checked_in')
            ->with(['user', 'timeWindow', 'bookingPets.pet'])
            ->orderBy('queue_number', 'asc')
            ->get()
            ->map(fn($b) => $this->formatBooking($b));

        $inProgress = Booking::where('booking_date', $selectedDate)
            ->where('status', 'in_progress')
            ->with(['user', 'timeWindow', 'bookingPets.pet'])
            ->orderBy('queue_number', 'asc')
            ->get()
            ->map(fn($b) => $this->formatBooking($b));

        $forPickup = Booking::where('booking_date', $selectedDate)
            ->where('status', 'for_pickup')
            ->with(['user', 'timeWindow', 'bookingPets.pet'])
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
            'success'        => true,
            'incomingList'   => $incoming->values(),
            'queuedList'     => $queued->values(),
            'inProgressList' => $inProgress->values(),
            'forPickupList'  => $forPickup->values(),
            'summary'        => [
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

        if ($booking->booking_date !== Carbon::today()->toDateString()) {
            return response()->json(['success' => false, 'message' => 'Check-in is only allowed on the day of the appointment.'], 422);
        }

        $queueNumber = Booking::where('booking_date', $booking->booking_date)
            ->whereNotIn('status', ['cancelled', 'waiting_to_arrive'])
            ->count() + 1;

        $booking->update([
            'status'       => 'checked_in',
            'queue_number' => $queueNumber,
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

        $booking->update(['status' => 'in_progress']);

        return response()->json([
            'success' => true,
            'message' => 'Grooming session started.',
        ]);
    }

    // ── MARK DONE ─────────────────────────────────────────
    // in_progress → for_pickup
    public function markDone($id)
    {
        $booking = Booking::find($id);

        if (!$booking) {
            return response()->json(['success' => false, 'message' => 'Booking not found.'], 404);
        }

        if ($booking->status !== 'in_progress') {
            return response()->json(['success' => false, 'message' => 'Booking must be in progress first.'], 422);
        }

        $booking->update(['status' => 'for_pickup']);

        return response()->json([
            'success' => true,
            'message' => 'Grooming session marked as done. Pet is ready for pickup.',
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

        return [
            // Fields the card templates read directly
            'id'              => $booking->booking_id,
            'queueNumber'     => $booking->queue_number ?? 0,
            'ownerName'       => trim(($user?->first_name ?? '') . ' ' . ($user?->last_name ?? '')),
            'contactNumber'   => $user?->phone ?? '—',
            'petName'         => $petName,
            'petType'         => $petType,
            'breed'           => $breed,
            'serviceLabel'    => 'Grooming',
            'appointmentDate' => $booking->booking_date,
            'appointmentTime' => $window?->window_label ?? '—',
            'dropOffTime'     => '—',
            'startedAt'       => '—',
            'completedAt'     => '—',
            'clientNotified'  => false,
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
        ];
    }

    // Maps DB status values to the tab keys the frontend uses
    private function mapStatus(string $status): string
    {
        return match ($status) {
            'waiting_to_arrive' => 'incoming',
            'checked_in'        => 'queued',
            'in_progress'       => 'in-progress',
            'for_pickup'        => 'for-pickup',
            default             => $status,
        };
    }
}
