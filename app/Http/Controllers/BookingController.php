<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingPet;
use App\Models\BookingService;
use App\Models\ClinicClosure;
use App\Models\ClinicSetting;
use App\Models\GroomingClinicReferral;
use App\Models\Notification;
use App\Models\Pet;
use App\Models\Service;
use App\Models\TimeWindow;
use App\Models\User;
use App\Rules\ValidBreedCoat;
use App\Rules\ValidPetSize;
use App\Rules\ValidPetWeight;
use App\Services\AvailabilityTimeWindowService;
use App\Services\CustomerPreRegistrationAccessService;
use App\Services\GroomingPaymentReadinessService;
use App\Services\GroomingServicePriceResolver;
use App\Support\PetWeightSize;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BookingController extends Controller
{
    private const DAILY_CAPACITY = 20;

    private const MAX_PETS_PER_BOOKING = 10;

    private const MAX_PRE_REGISTRATION_DAYS_AHEAD = 2;

    // ── GET AVAILABLE TIME WINDOWS ────────────────────────
    public function getTimeslots(
        Request $request,
        AvailabilityTimeWindowService $timeWindows,
    ) {
        $date = $request->query('date', Carbon::today()->toDateString());
        $settings = ClinicSetting::current();
        $availability = $settings->serviceAvailability('grooming');
        $cutoffPassed = $settings->isSameDayPreRegistrationCutoffPassed('grooming', $date);

        $totalBooked = Booking::where('booking_date', $date)
            ->whereNotIn('status', ['cancelled'])
            ->sum('number_of_pets');

        $dayFull = $totalBooked >= self::DAILY_CAPACITY;
        $dailyRemaining = max(0, self::DAILY_CAPACITY - $totalBooked);

        $windows = $timeWindows->availableWindows($settings, 'grooming');

        $result = $windows->map(function ($window) use ($date, $dayFull, $dailyRemaining, $cutoffPassed) {
            $booked = Booking::where('window_id', $window->window_id)
                ->where('booking_date', $date)
                ->whereNotIn('status', ['cancelled'])
                ->sum('number_of_pets');

            return [
                'window_id' => $window->window_id,
                'window_label' => $window->displayLabel(),
                'start_time' => $window->start_time,
                'end_time' => $window->end_time,
                'max_slots' => $window->max_slots,
                'booked' => $booked,
                'remaining' => $dailyRemaining,
                'is_full' => $dayFull,
                'is_past' => $this->windowHasStarted($date, $window),
                'is_cutoff' => $cutoffPassed,
                'recommended' => false,
            ];
        });

        // ── AI FEATURE: Mark least congested as recommended ──
        $available = $result
            ->where('is_full', false)
            ->where('is_past', false)
            ->where('is_cutoff', false);
        if ($available->isNotEmpty()) {
            $minBooked = $available->min('booked');
            $recommended = $available->firstWhere('booked', $minBooked);

            $result = $result->map(function ($window) use ($recommended) {
                if ($window['window_id'] === $recommended['window_id']) {
                    $window['recommended'] = true;
                }

                return $window;
            });
        }

        // ── Return active time windows with daily capacity status ──────────────────
        return response()->json([
            'success' => true,
            'date' => $date,
            'day_full' => $dayFull,
            'total_booked' => $totalBooked,
            'capacity' => self::DAILY_CAPACITY,
            'cutoff_passed' => $cutoffPassed,
            'availability' => $availability,
            'windows' => $result->values(),
        ]);
    }

    // ── SUBMIT A BOOKING ──────────────────────────────────
    public function store(
        Request $request,
        GroomingServicePriceResolver $servicePrices,
        CustomerPreRegistrationAccessService $preRegistrationAccess,
    ) {
        $today = now()->toDateString();

        $validated = $request->validate([
            'booking_date' => 'required|date|after_or_equal:'.$today,
            'window_id' => 'required|exists:time_windows,window_id',
            'number_of_pets' => 'required|integer|min:1|max:'.self::MAX_PETS_PER_BOOKING,
            'special_notes' => 'nullable|string',
            'sedation_consent' => 'sometimes|boolean',
            'pets' => 'required|array|min:1|max:'.self::MAX_PETS_PER_BOOKING,
            'pets.*.pet_id' => 'nullable|integer|exists:pets,pet_id',
            'pets.*.pet_name' => 'required|string|max:100',
            'pets.*.species' => 'nullable|string|max:50',
            'pets.*.breed' => 'nullable|string|max:100',
            'pets.*.size' => ['nullable', 'in:small,medium,large,extra_large', new ValidPetSize],
            'pets.*.fur_type' => ['nullable', 'string', 'max:100', new ValidBreedCoat],
            'pets.*.weight' => ['nullable', 'numeric', new ValidPetWeight],
            'pets.*.color' => 'nullable|string|max:50',
            'pets.*.medical_conditions' => 'nullable|string',
            'pets.*.special_instructions' => 'nullable|string',
            'pets.*.services' => 'nullable|array',
            'pets.*.services.package' => 'nullable|string',
            'pets.*.services.ala_carte' => 'nullable|array',
            'pets.*.services.ala_carte.*' => 'nullable|string',
        ]);

        $validated['pets'] = array_map(
            fn (array $pet) => PetWeightSize::withComputedSize($pet),
            $validated['pets'],
        );
        $request->merge(['pets' => $validated['pets']]);

        return DB::transaction(function () use (
            $request,
            $servicePrices,
            $preRegistrationAccess,
        ) {

            $user = $request->user();
            User::query()->whereKey($user->user_id)->lockForUpdate()->first();

            $access = $preRegistrationAccess->forUser((int) $user->user_id);
            if (! $access['allowed']) {
                return response()->json([
                    'success' => false,
                    'code' => 'ongoing_pre_registration',
                    'message' => $access['message'],
                    'ongoing' => $access['ongoing'],
                ], 409);
            }

            $date = $request->booking_date;
            $petCount = (int) $request->number_of_pets;
            $sedationConsent = $request->boolean('sedation_consent');
            $settings = ClinicSetting::current();

            if ($settings->isSameDayPreRegistrationCutoffPassed('grooming', $date)) {
                $cutoffLabel = $settings
                    ->serviceAvailability('grooming')['pre_registration_cutoff_label'];

                return response()->json([
                    'success' => false,
                    'message' => "Same-day grooming pre-registration closes at {$cutoffLabel}. Please choose another date.",
                ], 422);
            }

            // ── Check if day is full ──────────────────────────
            $totalBooked = Booking::where('booking_date', $date)
                ->whereNotIn('status', ['cancelled'])
                ->sum('number_of_pets');

            if ($totalBooked + $petCount > self::DAILY_CAPACITY) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sorry, this date is fully booked. Please choose another date.',
                ], 422);
            }

            // ── Load selected time window ───────────────────────
            $window = TimeWindow::query()
                ->whereKey($request->window_id)
                ->where('is_active', 1)
                ->first();

            if (
                ! $window
                || ! $settings->isWindowWithinOperatingHours(
                    'grooming',
                    $window->start_time,
                    $window->end_time,
                )
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'The selected grooming time is not available under the current operating hours and cutoff.',
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
                    'errors' => [
                        'existing_booking_id' => $duplicate->booking_id,
                        'existing_booking_ref' => $duplicate->booking_reference,
                    ],
                ], 422);
            }

            // ── Generate booking reference ────────────────────
            $dateStr = Carbon::parse($date)->format('Ymd');
            $prefix = 'BAC-'.$dateStr.'-';
            $maxRef = Booking::where('booking_reference', 'like', $prefix.'%')->max('booking_reference');
            $lastCount = $maxRef ? ((int) substr($maxRef, -4)) + 1 : 1;
            $reference = $prefix.str_pad($lastCount, 4, '0', STR_PAD_LEFT);

            // ── Create the booking ────────────────────────────
            $booking = Booking::create([
                'booking_reference' => $reference,
                'user_id' => $user->user_id,
                'window_id' => $request->window_id,
                'booking_date' => $date,
                'number_of_pets' => $request->number_of_pets,
                'booking_type' => 'online',
                'status' => 'waiting_to_arrive',
                'special_notes' => $request->special_notes,
                'sedation_consent' => $sedationConsent,
                'sedation_consent_source' => $sedationConsent ? 'customer_online' : null,
                'sedation_consent_recorded_at' => $sedationConsent ? now() : null,
                'total_amount' => 0,
            ]);

            // ── Save pets ─────────────────────────────────────
            foreach ($request->pets as $petData) {
                $pet = null;

                // Reuse existing pet if pet_id is provided and belongs to this user
                if (! empty($petData['pet_id'])) {
                    $pet = Pet::where('pet_id', $petData['pet_id'])
                        ->where('user_id', $user->user_id)
                        ->first();
                }

                if ($pet) {
                    $pet->update([
                        'breed' => $petData['breed'] ?? $pet->breed,
                        'fur_type' => $petData['fur_type'] ?? $pet->fur_type,
                        'weight' => $petData['weight'] ?? $pet->weight,
                        'size' => $petData['size'] ?? $pet->size,
                    ]);
                }

                // Create a new pet record if none was found
                if (! $pet) {
                    $pet = Pet::create([
                        'user_id' => $user->user_id,
                        'pet_name' => $petData['pet_name'],
                        'species' => $petData['species'] ?? 'Dog',
                        'breed' => $petData['breed'] ?? null,
                        'weight' => $petData['weight'] ?? null,
                        'color' => $petData['color'] ?? null,
                        'size' => $petData['size'] ?? null,
                        'fur_type' => $petData['fur_type'] ?? null,
                        'medical_conditions' => $petData['medical_conditions'] ?? null,
                    ]);
                }

                // Link pet to this booking
                $bookingPetAttributes = [
                    'booking_id' => $booking->booking_id,
                    'pet_id' => $pet->pet_id,
                    'special_instructions' => $petData['special_instructions'] ?? null,
                ];
                if (Schema::hasColumn('booking_pets', 'registered_size')) {
                    $bookingPetAttributes['registered_size'] = $petData['size'] ?? $pet->size;
                }
                $bookingPet = BookingPet::create($bookingPetAttributes);

                // ── Save services for this pet ────────────────
                $petSize = $pet->size ?? $petData['size'] ?? null;
                $slugsToSave = [];

                $packageSlug = $petData['services']['package'] ?? null;
                if ($packageSlug) {
                    $slugsToSave[] = $packageSlug;
                }

                foreach ($petData['services']['ala_carte'] ?? [] as $slug) {
                    if ($slug) {
                        $slugsToSave[] = $slug;
                    }
                }

                if (! empty($slugsToSave)) {
                    $services = Service::whereIn('slug', $slugsToSave)->get()->keyBy('slug');

                    foreach ($slugsToSave as $slug) {
                        $service = $services->get($slug);
                        if (! $service) {
                            continue;
                        }

                        $price = $servicePrices->servicePrice($service, $petSize);

                        BookingService::create([
                            'booking_id' => $booking->booking_id,
                            'booking_pet_id' => $bookingPet->booking_pet_id ?? null,
                            'service_id' => $service->service_id,
                            'addon_id' => null,
                            'price_at_booking' => $price,
                        ]);
                    }
                }
            }

            // ── Create notification for admin ─────────────────
            $schedule = $this->notificationScheduleLabel($date, $window);

            Notification::create([
                'type' => 'booked',
                'booking_id' => $booking->booking_id,
                'message' => "New pre-registration {$reference} by {$user->first_name} {$user->last_name} on {$schedule}.",
                'is_read' => 0,
                'created_at' => now(),
            ]);

            // Reload database-managed timestamps so the confirmation uses the
            // authoritative booking creation time without changing the schedule.
            $booking->refresh();
            $createdAt = Carbon::parse(
                $booking->created_at,
                config('app.timezone')
            )->toIso8601String();

            return response()->json([
                'success' => true,
                'message' => 'Booking confirmed successfully!',
                'booking' => [
                    'booking_id' => $booking->booking_id,
                    'booking_reference' => $booking->booking_reference,
                    'booking_date' => $booking->booking_date,
                    'window' => $window->window_label,
                    'created_at' => $createdAt,
                    'status' => $booking->status,
                    'number_of_pets' => $booking->number_of_pets,
                ],
            ], 201);
        });
    }

    public function preRegistrationAccess(
        Request $request,
        CustomerPreRegistrationAccessService $preRegistrationAccess,
    ) {
        return response()->json([
            'success' => true,
            ...$preRegistrationAccess->forUser((int) $request->user()->user_id),
        ]);
    }

    // ── GET BOOKING HISTORY ───────────────────────────────
    public function history(Request $request)
    {
        $userId = $request->user()->user_id;
        $historyLimit = $request->has('history_limit')
            ? max(0, min(100, (int) $request->query('history_limit')))
            : null;
        $petId = null;

        if ($request->filled('pet_id')) {
            $request->validate([
                'pet_id' => ['integer', 'min:1'],
            ]);

            $petId = (int) $request->query('pet_id');
            $ownsPet = Pet::where('pet_id', $petId)
                ->where('user_id', $userId)
                ->exists();

            if (! $ownsPet) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pet not found.',
                ], 404);
            }
        }

        $relations = ['timeWindow', 'bookingPets.pet', 'bookingServices.service'];
        $hasCancellationAuditColumns = Schema::hasColumns('bookings', [
            'cancel_count',
            'cancellation_reason',
        ]);
        $hasReferralFoundation = Schema::hasTable('grooming_clinic_referrals');
        if ($hasReferralFoundation) {
            $relations[] = 'bookingPets.groomingClinicReferrals:id,booking_id,booking_pet_id,pet_id,status';
        }
        if (Schema::hasTable('payments')) {
            $relations['payments'] = fn ($query) => $query
                ->where('payment_status', 'paid')
                ->orderByDesc('paid_at');
        }

        $activeQuery = Booking::where('user_id', $userId)
            ->neverCancelled($hasCancellationAuditColumns)
            ->whereNotIn('status', ['archived']);

        if ($petId !== null) {
            $activeQuery->whereHas('bookingPets', fn ($query) => $query->where('pet_id', $petId));
        }

        $active = $activeQuery
            ->with($relations)
            ->orderBy('booking_date', 'desc')
            ->orderBy('booking_id', 'desc')
            ->get();

        $historyQuery = Booking::where('user_id', $userId)
            ->neverCancelled($hasCancellationAuditColumns)
            ->where('status', 'archived');

        if ($petId !== null) {
            $historyQuery->whereHas('bookingPets', fn ($query) => $query->where('pet_id', $petId));
        }

        $historyTotal = (clone $historyQuery)->count();

        $historyQuery
            ->with($relations)
            ->orderBy('booking_date', 'desc')
            ->orderBy('booking_id', 'desc');

        if ($historyLimit !== null) {
            $historyQuery->limit($historyLimit);
        }

        $history = $historyLimit === 0
            ? collect()
            : $historyQuery->get();

        $format = function ($b) use ($petId, $hasReferralFoundation) {
            $bookingPets = $b->bookingPets ?? collect();
            $showGroomingTracker = $this->shouldShowGroomingTracker(
                $bookingPets,
                $hasReferralFoundation,
            );
            $paymentSummary = (bool) $b->paid
                ? app(GroomingPaymentReadinessService::class)->summarize($b)
                : null;
            $paymentPetsById = collect($paymentSummary['pets'] ?? [])->keyBy('booking_pet_id');

            if ($petId !== null) {
                $bookingPets = $bookingPets->where('pet_id', $petId);
            }

            $pets = $bookingPets->map(function ($bp) use (
                $b,
                $paymentPetsById,
                $hasReferralFoundation,
            ) {
                $services = ($b->bookingServices ?? collect())
                    ->where('booking_pet_id', $bp->booking_pet_id)
                    ->map(fn ($bookingService) => [
                        'booking_service_id' => $bookingService->booking_service_id,
                        'service_id' => $bookingService->service_id,
                        'service_name' => $bookingService->service?->service_name,
                        'price_at_booking' => $bookingService->price_at_booking,
                    ])
                    ->values();

                $referredToClinic = $this->bookingPetIsReferredToClinic(
                    $bp,
                    $hasReferralFoundation,
                );
                $groomingFinished = $bp->grooming_state === BookingPet::GROOMING_STATE_FINISHED
                    || $bp->grooming_end_time !== null;
                $groomingStatus = match (true) {
                    in_array($b->status, ['cancelled', 'no_show'], true) => $b->status,
                    $referredToClinic => 'referred_to_clinic',
                    $bp->grooming_state === BookingPet::GROOMING_STATE_STOPPED => 'stopped',
                    $bp->grooming_state === BookingPet::GROOMING_STATE_PAUSED => 'paused',
                    $bp->grooming_end_time !== null => 'grooming_finished',
                    $bp->grooming_start_time !== null => 'in_progress',
                    default => $b->status,
                };
                $paymentPet = $paymentPetsById->get($bp->booking_pet_id);

                return [
                    'pet_id' => $bp->pet_id,
                    'booking_pet_id' => $bp->booking_pet_id,
                    'pet_name' => $bp->pet?->pet_name ?? '—',
                    'breed' => $bp->pet?->breed ?? '—',
                    'species' => $bp->pet?->species,
                    'size' => $bp->pet?->size,
                    'weight' => $bp->pet?->weight,
                    'registered_size' => $bp->registered_size,
                    'confirmed_size' => $bp->confirmed_size,
                    'grooming_status' => $groomingStatus,
                    'clinic_referred' => $referredToClinic,
                    'active_in_grooming' => ! $referredToClinic && ! $groomingFinished,
                    'grooming_started_at' => $bp->grooming_start_time
                        ? Carbon::parse($bp->grooming_start_time)->format('g:i A')
                        : null,
                    'grooming_finished_at' => $bp->grooming_end_time
                        ? Carbon::parse($bp->grooming_end_time)->format('g:i A')
                        : null,
                    'grooming_started_timestamp' => $bp->grooming_start_time?->toIso8601String(),
                    'grooming_finished_timestamp' => $bp->grooming_end_time?->toIso8601String(),
                    'services' => $services,
                    'payment_review' => $paymentPet
                        && ($paymentPet['payment_kind'] ?? null) === 'stopped_reviewed'
                        ? [
                            'status' => 'completed',
                            'decision' => $paymentPet['review_decision'],
                            'decision_label' => $paymentPet['review_decision_label'],
                            'original_amount' => $paymentPet['review_original_pet_subtotal'],
                            'final_amount' => $paymentPet['final_pet_charge'],
                            'adjustment' => $paymentPet['adjustment'],
                            'customer_explanation' => $paymentPet['customer_explanation'],
                            'reviewed_at' => $paymentPet['reviewed_at'],
                        ]
                        : null,
                ];
            })->values();

            $paidPayment = $b->relationLoaded('payments')
                ? $b->payments->first()
                : null;
            $customerPaymentSummary = null;
            if ($paymentSummary && $paidPayment) {
                $safePets = collect($paymentSummary['pets']);
                if ($petId !== null) {
                    $safePets = $safePets->where('pet_id', $petId);
                }

                $customerPaymentSummary = [
                    'status' => 'paid',
                    'final_booking_total' => $paymentSummary['final_booking_total'],
                    'amount_tendered' => $paidPayment->amount_tendered,
                    'change_amount' => $paidPayment->change_amount,
                    'payment_method' => $paidPayment->payment_method,
                    'payment_method_label' => (float) $paidPayment->total_amount === 0.0
                        ? 'No payment required'
                        : ucfirst((string) $paidPayment->payment_method),
                    'paid_at' => $paidPayment->paid_at?->toIso8601String(),
                    'pets' => $safePets->map(fn (array $pet) => [
                        'pet_id' => $pet['pet_id'],
                        'booking_pet_id' => $pet['booking_pet_id'],
                        'pet_name' => $pet['pet_name'],
                        'pet_species' => $pet['pet_species'],
                        'grooming_state' => $pet['grooming_state'],
                        'payment_kind' => $pet['payment_kind'],
                        'service_breakdown' => $pet['service_breakdown'],
                        'original_pet_subtotal' => $pet['original_pet_subtotal'],
                        'final_pet_charge' => $pet['final_pet_charge'],
                        'adjustment' => $pet['adjustment'],
                        'review_decision' => $pet['review_decision'],
                        'review_decision_label' => $pet['review_decision_label'],
                        'customer_explanation' => $pet['customer_explanation'],
                        'reviewed_at' => $pet['reviewed_at'],
                    ])->values()->all(),
                ];
            }

            return [
                'booking_id' => $b->booking_id,
                'booking_reference' => $b->booking_reference,
                'booking_type' => $b->booking_type,
                'booking_date' => $b->booking_date,
                'created_at' => $b->created_at
                    ? Carbon::parse($b->created_at, config('app.timezone'))->toIso8601String()
                    : null,
                'status' => $b->status,
                'show_grooming_tracker' => $showGroomingTracker,
                'paid' => (bool) $b->paid,
                'payment_summary' => $customerPaymentSummary,
                'number_of_pets' => $b->number_of_pets,
                'reschedule_count' => $b->reschedule_count ?? 0,
                'cancel_count' => $b->cancel_count ?? 0,
                'special_notes' => $b->special_notes,
                'time_window' => $b->timeWindow ? [
                    'window_id' => $b->timeWindow->window_id,
                    'window_label' => $b->timeWindow->window_label,
                ] : null,
                'dropped_off_at' => $b->dropped_off_at
                    ? Carbon::parse($b->dropped_off_at)->format('g:i A')
                    : null,
                'dropped_off_timestamp' => $b->dropped_off_at
                    ? Carbon::parse($b->dropped_off_at, config('app.timezone'))->toIso8601String()
                    : null,
                'grooming_started_at' => $b->grooming_started_at
                    ? Carbon::parse($b->grooming_started_at)->format('g:i A')
                    : null,
                'grooming_started_timestamp' => $b->grooming_started_at
                    ? Carbon::parse($b->grooming_started_at, config('app.timezone'))->toIso8601String()
                    : null,
                'grooming_finished_at' => $b->grooming_finished_at
                    ? Carbon::parse($b->grooming_finished_at)->format('g:i A')
                    : null,
                'grooming_finished_timestamp' => $b->grooming_finished_at
                    ? Carbon::parse($b->grooming_finished_at, config('app.timezone'))->toIso8601String()
                    : null,
                'pets' => $pets,
            ];
        };

        return response()->json([
            'success' => true,
            'bookings' => $active->map($format)->values(),
            'history' => $history->map($format)->values(),
            'history_total' => $historyTotal,
        ]);
    }

    private function shouldShowGroomingTracker($bookingPets, bool $hasReferralFoundation): bool
    {
        if (! $hasReferralFoundation) {
            return true;
        }

        $hasReferral = $bookingPets->contains(
            fn (BookingPet $bookingPet) => $this->bookingPetIsReferredToClinic(
                $bookingPet,
                $hasReferralFoundation,
            ),
        );

        if (! $hasReferral) {
            return true;
        }

        return $bookingPets->contains(function (BookingPet $bookingPet): bool {
            $finished = $bookingPet->grooming_state === BookingPet::GROOMING_STATE_FINISHED
                || $bookingPet->grooming_end_time !== null;
            $referred = $this->bookingPetIsReferredToClinic($bookingPet, true);

            return ! $finished && ! $referred;
        });
    }

    private function bookingPetIsReferredToClinic(
        BookingPet $bookingPet,
        bool $hasReferralFoundation,
    ): bool {
        return $hasReferralFoundation
            && $bookingPet->groomingClinicReferrals->contains(
                fn (GroomingClinicReferral $referral) => $referral->status
                    !== GroomingClinicReferral::STATUS_CANCELLED,
            );
    }

    private function activeGroomingPetQuery(string $date): Builder
    {
        $query = BookingPet::query()
            ->whereNull('grooming_end_time')
            ->where('grooming_state', '!=', BookingPet::GROOMING_STATE_FINISHED)
            ->whereHas('booking', function (Builder $booking) use ($date) {
                $booking->where('booking_date', $date)
                    ->whereIn('status', ['checked_in', 'in_progress']);
            });

        if (Schema::hasTable('grooming_clinic_referrals')) {
            $query->whereDoesntHave('groomingClinicReferrals', function (Builder $referral) {
                $referral->where('status', '!=', GroomingClinicReferral::STATUS_CANCELLED);
            });
        }

        return $query;
    }

    // Real-time client dashboard snapshot of today's grooming queue and capacity.
    public function groomingCapacity(Request $request)
    {
        $today = Carbon::today()->toDateString();
        $activeGroomingPets = $this->activeGroomingPetQuery($today);

        $queued = (clone $activeGroomingPets)
            ->where('grooming_state', BookingPet::GROOMING_STATE_NOT_STARTED)
            ->count();

        $inProgress = (clone $activeGroomingPets)
            ->where('grooming_state', BookingPet::GROOMING_STATE_IN_PROGRESS)
            ->count();
        $used = $queued + $inProgress;

        $readyForPickup = Booking::where('booking_date', $today)
            ->whereIn('status', ['for_payment', 'for_pickup', 'released'])
            ->count();

        $waiting = Booking::where('booking_date', $today)
            ->where('status', 'waiting_to_arrive')
            ->count();

        $completed = Booking::whereDate('grooming_finished_at', $today)
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->count();

        $capacity = self::DAILY_CAPACITY;
        $remaining = max(0, $capacity - $used);
        $percent = $capacity > 0 ? min(100, round(($used / $capacity) * 100)) : 0;

        return response()->json([
            'success' => true,
            'date' => $today,
            'capacity' => [
                'used' => $used,
                'max' => $capacity,
                'remaining' => $remaining,
                'percent' => $percent,
                'is_full' => $used >= $capacity,
            ],
            'queue' => [
                'active' => $queued + $inProgress,
                'queued' => $queued,
                'in_progress' => $inProgress,
                'ready_for_pickup' => $readyForPickup,
                'waiting' => $waiting,
                'completed_today' => $completed,
            ],
            'updated_at' => Carbon::now()->toIso8601String(),
        ]);
    }

    // ── GET SINGLE BOOKING ────────────────────────────────
    public function show(Request $request, $id)
    {
        $booking = Booking::where('booking_id', $id)
            ->where('user_id', $request->user()->user_id)
            ->with(['timeWindow', 'bookingPets.pet'])
            ->first();

        if (! $booking) {
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
            'reason' => 'nullable|string',
        ]);

        $user = $request->user();
        $booking = Booking::where('booking_id', $request->booking_id)
            ->where('user_id', $user->user_id)
            ->first();

        if (! $booking) {
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
            'status' => 'cancelled',
            'cancellation_reason' => $request->reason,
            'cancel_count' => $booking->cancel_count + 1,
        ]);

        Notification::create([
            'type' => 'cancelled',
            'booking_id' => $booking->booking_id,
            'message' => "Pre-registration {$booking->booking_reference} was cancelled by {$user->first_name} {$user->last_name}.",
            'is_read' => 0,
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
        $today = now()->toDateString();
        $lastAvailableDate = now()
            ->addDays(self::MAX_PRE_REGISTRATION_DAYS_AHEAD)
            ->toDateString();

        $request->validate([
            'booking_id' => 'required|exists:bookings,booking_id',
            'new_date' => 'required|date_format:Y-m-d|after_or_equal:'.$today
                .'|before_or_equal:'.$lastAvailableDate,
            'new_window_id' => 'required|exists:time_windows,window_id',
        ], [
            'new_date.before_or_equal' => 'Grooming can be pre-registered up to three days in advance.',
        ]);

        $user = $request->user();
        $booking = Booking::where('booking_id', $request->booking_id)
            ->where('user_id', $user->user_id)
            ->first();

        if (! $booking) {
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

        // Check that the target date still has enough daily capacity
        $newDate = $request->new_date;
        $settings = ClinicSetting::current();
        $newWindow = TimeWindow::query()
            ->whereKey($request->new_window_id)
            ->where('is_active', true)
            ->first();
        $petCount = (int) $booking->number_of_pets;

        if (
            $booking->booking_date === $newDate
            && (int) $booking->window_id === (int) $request->new_window_id
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Choose a different date or time slot from the current schedule.',
            ], 422);
        }

        if ($settings->isSameDayPreRegistrationCutoffPassed('grooming', $newDate)) {
            $cutoffLabel = $settings
                ->serviceAvailability('grooming')['pre_registration_cutoff_label'];

            return response()->json([
                'success' => false,
                'message' => "Same-day grooming pre-registration closes at {$cutoffLabel}. Please choose another date.",
            ], 422);
        }

        $closure = ClinicClosure::query()
            ->where('is_active', true)
            ->whereDate('start_date', '<=', $newDate)
            ->whereDate('end_date', '>=', $newDate)
            ->where(function ($query) use ($newDate) {
                $query->where('type', 'blocked_date')
                    ->orWhere(function ($stopToday) use ($newDate) {
                        $stopToday
                            ->where('type', 'stop_today')
                            ->whereDate('start_date', now()->toDateString())
                            ->whereDate('start_date', $newDate);
                    });
            })
            ->first();

        if ($closure) {
            return response()->json([
                'success' => false,
                'message' => $closure->reason
                    ?: 'The clinic is not accepting grooming pre-registrations on the selected date.',
            ], 422);
        }

        if (
            ! $newWindow
            || ! $settings->isWindowWithinOperatingHours(
                'grooming',
                $newWindow->start_time,
                $newWindow->end_time,
            )
        ) {
            return response()->json([
                'success' => false,
                'message' => 'The selected grooming time is not available under the current operating hours and cutoff.',
            ], 422);
        }

        if ($this->windowHasStarted($newDate, $newWindow)) {
            return response()->json([
                'success' => false,
                'message' => 'The selected grooming time has already passed.',
            ], 422);
        }

        $dayBooked = Booking::where('booking_date', $newDate)
            ->whereNotIn('status', ['cancelled'])
            ->where('booking_id', '!=', $booking->booking_id)
            ->sum('number_of_pets');

        if ($dayBooked + $petCount > self::DAILY_CAPACITY) {
            return response()->json([
                'success' => false,
                'message' => 'Sorry, this date is fully booked. Please choose another date.',
            ], 422);
        }

        $previousSchedule = $this->notificationScheduleLabel(
            (string) $booking->booking_date,
            TimeWindow::query()->find($booking->window_id),
        );
        $newSchedule = $this->notificationScheduleLabel($newDate, $newWindow);

        $booking->update([
            'booking_date' => $newDate,
            'window_id' => $request->new_window_id,
            'reschedule_count' => $booking->reschedule_count + 1,
            'status' => 'waiting_to_arrive',
        ]);

        Notification::create([
            'type' => 'rescheduled',
            'booking_id' => $booking->booking_id,
            'message' => "Pre-registration {$booking->booking_reference} was rescheduled by {$user->first_name} {$user->last_name} from {$previousSchedule} to {$newSchedule}.",
            'is_read' => 0,
            'created_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Booking rescheduled successfully.',
            'booking' => [
                'booking_reference' => $booking->booking_reference,
                'booking_date' => $booking->booking_date,
                'window' => $newWindow->displayLabel(),
                'reschedule_count' => $booking->reschedule_count,
            ],
        ]);
    }

    private function windowHasStarted(string $bookingDate, TimeWindow $window): bool
    {
        if ($bookingDate !== now()->toDateString()) {
            return false;
        }

        $startTime = substr((string) $window->start_time, 0, 8);
        $startsAt = Carbon::createFromFormat(
            'Y-m-d H:i:s',
            "{$bookingDate} {$startTime}",
            config('app.timezone'),
        );

        return $startsAt->lessThanOrEqualTo(now());
    }

    private function notificationScheduleLabel(string $date, ?TimeWindow $window): string
    {
        $scheduleDate = Carbon::parse($date);
        $dateLabel = $scheduleDate->year === now()->year
            ? $scheduleDate->format('M j')
            : $scheduleDate->format('M j, Y');
        $windowLabel = $window?->displayLabel();

        return $windowLabel ? "{$dateLabel} at {$windowLabel}" : $dateLabel;
    }
}
