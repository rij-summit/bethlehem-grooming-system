<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use App\Models\Booking;
use App\Models\Notification;
use App\Models\TimeWindow;
use App\Models\Pet;
use App\Models\BookingPet;
use App\Models\BookingService;
use App\Models\Service;

class BookingController extends Controller
{
    // ── GET AVAILABLE TIME WINDOWS ────────────────────────
    public function getTimeslots(Request $request)
    {
        $date = $request->query('date', Carbon::today()->toDateString());

        $windows = TimeWindow::where('is_active', 1)->get();

        $result = $windows->map(function ($window) use ($date) {
            $booked = Booking::where('window_id', $window->window_id)
                ->where('booking_date', $date)
                ->whereNotIn('status', ['cancelled'])
                ->count();

            $remaining = $window->max_slots - $booked;
            $isFull    = $remaining <= 0;

            return [
                'window_id'    => $window->window_id,
                'window_label' => $window->window_label,
                'start_time'   => $window->start_time,
                'end_time'     => $window->end_time,
                'max_slots'    => $window->max_slots,
                'booked'       => $booked,
                'remaining'    => max(0, $remaining),
                'is_full'      => $isFull,
                'recommended'  => false,
            ];
        });

        // ── AI FEATURE: Mark least congested as recommended ──
        $available = $result->where('is_full', false);
        if ($available->isNotEmpty()) {
            $minBooked   = $available->min('booked');
            $recommended = $available->firstWhere('booked', $minBooked);

            $result = $result->map(function ($window) use ($recommended) {
                if ($window['window_id'] === $recommended['window_id']) {
                    $window['recommended'] = true;
                }
                return $window;
            });
        }

        // ── Check if day is fully booked ──────────────────
        $totalBooked = Booking::where('booking_date', $date)
            ->whereNotIn('status', ['cancelled'])
            ->count();

        $dayFull = $totalBooked >= 20;

        return response()->json([
            'success'      => true,
            'date'         => $date,
            'day_full'     => $dayFull,
            'total_booked' => $totalBooked,
            'capacity'     => 20,
            'windows'      => $result->values(),
        ]);
    }

    // ── SUBMIT A BOOKING ──────────────────────────────────
    public function store(Request $request)
    {
        $request->validate([
            'booking_date'  => 'required|date|after_or_equal:today',
            'window_id'     => 'required|exists:time_windows,window_id',
            'number_of_pets'=> 'required|integer|min:1|max:3',
            'special_notes' => 'nullable|string',
            'pets'          => 'required|array|min:1|max:3',
            'pets.*.pet_id' => 'nullable|integer|exists:pets,pet_id',
            'pets.*.pet_name'   => 'required|string|max:100',
            'pets.*.species'    => 'nullable|string|max:50',
            'pets.*.breed'      => 'nullable|string|max:100',
            'pets.*.size'       => 'nullable|in:small,medium,large,extra_large',
            'pets.*.fur_type'   => 'nullable|in:short,medium,long,wire,curl',
            'pets.*.weight'     => 'nullable|numeric',
            'pets.*.color'      => 'nullable|string|max:50',
            'pets.*.medical_conditions'    => 'nullable|string',
            'pets.*.special_instructions'  => 'nullable|string',
            'pets.*.services'              => 'nullable|array',
            'pets.*.services.package'      => 'nullable|string',
            'pets.*.services.ala_carte'    => 'nullable|array',
            'pets.*.services.ala_carte.*'  => 'nullable|string',
        ]);

        $user = $request->user();
        $date = $request->booking_date;

        // ── Check if day is full ──────────────────────────
        $totalBooked = Booking::where('booking_date', $date)
            ->whereNotIn('status', ['cancelled'])
            ->count();

        if ($totalBooked >= 20) {
            return response()->json([
                'success' => false,
                'message' => 'Sorry, this date is fully booked. Please choose another date.',
            ], 422);
        }

        // ── Check if window is full ───────────────────────
        $windowBooked = Booking::where('window_id', $request->window_id)
            ->where('booking_date', $date)
            ->whereNotIn('status', ['cancelled'])
            ->count();

        $window = TimeWindow::find($request->window_id);

        if ($windowBooked >= $window->max_slots) {
            return response()->json([
                'success' => false,
                'message' => 'Sorry, this time slot is already full. Please choose another time.',
            ], 422);
        }

        // ── Check duplicate booking ───────────────────────
        $duplicate = Booking::where('user_id', $user->user_id)
            ->where('booking_date', $date)
            ->whereNotIn('status', ['cancelled', 'archived', 'no_show'])
            ->first();

        if ($duplicate) {
            return response()->json([
                'success' => false,
                'message' => 'You already have a booking on this date.',
                'errors'  => [
                    'existing_booking_id'  => $duplicate->booking_id,
                    'existing_booking_ref' => $duplicate->booking_reference,
                ],
            ], 422);
        }

        // ── Generate booking reference ────────────────────
        $dateStr  = Carbon::parse($date)->format('Ymd');
        $prefix   = 'BAC-' . $dateStr . '-';
        $maxRef   = Booking::where('booking_reference', 'like', $prefix . '%')->max('booking_reference');
        $lastCount = $maxRef ? ((int) substr($maxRef, -4)) + 1 : 1;
        $reference = $prefix . str_pad($lastCount, 4, '0', STR_PAD_LEFT);

        // ── Create the booking ────────────────────────────
        $booking = Booking::create([
            'booking_reference' => $reference,
            'user_id'           => $user->user_id,
            'window_id'         => $request->window_id,
            'booking_date'      => $date,
            'number_of_pets'    => $request->number_of_pets,
            'booking_type'      => 'online',
            'status'            => 'waiting_to_arrive',
            'special_notes'     => $request->special_notes,
            'total_amount'      => 0,
        ]);

        // ── Save pets ─────────────────────────────────────
        foreach ($request->pets as $petData) {
            $pet = null;

            // Reuse existing pet if pet_id is provided and belongs to this user
            if (!empty($petData['pet_id'])) {
                $pet = Pet::where('pet_id', $petData['pet_id'])
                          ->where('user_id', $user->user_id)
                          ->first();
            }

            // Create a new pet record if none was found
            if (!$pet) {
                $pet = Pet::create([
                    'user_id'            => $user->user_id,
                    'pet_name'           => $petData['pet_name'],
                    'species'            => $petData['species'] ?? 'Dog',
                    'breed'              => $petData['breed'] ?? null,
                    'weight'             => $petData['weight'] ?? null,
                    'color'              => $petData['color'] ?? null,
                    'size'               => $petData['size'] ?? null,
                    'fur_type'           => $petData['fur_type'] ?? null,
                    'medical_conditions' => $petData['medical_conditions'] ?? null,
                ]);
            }

            // Link pet to this booking
            $bookingPet = BookingPet::create([
                'booking_id'           => $booking->booking_id,
                'pet_id'               => $pet->pet_id,
                'special_instructions' => $petData['special_instructions'] ?? null,
            ]);

            // ── Save services for this pet ────────────────
            $petSize    = $petData['size'] ?? null;
            $slugsToSave = [];

            $packageSlug = $petData['services']['package'] ?? null;
            if ($packageSlug) {
                $slugsToSave[] = $packageSlug;
            }

            foreach ($petData['services']['ala_carte'] ?? [] as $slug) {
                if ($slug) $slugsToSave[] = $slug;
            }

            if (!empty($slugsToSave)) {
                $services = Service::whereIn('slug', $slugsToSave)->get()->keyBy('slug');

                foreach ($slugsToSave as $slug) {
                    $service = $services->get($slug);
                    if (!$service) continue;

                    $price = match ($petSize) {
                        'small'       => $service->price_small  ?? $service->base_price,
                        'medium'      => $service->price_medium ?? $service->base_price,
                        'large',
                        'extra_large' => $service->price_large  ?? $service->base_price,
                        default       => $service->base_price,
                    };

                    BookingService::create([
                        'booking_id'       => $booking->booking_id,
                        'booking_pet_id'   => $bookingPet->booking_pet_id ?? null,
                        'service_id'       => $service->service_id,
                        'addon_id'         => null,
                        'price_at_booking' => $price,
                    ]);
                }
            }
        }

        // ── Create notification for admin ─────────────────
        Notification::create([
            'type'       => 'booked',
            'booking_id' => $booking->booking_id,
            'message'    => "New booking {$reference} by {$user->first_name} {$user->last_name} on {$date} at {$window->window_label}.",
            'is_read'    => 0,
            'created_at' => now(),
        ]);

        return response()->json([
            'success'   => true,
            'message'   => 'Booking confirmed successfully!',
            'booking'   => [
                'booking_id'        => $booking->booking_id,
                'booking_reference' => $booking->booking_reference,
                'booking_date'      => $booking->booking_date,
                'window'            => $window->window_label,
                'status'            => $booking->status,
                'number_of_pets'    => $booking->number_of_pets,
            ],
        ], 201);
    }

    // ── GET BOOKING HISTORY ───────────────────────────────
    public function history(Request $request)
    {
        $bookings = Booking::where('user_id', $request->user()->user_id)
            ->with(['timeWindow', 'bookingPets.pet'])
            ->orderBy('booking_date', 'desc')
            ->get();

        $active  = $bookings->whereNotIn('status', ['archived'])->values();
        $history = $bookings->where('status', 'archived')->values();

        $format = function ($b) {
            $pets = ($b->bookingPets ?? collect())->map(fn($bp) => [
                'pet_name' => $bp->pet?->pet_name ?? '—',
                'breed'    => $bp->pet?->breed    ?? '—',
            ])->values();

            return [
                'booking_id'        => $b->booking_id,
                'booking_reference' => $b->booking_reference,
                'booking_date'      => $b->booking_date,
                'status'            => $b->status,
                'paid'              => (bool) $b->paid,
                'number_of_pets'    => $b->number_of_pets,
                'reschedule_count'  => $b->reschedule_count ?? 0,
                'cancel_count'      => $b->cancel_count     ?? 0,
                'special_notes'     => $b->special_notes,
                'time_window'        => $b->timeWindow ? [
                    'window_label' => $b->timeWindow->window_label,
                ] : null,
                'dropped_off_at'     => $b->dropped_off_at
                    ? \Carbon\Carbon::parse($b->dropped_off_at)->format('g:i A')
                    : null,
                'grooming_started_at' => $b->grooming_started_at
                    ? \Carbon\Carbon::parse($b->grooming_started_at)->format('g:i A')
                    : null,
                'grooming_finished_at' => $b->grooming_finished_at
                    ? \Carbon\Carbon::parse($b->grooming_finished_at)->format('g:i A')
                    : null,
                'pets'               => $pets,
            ];
        };

        return response()->json([
            'success'  => true,
            'bookings' => $active->map($format)->values(),
            'history'  => $history->map($format)->values(),
        ]);
    }

    // ── GET SINGLE BOOKING ────────────────────────────────
    public function show(Request $request, $id)
    {
        $booking = Booking::where('booking_id', $id)
            ->where('user_id', $request->user()->user_id)
            ->with(['timeWindow', 'bookingPets.pet'])
            ->first();

        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'Booking not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'booking' => $booking,
        ]);
    }

    // ── CANCEL A BOOKING ──────────────────────────────────
    public function cancel(Request $request)
    {
        $request->validate([
            'booking_id' => 'required|exists:bookings,booking_id',
            'reason'     => 'nullable|string',
        ]);

        $user    = $request->user();
        $booking = Booking::where('booking_id', $request->booking_id)
            ->where('user_id', $user->user_id)
            ->first();

        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'Booking not found.',
            ], 404);
        }

        if ($booking->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'This booking is already cancelled.',
            ], 422);
        }

        if ($booking->cancel_count >= 2) {
            return response()->json([
                'success' => false,
                'message' => 'You have reached the maximum number of cancellations (2) for this booking.',
            ], 422);
        }

        $booking->update([
            'status'              => 'cancelled',
            'cancellation_reason' => $request->reason,
            'cancel_count'        => $booking->cancel_count + 1,
        ]);

        Notification::create([
            'type'       => 'cancelled',
            'booking_id' => $booking->booking_id,
            'message'    => "Booking {$booking->booking_reference} was cancelled by {$user->first_name} {$user->last_name}.",
            'is_read'    => 0,
            'created_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Booking cancelled successfully.',
        ]);
    }

    // ── RESCHEDULE A BOOKING ──────────────────────────────
    public function reschedule(Request $request)
    {
        $request->validate([
            'booking_id'    => 'required|exists:bookings,booking_id',
            'new_date'      => 'required|date|after_or_equal:today',
            'new_window_id' => 'required|exists:time_windows,window_id',
        ]);

        $user    = $request->user();
        $booking = Booking::where('booking_id', $request->booking_id)
            ->where('user_id', $user->user_id)
            ->first();

        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'Booking not found.',
            ], 404);
        }

        if ($booking->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'A cancelled booking cannot be rescheduled.',
            ], 422);
        }

        if ($booking->reschedule_count >= 2) {
            return response()->json([
                'success' => false,
                'message' => 'You have reached the maximum number of reschedules (2) for this booking.',
            ], 422);
        }

        // Check that the target window is not full
        $newDate   = $request->new_date;
        $newWindow = TimeWindow::find($request->new_window_id);

        $windowBooked = Booking::where('window_id', $request->new_window_id)
            ->where('booking_date', $newDate)
            ->whereNotIn('status', ['cancelled'])
            ->where('booking_id', '!=', $booking->booking_id)
            ->count();

        if ($windowBooked >= $newWindow->max_slots) {
            return response()->json([
                'success' => false,
                'message' => 'Sorry, that time slot is already full. Please choose another.',
            ], 422);
        }

        $booking->update([
            'booking_date'     => $newDate,
            'window_id'        => $request->new_window_id,
            'reschedule_count' => $booking->reschedule_count + 1,
            'status'           => 'waiting_to_arrive',
        ]);

        Notification::create([
            'type'       => 'rescheduled',
            'booking_id' => $booking->booking_id,
            'message'    => "Booking {$booking->booking_reference} was rescheduled by {$user->first_name} {$user->last_name} to {$newDate} at {$newWindow->window_label}.",
            'is_read'    => 0,
            'created_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Booking rescheduled successfully.',
            'booking' => [
                'booking_reference' => $booking->booking_reference,
                'booking_date'      => $booking->booking_date,
                'window'            => $newWindow->window_label,
                'reschedule_count'  => $booking->reschedule_count,
            ],
        ]);
    }

    // ── GET USER'S SAVED PETS ─────────────────────────────
    public function getPets(Request $request)
    {
        $pets = Pet::where('user_id', $request->user()->user_id)
                   ->orderBy('created_at', 'desc')
                   ->get();

        return response()->json([
            'success' => true,
            'pets'    => $pets,
        ]);
    }
}