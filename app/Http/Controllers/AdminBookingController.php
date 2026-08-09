<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingPet;
use App\Models\ClinicClosure;
use App\Models\ClinicSetting;
use App\Models\CustomerNotification;
use App\Models\GroomingClinicReferral;
use App\Models\GroomingMedicalConcern;
use App\Models\Notification;
use App\Models\Payment;
use App\Services\DailyPetQueue;
use App\Services\GroomingBookingWorkflowService;
use App\Services\GroomingClinicReferralAssessmentService;
use App\Services\GroomingPaymentReadinessService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

    private const ACTIVE_CLINIC_REFERRAL_STATUSES = [
        GroomingClinicReferral::STATUS_PENDING_CONSENT,
        GroomingClinicReferral::STATUS_PENDING_CLINIC_ACCEPTANCE,
        GroomingClinicReferral::STATUS_ACCEPTED,
        GroomingClinicReferral::STATUS_UNDER_CLINIC_REVIEW,
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

    private function activeGroomingPetCount(): int
    {
        $query = BookingPet::where(
            'grooming_state',
            BookingPet::GROOMING_STATE_IN_PROGRESS,
        )
            ->whereHas('booking', function ($query) {
                $query->whereIn('status', ['checked_in', 'in_progress']);
            });

        if (Schema::hasTable('grooming_clinic_referrals')) {
            $query->whereDoesntHave('groomingClinicReferrals', function (Builder $referral) {
                $referral->where('status', '!=', GroomingClinicReferral::STATUS_CANCELLED);
            });
        }

        return $query->count();
    }

    private function groomerCapacitySnapshot(): array
    {
        $groomersOnDuty = ClinicSetting::current()->groomers_on_duty;
        $activePets = $this->activeGroomingPetCount();

        return [
            'groomers_on_duty' => $groomersOnDuty,
            'active_pets' => $activePets,
            'available_slots' => max(0, $groomersOnDuty - $activePets),
            'is_full' => $activePets >= $groomersOnDuty,
        ];
    }

    private function groomerCapacityError(int $activePets, int $groomersOnDuty, int $requestedSlots = 1): array
    {
        $availableSlots = max(0, $groomersOnDuty - $activePets);
        $slotLabel = $availableSlots === 1 ? 'slot is' : 'slots are';
        $message = $availableSlots === 0
            ? "Groomer capacity is full ({$activePets} of {$groomersOnDuty} pets in progress). Finish a pet before starting another."
            : "Only {$availableSlots} groomer {$slotLabel} available, but {$requestedSlots} pets would be started.";

        return ['error' => [
            'message' => $message,
            'status' => 422,
        ]];
    }

    // ── GET BOOKINGS (split by status, filterable by date) ────────────
    public function index(Request $request)
    {
        $today = Carbon::today();
        $selectedDate = $request->query('date', $today->toDateString());
        $includeFuture = $request->boolean('include_future', false);

        // Validate the date; fall back to today if malformed
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
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
        $incomingQuery = Booking::whereBetween('booking_date', [
            $rangeStart->toDateString(),
            $rangeEnd->toDateString(),
        ])
            ->where('status', 'waiting_to_arrive');
        $this->withOwnerCardMedicalConcernCounts($incomingQuery);
        $incoming = $incomingQuery
            ->with(['user', 'timeWindow', 'bookingPets.pet', 'bookingServices.service'])
            ->orderByRaw('CASE WHEN booking_date = ? THEN 0 ELSE 1 END', [$rangeStart->toDateString()])
            ->orderBy('booking_date', 'asc')
            ->get()
            ->map(fn ($b) => $this->formatBooking($b));

        // DATE GUARD TEMPORARILY DISABLED FOR TESTING
        // Removed booking_date filter from active states so cards stay visible
        // regardless of the selected date. Restore ->where('booking_date', $selectedDate)
        // on each query below when re-enabling the date guard for production.
        $queuedQuery = Booking::where(function (Builder $booking) {
            $booking->where('status', 'checked_in')
                ->orWhere(function (Builder $actionOnly) {
                    $actionOnly->where('status', 'in_progress');
                    $this->whereOnlyActionableStoppedPaymentReviewRemains($actionOnly);
                });
        })
            ->where(function (Builder $booking) {
                $booking->whereDoesntHave('bookingPets', function (Builder $pet) {
                    $pet->whereNotNull('grooming_start_time');
                })->orWhere(function (Builder $actionOnly) {
                    $this->whereOnlyActionableStoppedPaymentReviewRemains($actionOnly);
                });
            });
        $this->retainOwnerCardsWithActiveGroomingPets($queuedQuery);
        $this->withOwnerCardMedicalConcernCounts($queuedQuery);
        $queued = $queuedQuery
            ->with(['user', 'walkin', 'timeWindow', 'bookingPets.pet', 'bookingServices.service'])
            ->orderBy('queue_number', 'asc')
            ->get()
            ->map(fn ($b) => $this->formatBooking($b));

        $inProgressQuery = Booking::where(function ($query) {
            $query->where('status', 'in_progress')
                ->orWhere(function ($partialBooking) {
                    $partialBooking->where('status', 'checked_in')
                        ->whereHas('bookingPets', function ($pet) {
                            $pet->whereNotNull('grooming_start_time');
                        })
                        ->whereHas('bookingPets', function ($pet) {
                            // Includes an unstarted queued sibling after a
                            // different pet in this booking has finished.
                            $pet->whereNull('grooming_end_time');
                        });
                });
        })->where(function (Builder $booking) {
            $booking->whereDoesntHave('bookingPets', function (Builder $pet) {
                $this->constrainActionableStoppedPaymentReviewPet($pet);
            })->orWhereHas('bookingPets', function (Builder $pet) {
                $pet->whereNull('grooming_end_time')
                    ->whereIn('grooming_state', [
                        BookingPet::GROOMING_STATE_NOT_STARTED,
                        BookingPet::GROOMING_STATE_IN_PROGRESS,
                        BookingPet::GROOMING_STATE_PAUSED,
                    ]);
            });
        });
        $this->retainOwnerCardsWithActiveGroomingPets($inProgressQuery);
        $this->withOwnerCardMedicalConcernCounts($inProgressQuery);
        $inProgress = $inProgressQuery
            ->with(['user', 'walkin', 'timeWindow', 'bookingPets.pet', 'bookingServices.service'])
            ->orderBy('queue_number', 'asc')
            ->get()
            ->map(function ($booking) {
                $formatted = $this->formatBooking($booking);
                // A partially started booking remains checked_in in storage, but
                // staff should manage the whole owner card from In Progress.
                $formatted['status'] = 'in-progress';

                return $formatted;
            });

        $forPayment = Booking::where('status', 'for_payment')
            ->with(['user', 'walkin', 'timeWindow', 'bookingPets.pet', 'bookingServices.service'])
            ->orderBy('queue_number', 'asc')
            ->get()
            ->map(fn ($b) => $this->formatBooking($b));

        $released = Booking::where('status', 'released')
            ->with(['user', 'walkin', 'timeWindow', 'bookingPets.pet', 'bookingServices.service'])
            ->orderBy('queue_number', 'asc')
            ->get()
            ->map(fn ($b) => $this->formatBooking($b));

        // Summary metrics (always based on today, not the filter date)
        $todayCompletedCount = Booking::whereDate('grooming_finished_at', $today->toDateString())
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->sum('number_of_pets');
        $todayIntakeCount = $this->dailyIntakeCount($today->toDateString());

        $weekStart = Carbon::now()->startOfWeek()->toDateString();
        $weekEnd = Carbon::now()->endOfWeek()->toDateString();
        $weekCount = Booking::whereBetween('booking_date', [$weekStart, $weekEnd])
            ->whereNotIn('status', ['cancelled'])
            ->sum('number_of_pets');
        $weekBookingCount = Booking::whereBetween('booking_date', [$weekStart, $weekEnd])
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
        $noShowWeekRate = $weekBookingCount > 0
            ? round(($noShowWeekCount / $weekBookingCount) * 100, 1)
            : 0;

        return response()->json([
            'success' => true,
            'incomingList' => $incoming->values(),
            'queuedList' => $queued->values(),
            'inProgressList' => $inProgress->values(),
            'forPaymentList' => $forPayment->values(),
            'releasedList' => $released->values(),
            'summary' => [
                'today' => $todayCompletedCount,
                'week' => $weekCount,
                'revenueToday' => (float) $revenueToday,
                'revenuePaymentCount' => $revenuePaymentCount,
                'noShowWeek' => $noShowWeekCount,
                'noShowWeekRate' => $noShowWeekRate,
            ],
            'recentActivity' => $this->recentActivity(),
            'capacity' => [
                'current' => $todayIntakeCount,
                'max' => self::MAX_CAPACITY,
            ],
            'groomerCapacity' => $this->groomerCapacitySnapshot(),
        ]);
    }

    /**
     * Hide a referred owner's card only when no unfinished grooming pet remains.
     */
    private function retainOwnerCardsWithActiveGroomingPets(Builder $query): Builder
    {
        if (! Schema::hasTable('grooming_clinic_referrals')) {
            return $query;
        }

        return $query->where(function (Builder $booking) {
            $booking
                ->whereDoesntHave('groomingClinicReferrals', function (Builder $referral) {
                    $referral->whereIn('status', self::ACTIVE_CLINIC_REFERRAL_STATUSES);
                })
                ->orWhereHas('bookingPets', function (Builder $pet) {
                    $pet->whereNull('grooming_end_time')
                        ->where('grooming_state', '!=', BookingPet::GROOMING_STATE_FINISHED)
                        ->whereDoesntHave('groomingClinicReferrals', function (Builder $referral) {
                            $referral->whereIn('status', self::ACTIVE_CLINIC_REFERRAL_STATUSES);
                        });
                });
        });
    }

    private function whereHasActionableStoppedPaymentReview(Builder $booking): Builder
    {
        return $booking->whereHas('bookingPets', function (Builder $pet) {
            $this->constrainActionableStoppedPaymentReviewPet($pet);
        });
    }

    private function whereOnlyActionableStoppedPaymentReviewRemains(Builder $booking): Builder
    {
        $this->whereHasActionableStoppedPaymentReview($booking);

        return $booking->whereDoesntHave('bookingPets', function (Builder $pet) {
            $pet->whereNull('grooming_end_time')
                ->whereIn('grooming_state', [
                    BookingPet::GROOMING_STATE_NOT_STARTED,
                    BookingPet::GROOMING_STATE_IN_PROGRESS,
                    BookingPet::GROOMING_STATE_PAUSED,
                ]);
        });
    }

    private function constrainActionableStoppedPaymentReviewPet(Builder $pet): Builder
    {
        $pet->where('grooming_state', BookingPet::GROOMING_STATE_STOPPED)
            ->whereNull('grooming_end_time');

        if (Schema::hasTable('grooming_stopped_payment_reviews')) {
            $pet->whereDoesntHave('groomingStoppedPaymentReview');
        }

        if (Schema::hasTable('grooming_clinic_referrals')) {
            $pet->whereDoesntHave('groomingClinicReferrals', function (Builder $referral) {
                $referral->whereIn('status', self::ACTIVE_CLINIC_REFERRAL_STATUSES);
            });
        }

        return $pet;
    }

    // ── CHECK IN ──────────────────────────────────────────
    // waiting_to_arrive → checked_in, assigns queue number
    public function checkIn($id)
    {
        $queueDate = now()->toDateString();
        $result = app(DailyPetQueue::class)->runForDate($queueDate, function () use ($id, $queueDate) {
            $booking = Booking::with(['user', 'timeWindow', 'bookingPets.pet'])
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if (! $booking) {
                return ['error' => ['message' => 'Booking not found.', 'status' => 404]];
            }

            if ($booking->status !== 'waiting_to_arrive') {
                return ['error' => ['message' => 'Booking is not in waiting status.', 'status' => 422]];
            }

            if ($booking->booking_date !== $queueDate) {
                return ['error' => ['message' => 'Check-in is only allowed on the day of the appointment.', 'status' => 422]];
            }

            $queueNumber = ((int) Booking::where('booking_date', $queueDate)
                ->whereNotNull('queue_number')
                ->max('queue_number')) + 1;

            $booking->update([
                'status' => 'checked_in',
                'queue_number' => $queueNumber,
                'dropped_off_at' => now(),
            ]);

            app(DailyPetQueue::class)->assignBookingPets($booking, $queueDate);

            return ['queue_number' => $queueNumber];
        });

        if (isset($result['error'])) {
            return response()->json([
                'success' => false,
                'message' => $result['error']['message'],
            ], $result['error']['status']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Customer checked in successfully.',
            'queue_number' => $result['queue_number'],
        ]);
    }

    // Undo check-in completely: restore the pre-arrival booking and release all queue fields.
    public function revertCheckIn($id)
    {
        $result = DB::transaction(function () use ($id) {
            $booking = Booking::whereKey($id)->lockForUpdate()->first();

            if (! $booking) {
                return ['error' => ['message' => 'Booking not found.', 'status' => 404]];
            }

            if ($booking->status !== 'checked_in') {
                return ['error' => [
                    'message' => 'Only a queued booking can be reverted to Incoming.',
                    'status' => 422,
                ]];
            }

            $bookingPets = BookingPet::where('booking_id', $booking->booking_id)
                ->lockForUpdate()
                ->get();
            $hasGroomingActivity = $bookingPets->contains(
                fn (BookingPet $bookingPet) => $bookingPet->grooming_start_time !== null
                    || $bookingPet->grooming_end_time !== null
                    || in_array($bookingPet->grooming_state, [
                        BookingPet::GROOMING_STATE_IN_PROGRESS,
                        BookingPet::GROOMING_STATE_PAUSED,
                        BookingPet::GROOMING_STATE_STOPPED,
                        BookingPet::GROOMING_STATE_FINISHED,
                    ], true),
            );

            if ($hasGroomingActivity) {
                return ['error' => [
                    'message' => 'Check-in cannot be reverted after grooming activity has started.',
                    'status' => 422,
                ]];
            }

            $booking->update([
                'status' => 'waiting_to_arrive',
                'queue_number' => null,
                'dropped_off_at' => null,
            ]);

            BookingPet::whereIn('booking_pet_id', $bookingPets->pluck('booking_pet_id'))
                ->update([
                    'pet_queue_date' => null,
                    'pet_queue_number' => null,
                ]);

            return ['booking_id' => $booking->booking_id];
        });

        if (isset($result['error'])) {
            return response()->json([
                'success' => false,
                'message' => $result['error']['message'],
            ], $result['error']['status']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Check-in reverted. The appointment is Incoming again.',
            'booking_id' => $result['booking_id'],
            'status' => 'waiting_to_arrive',
        ]);
    }

    // ── START GROOMING ────────────────────────────────────
    // checked_in → in_progress
    public function startGrooming($id)
    {
        $result = DB::transaction(function () use ($id) {
            $settings = ClinicSetting::current(lockForUpdate: true);
            $booking = Booking::whereKey($id)->lockForUpdate()->first();

            if (! $booking) {
                return ['error' => ['message' => 'Booking not found.', 'status' => 404]];
            }

            if ($booking->status !== 'checked_in') {
                return ['error' => ['message' => 'Booking must be checked in first.', 'status' => 422]];
            }

            $bookingPets = BookingPet::where('booking_id', $booking->booking_id)
                ->lockForUpdate()
                ->get();
            $petsToStart = $bookingPets->filter(
                fn (BookingPet $bookingPet) => $bookingPet->grooming_state === BookingPet::GROOMING_STATE_NOT_STARTED
                    && $bookingPet->grooming_start_time === null
                    && $bookingPet->grooming_end_time === null,
            );

            if ($petsToStart->isEmpty()) {
                return ['error' => [
                    'message' => 'No eligible not-started pets are available to begin grooming.',
                    'status' => 422,
                ]];
            }

            $activePets = $this->activeGroomingPetCount();

            if ($activePets + $petsToStart->count() > $settings->groomers_on_duty) {
                return $this->groomerCapacityError(
                    $activePets,
                    $settings->groomers_on_duty,
                    $petsToStart->count(),
                );
            }

            $startedAt = now();
            if ($petsToStart->isNotEmpty()) {
                BookingPet::whereIn('booking_pet_id', $petsToStart->pluck('booking_pet_id'))
                    ->update([
                        'grooming_start_time' => $startedAt,
                        'grooming_state' => BookingPet::GROOMING_STATE_IN_PROGRESS,
                    ]);
            }

            $booking->update([
                'status' => 'in_progress',
                'grooming_started_at' => $booking->grooming_started_at ?? $startedAt,
            ]);
            $booking->load('user', 'bookingPets.pet');

            return ['booking' => $booking];
        });

        if (isset($result['error'])) {
            return response()->json([
                'success' => false,
                'message' => $result['error']['message'],
            ], $result['error']['status']);
        }

        $booking = $result['booking'];

        // Notify the customer that grooming has started
        if ($booking->user) {
            $petName = $this->petNames($booking);
            $petVerb = $this->hasMultiplePets($booking) ? 'have' : 'has';
            CustomerNotification::create([
                'user_id' => $booking->user->user_id,
                'booking_id' => $booking->booking_id,
                'type' => 'grooming_started',
                'message' => "Great news! {$petName} {$petVerb} Started Grooming. We'll let you know as soon as they're ready for pickup!",
                'is_read' => false,
                'created_at' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Grooming session started.',
        ]);
    }

    // Starts one pet while the owner booking remains checked_in until every pet has started.
    // The schedule feed surfaces partially started owner cards in In Progress.
    // No migration is needed: booking_pets.grooming_start_time already stores this per-pet state.
    public function startPetGrooming($id, $bookingPetId)
    {
        $result = DB::transaction(function () use ($id, $bookingPetId) {
            $settings = ClinicSetting::current(lockForUpdate: true);
            $booking = Booking::whereKey($id)->lockForUpdate()->first();

            if (! $booking) {
                return ['error' => ['message' => 'Booking not found.', 'status' => 404]];
            }

            if ($booking->status !== 'checked_in') {
                return ['error' => ['message' => 'Booking must be queued before a pet can start grooming.', 'status' => 422]];
            }

            $bookingPet = BookingPet::where('booking_id', $booking->booking_id)
                ->whereKey($bookingPetId)
                ->lockForUpdate()
                ->first();

            if (! $bookingPet) {
                return ['error' => ['message' => 'Pet is not part of this booking.', 'status' => 404]];
            }

            if (
                $bookingPet->grooming_state !== BookingPet::GROOMING_STATE_NOT_STARTED
                || $bookingPet->grooming_start_time !== null
                || $bookingPet->grooming_end_time !== null
            ) {
                return ['error' => [
                    'message' => 'Only a not-started pet can begin grooming.',
                    'status' => 422,
                ]];
            }

            $activePets = $this->activeGroomingPetCount();
            if ($activePets >= $settings->groomers_on_duty) {
                return $this->groomerCapacityError($activePets, $settings->groomers_on_duty);
            }

            $startedAt = now();
            $bookingPet->update([
                'grooming_start_time' => $startedAt,
                'grooming_state' => BookingPet::GROOMING_STATE_IN_PROGRESS,
            ]);

            $remainingPets = BookingPet::where('booking_id', $booking->booking_id)
                ->where('grooming_state', BookingPet::GROOMING_STATE_NOT_STARTED)
                ->count();

            $bookingUpdates = [];
            if (! $booking->grooming_started_at) {
                $bookingUpdates['grooming_started_at'] = $startedAt;
            }
            if ($remainingPets === 0) {
                $bookingUpdates['status'] = 'in_progress';
            }
            if ($bookingUpdates) {
                $booking->update($bookingUpdates);
            }

            $booking->loadMissing(['user', 'bookingPets.pet']);
            $petName = $bookingPet->pet()->value('pet_name') ?: 'your pet';

            if ($booking->user) {
                CustomerNotification::create([
                    'user_id' => $booking->user->user_id,
                    'booking_id' => $booking->booking_id,
                    'type' => 'grooming_started',
                    'message' => "Great news! {$petName} has Started Grooming. We'll let you know as soon as they're ready for pickup!",
                    'is_read' => false,
                    'created_at' => $startedAt,
                ]);
            }

            return [
                'booking' => $booking,
                'bookingPet' => $bookingPet,
                'petName' => $petName,
                'allPetsStarted' => $remainingPets === 0,
                'remainingPets' => $remainingPets,
                'startedAt' => $startedAt,
            ];
        });

        if (isset($result['error'])) {
            return response()->json([
                'success' => false,
                'message' => $result['error']['message'],
            ], $result['error']['status']);
        }

        return response()->json([
            'success' => true,
            'message' => "Grooming started for {$result['petName']}.",
            'booking_id' => $result['booking']->booking_id,
            'booking_pet_id' => $result['bookingPet']->booking_pet_id,
            'started_at' => $result['startedAt']->toIso8601String(),
            'grooming_state' => BookingPet::GROOMING_STATE_IN_PROGRESS,
            'all_pets_started' => $result['allPetsStarted'],
            'remaining_pets' => $result['remainingPets'],
            'booking_status' => $result['allPetsStarted'] ? 'in_progress' : 'checked_in',
        ]);
    }

    // Undo all grooming starts for an owner card and restore its queued state.
    public function revertStartGrooming($id)
    {
        $result = DB::transaction(function () use ($id) {
            $booking = Booking::whereKey($id)->lockForUpdate()->first();

            if (! $booking) {
                return ['error' => ['message' => 'Booking not found.', 'status' => 404]];
            }

            if (! in_array($booking->status, ['checked_in', 'in_progress'], true)) {
                return ['error' => [
                    'message' => 'Only an in-progress booking can be reverted to Queued.',
                    'status' => 422,
                ]];
            }

            $bookingPets = BookingPet::where('booking_id', $booking->booking_id)
                ->lockForUpdate()
                ->get();
            $startedPets = $bookingPets->filter(
                fn (BookingPet $bookingPet) => $bookingPet->grooming_start_time !== null
                    || $bookingPet->grooming_state === BookingPet::GROOMING_STATE_IN_PROGRESS,
            );

            if ($startedPets->isEmpty()) {
                return ['error' => [
                    'message' => 'This booking has no grooming start to revert.',
                    'status' => 422,
                ]];
            }

            $hasLaterGroomingActivity = $bookingPets->contains(
                fn (BookingPet $bookingPet) => $bookingPet->grooming_end_time !== null
                    || in_array($bookingPet->grooming_state, [
                        BookingPet::GROOMING_STATE_PAUSED,
                        BookingPet::GROOMING_STATE_STOPPED,
                        BookingPet::GROOMING_STATE_FINISHED,
                    ], true),
            );

            if ($hasLaterGroomingActivity) {
                return ['error' => [
                    'message' => 'Grooming cannot be reverted after a later grooming action has occurred.',
                    'status' => 422,
                ]];
            }

            BookingPet::whereIn('booking_pet_id', $startedPets->pluck('booking_pet_id'))
                ->update([
                    'grooming_start_time' => null,
                    'grooming_state' => BookingPet::GROOMING_STATE_NOT_STARTED,
                ]);

            $booking->update([
                'status' => 'checked_in',
                'grooming_started_at' => null,
            ]);

            CustomerNotification::where('booking_id', $booking->booking_id)
                ->where('type', 'grooming_started')
                ->delete();

            return ['booking_id' => $booking->booking_id];
        });

        if (isset($result['error'])) {
            return response()->json([
                'success' => false,
                'message' => $result['error']['message'],
            ], $result['error']['status']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Grooming start reverted. The appointment is Queued again.',
            'booking_id' => $result['booking_id'],
            'status' => 'checked_in',
        ]);
    }

    // ── MARK DONE ─────────────────────────────────────────
    // in_progress → for_payment  (or archived if already paid early)
    public function markDone($id)
    {
        $result = DB::transaction(function () use ($id) {
            $booking = Booking::whereKey($id)->lockForUpdate()->first();

            if (! $booking) {
                return ['error' => ['message' => 'Booking not found.', 'status' => 404]];
            }

            if ($booking->status !== 'in_progress') {
                return ['error' => ['message' => 'Booking must be in progress first.', 'status' => 422]];
            }

            $bookingPets = BookingPet::where('booking_id', $booking->booking_id)
                ->lockForUpdate()
                ->get();
            $unfinishedPets = $bookingPets->where(
                'grooming_state',
                '!=',
                BookingPet::GROOMING_STATE_FINISHED,
            );

            if ($unfinishedPets->isEmpty()) {
                return ['error' => [
                    'message' => 'Every pet in this booking is already marked as finished.',
                    'status' => 422,
                ]];
            }

            $paymentSummary = $this->paymentReadiness()->summarize($booking, true);
            $paymentPetsById = collect($paymentSummary['pets'])->keyBy('booking_pet_id');
            $petsToFinish = $unfinishedPets->filter(
                fn (BookingPet $bookingPet) => $bookingPet->grooming_state
                    === BookingPet::GROOMING_STATE_IN_PROGRESS,
            );
            $hasIneligiblePet = $unfinishedPets->contains(function (BookingPet $bookingPet) use ($paymentPetsById) {
                if ($bookingPet->grooming_state === BookingPet::GROOMING_STATE_IN_PROGRESS) {
                    return $bookingPet->grooming_start_time === null
                        || $bookingPet->grooming_end_time !== null;
                }

                return ! (bool) ($paymentPetsById->get($bookingPet->booking_pet_id)['payment_ready'] ?? false);
            });

            if ($hasIneligiblePet) {
                return ['error' => [
                    'message' => 'All unfinished pets must be in progress, or stopped with a completed exact payment review. Paused and unreviewed stopped pets block completion.',
                    'status' => 422,
                ]];
            }

            if ($petsToFinish->isEmpty()) {
                return ['error' => [
                    'message' => 'There are no in-progress pets to finish normally.',
                    'status' => 422,
                ]];
            }

            $finishedAt = now();
            BookingPet::whereIn(
                'booking_pet_id',
                $petsToFinish->pluck('booking_pet_id'),
            )->update([
                'grooming_end_time' => $finishedAt,
                'grooming_state' => BookingPet::GROOMING_STATE_FINISHED,
            ]);

            return [
                'completion' => $this->completeGroomingBooking(
                    $booking,
                    $finishedAt,
                ),
            ];
        });

        if (isset($result['error'])) {
            return response()->json([
                'success' => false,
                'message' => $result['error']['message'],
            ], $result['error']['status']);
        }

        return response()->json([
            'success' => true,
            'message' => $result['completion']['message'],
            'booking_status' => $result['completion']['status'],
        ]);
    }

    // ── MARK ONE PET DONE ─────────────────────────────────
    // Finishes one pet and keeps the owner booking In Progress until every pet is done.
    // booking_pets.grooming_end_time already provides the required per-pet state.
    public function markPetDone($id, $bookingPetId)
    {
        $result = DB::transaction(function () use ($id, $bookingPetId) {
            $booking = Booking::whereKey($id)->lockForUpdate()->first();

            if (! $booking) {
                return ['error' => ['message' => 'Booking not found.', 'status' => 404]];
            }

            if (! in_array($booking->status, ['checked_in', 'in_progress'], true)) {
                return ['error' => ['message' => 'Booking must be queued or in progress first.', 'status' => 422]];
            }

            $bookingPet = BookingPet::where('booking_id', $booking->booking_id)
                ->whereKey($bookingPetId)
                ->lockForUpdate()
                ->first();

            if (! $bookingPet) {
                return ['error' => ['message' => 'Pet is not part of this booking.', 'status' => 404]];
            }

            if (
                $bookingPet->grooming_state !== BookingPet::GROOMING_STATE_IN_PROGRESS
                || $bookingPet->grooming_start_time === null
                || $bookingPet->grooming_end_time !== null
            ) {
                return ['error' => [
                    'message' => 'Only a pet currently in progress can be finished. Resume a paused pet first; stopped pets cannot be finished normally.',
                    'status' => 422,
                ]];
            }

            $finishedAt = now();
            $bookingPet->update([
                'grooming_end_time' => $finishedAt,
                'grooming_state' => BookingPet::GROOMING_STATE_FINISHED,
            ]);

            $paymentSummary = $this->paymentReadiness()->summarize($booking, true);
            $remainingPets = collect($paymentSummary['pets'])
                ->where('payment_ready', false)
                ->count();
            $petName = $bookingPet->pet()->value('pet_name') ?: 'Pet';
            $completion = null;

            if ($paymentSummary['payment_ready']) {
                $completion = $this->completeGroomingBooking($booking, $finishedAt);
            } else {
                $booking->loadMissing('user');

                if ($booking->user) {
                    CustomerNotification::create([
                        'user_id' => $booking->user->user_id,
                        'booking_id' => $booking->booking_id,
                        'type' => 'grooming_finished',
                        'message' => "{$petName} is Finished with grooming. We'll keep you updated on the rest of the appointment.",
                        'is_read' => false,
                        'created_at' => $finishedAt,
                    ]);
                }
            }

            return [
                'booking' => $booking,
                'bookingPet' => $bookingPet,
                'petName' => $petName,
                'allPetsPaymentReady' => $paymentSummary['payment_ready'],
                'remainingPets' => $remainingPets,
                'finishedAt' => $finishedAt,
                'completion' => $completion,
            ];
        });

        if (isset($result['error'])) {
            return response()->json([
                'success' => false,
                'message' => $result['error']['message'],
            ], $result['error']['status']);
        }

        $allPetsFinished = $result['allPetsPaymentReady'];
        $remainingPets = $result['remainingPets'];
        $remainingLabel = $remainingPets === 1 ? 'pet remains' : 'pets remain';

        return response()->json([
            'success' => true,
            'message' => $allPetsFinished
                ? $result['completion']['message']
                : "Grooming finished for {$result['petName']}. {$remainingPets} {$remainingLabel} in progress.",
            'booking_id' => $result['booking']->booking_id,
            'booking_pet_id' => $result['bookingPet']->booking_pet_id,
            'finished_at' => $result['finishedAt']->toIso8601String(),
            'grooming_state' => BookingPet::GROOMING_STATE_FINISHED,
            'all_pets_finished' => $allPetsFinished,
            'all_pets_payment_ready' => $allPetsFinished,
            'remaining_pets' => $remainingPets,
            'booking_status' => $allPetsFinished
                ? $result['completion']['status']
                : $result['booking']->status,
        ]);
    }

    private function completeGroomingBooking(Booking $booking, Carbon $finishedAt): array
    {
        $booking->loadMissing(['user', 'bookingPets.pet']);
        $ownerName = trim(($booking->user?->first_name ?? '').' '.($booking->user?->last_name ?? ''));
        $readySubject = $this->hasMultiplePets($booking) ? 'pets are' : 'pet is';

        if ($booking->user) {
            CustomerNotification::create([
                'user_id' => $booking->user->user_id,
                'booking_id' => $booking->booking_id,
                'type' => 'ready_for_pickup',
                'message' => "Your {$readySubject} now Ready for Pickup and looking fabulous! Please come to the clinic to pick them up.",
                'is_read' => false,
                'created_at' => $finishedAt,
            ]);
        }

        if ($booking->paid) {
            $booking->update([
                'status' => 'released',
                'grooming_finished_at' => $finishedAt,
            ]);

            return [
                'status' => 'released',
                'message' => 'Grooming done. Customer notified for pickup (early payment on file).',
            ];
        }

        $booking->update([
            'status' => 'for_payment',
            'grooming_finished_at' => $finishedAt,
        ]);

        Notification::create([
            'type' => 'payment_due',
            'booking_id' => $booking->booking_id,
            'message' => "Grooming done for {$ownerName}. Pet is ready - please collect payment.",
            'is_read' => false,
            'created_at' => $finishedAt,
        ]);

        return [
            'status' => 'for_payment',
            'message' => 'Grooming done. Customer notified for pickup and payment.',
        ];
    }

    // ── MARK PICKED UP ────────────────────────────────────
    // released → archived + customer notification
    public function markPickedUp($id)
    {
        $result = DB::transaction(function () use ($id) {
            $booking = Booking::query()->whereKey($id)->lockForUpdate()->first();
            if (! $booking) {
                return ['error' => ['message' => 'Booking not found.', 'status' => 404]];
            }
            if ($booking->status !== 'released') {
                return ['error' => ['message' => 'Booking must be in Released status.', 'status' => 422]];
            }
            if (! (bool) $booking->paid) {
                return ['error' => ['message' => 'Payment must be completed before physical pickup.', 'status' => 422]];
            }

            $paymentSummary = $this->paymentReadiness()->summarize($booking, true);
            if (! $paymentSummary['payment_ready']) {
                return ['error' => [
                    'message' => 'Pickup completion is unavailable. '.$paymentSummary['payment_blocked_reason'],
                    'status' => 422,
                ]];
            }

            $pickupBlockedReason = app(GroomingClinicReferralAssessmentService::class)
                ->pickupBlockedReason($booking, true);
            if ($pickupBlockedReason) {
                return ['error' => [
                    'message' => $pickupBlockedReason,
                    'status' => 409,
                ]];
            }

            $booking->loadMissing(['user', 'bookingPets.pet']);
            $petName = $this->petNames($booking);
            $petVerb = $this->hasMultiplePets($booking) ? 'have' : 'has';
            $booking->update(['status' => 'archived', 'archived_at' => now()]);

            if ($booking->user) {
                CustomerNotification::create([
                    'user_id' => $booking->user->user_id,
                    'booking_id' => $booking->booking_id,
                    'type' => 'picked_up',
                    'message' => "{$petName} {$petVerb} been released. Thank you for visiting Bethlehem Animal Clinic!",
                    'is_read' => false,
                    'created_at' => now(),
                ]);
            }

            return ['booking' => $booking];
        });

        if (isset($result['error'])) {
            return response()->json([
                'success' => false,
                'message' => $result['error']['message'],
            ], $result['error']['status']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Booking marked as picked up and archived.',
        ]);
    }

    // ── ARCHIVE ───────────────────────────────────────────
    // for_pickup (legacy) → archived
    // Cancel a booking, remove it from active capacity, and notify its customer.
    public function cancel(Request $request, $id)
    {
        $validated = $request->validate([
            'cancellation_reason' => ['nullable', 'string', 'max:500'],
        ]);
        $customerReason = trim((string) ($validated['cancellation_reason'] ?? ''));

        $result = DB::transaction(function () use ($id, $customerReason) {
            $booking = Booking::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if (! $booking) {
                return ['error' => ['message' => 'Booking not found.', 'status' => 404]];
            }

            if (in_array($booking->status, ['cancelled', 'archived', 'no_show'], true)) {
                return ['error' => [
                    'message' => 'This booking cannot be cancelled.',
                    'status' => 422,
                ]];
            }

            $booking->update([
                'status' => 'cancelled',
                'cancellation_reason' => $customerReason !== ''
                    ? $customerReason
                    : 'Cancelled by clinic staff.',
                'cancel_count' => ($booking->cancel_count ?? 0) + 1,
            ]);

            $booking->loadMissing('user');
            $customerNotified = false;

            if ($booking->user) {
                $reference = trim((string) $booking->booking_reference);
                $subject = $reference !== ''
                    ? "Your grooming pre-registration {$reference}"
                    : 'Your grooming pre-registration';
                $message = "{$subject} has been cancelled by the clinic.";

                if ($customerReason !== '') {
                    $message .= " Reason: {$customerReason}";
                }

                CustomerNotification::create([
                    'user_id' => $booking->user->user_id,
                    'booking_id' => $booking->booking_id,
                    'type' => CustomerNotification::TYPE_BOOKING_CANCELLED,
                    'message' => $message,
                    'is_read' => false,
                    'created_at' => now(),
                ]);
                $customerNotified = true;
            }

            return ['customer_notified' => $customerNotified];
        });

        if (isset($result['error'])) {
            return response()->json([
                'success' => false,
                'message' => $result['error']['message'],
            ], $result['error']['status']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Booking cancelled successfully.',
            'customer_notified' => $result['customer_notified'],
        ]);
    }

    public function archive($id)
    {
        $result = DB::transaction(function () use ($id) {
            $booking = Booking::query()->whereKey($id)->lockForUpdate()->first();
            if (! $booking) {
                return ['error' => ['message' => 'Booking not found.', 'status' => 404]];
            }
            if (! in_array($booking->status, ['for_pickup', 'released'], true)) {
                return ['error' => [
                    'message' => 'Only For Pickup or Released bookings can be archived here.',
                    'status' => 422,
                ]];
            }
            if (! (bool) $booking->paid) {
                return ['error' => [
                    'message' => 'Payment must be completed before the booking can be archived.',
                    'status' => 422,
                ]];
            }

            $paymentSummary = $this->paymentReadiness()->summarize($booking, true);
            if (! $paymentSummary['payment_ready']) {
                return ['error' => [
                    'message' => 'Final pickup progression is unavailable. '
                        .$paymentSummary['payment_blocked_reason'],
                    'status' => 422,
                ]];
            }

            $pickupBlockedReason = app(GroomingClinicReferralAssessmentService::class)
                ->pickupBlockedReason($booking, true);
            if ($pickupBlockedReason) {
                return ['error' => [
                    'message' => $pickupBlockedReason,
                    'status' => 409,
                ]];
            }

            $booking->update(['status' => 'archived', 'archived_at' => now()]);

            return ['booking' => $booking];
        });

        if (isset($result['error'])) {
            return response()->json([
                'success' => false,
                'message' => $result['error']['message'],
            ], $result['error']['status']);
        }

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
            ->map(fn ($b) => $this->formatBooking($b));

        return response()->json([
            'success' => true,
            'noShowList' => $noShows->values(),
        ]);
    }

    // ── LATE CHECK-IN ─────────────────────────────────────
    // no_show → checked_in (same day only, before 5 PM, clinic not stopped)
    public function lateCheckIn($id)
    {
        $today = Carbon::today()->toDateString();
        $result = app(DailyPetQueue::class)->runForDate($today, function () use ($id, $today) {
            $booking = Booking::whereKey($id)->lockForUpdate()->first();

            if (! $booking) {
                return ['error' => ['message' => 'Booking not found.', 'status' => 404]];
            }

            if ($booking->status !== 'no_show') {
                return ['error' => ['message' => 'Only no-show bookings can be late checked-in.', 'status' => 422]];
            }

            if ($booking->booking_date !== $today) {
                return ['error' => ['message' => 'Late check-in is only available on the day of the booking.', 'status' => 422]];
            }

            if (Carbon::now()->hour >= 17) {
                return ['error' => ['message' => 'Late check-in is no longer available after 5:00 PM.', 'status' => 422]];
            }

            $stoppedToday = ClinicClosure::where('type', 'stop_today')
                ->where('start_date', $today)
                ->where('is_active', 1)
                ->exists();

            if ($stoppedToday) {
                return ['error' => ['message' => 'The clinic has stopped receiving for today.', 'status' => 422]];
            }

            $queueNumber = ((int) Booking::where('booking_date', $today)
                ->whereNotNull('queue_number')
                ->max('queue_number')) + 1;

            $booking->update([
                'status' => 'checked_in',
                'queue_number' => $queueNumber,
                'dropped_off_at' => now(),
            ]);

            app(DailyPetQueue::class)->assignBookingPets($booking, $today);

            return ['queue_number' => $queueNumber];
        });

        if (isset($result['error'])) {
            return response()->json([
                'success' => false,
                'message' => $result['error']['message'],
            ], $result['error']['status']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Late check-in successful. Customer added to the back of the queue.',
            'queue_number' => $result['queue_number'],
        ]);
    }

    // ── GET ARCHIVED BOOKINGS ────────────────────────────
    public function archivedIndex(Request $request)
    {
        $search = $request->query('search', '');
        $date = $request->query('date', '');

        $query = Booking::where('status', 'archived')
            ->neverCancelled()
            ->with([
                'user',
                'walkin',
                'timeWindow',
                'bookingPets.pet',
                'bookingServices.service',
                'payments' => fn ($q) => $q
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
                            ->orWhere('last_name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('bookingPets.pet', function ($q2) use ($search) {
                        $q2->where('pet_name', 'like', "%{$search}%");
                    });
            });
        }

        $archived = $query->get()->map(fn ($b) => $this->formatArchivedBooking($b));

        return response()->json([
            'success' => true,
            'archived' => $archived->values(),
            'total' => $archived->count(),
        ]);
    }

    // ── OWNER CARD CONCERN STATE ──────────────────────────
    private function withOwnerCardMedicalConcernCounts(Builder $query): Builder
    {
        if (! Schema::hasTable('grooming_medical_concerns')) {
            return $query;
        }

        return $query->withCount([
            'groomingMedicalConcerns as active_medical_concern_count' => function (Builder $concerns) {
                $concerns->whereNotIn('status', [
                    GroomingMedicalConcern::STATUS_RESOLVED,
                    GroomingMedicalConcern::STATUS_CANCELLED,
                ]);
            },
            'groomingMedicalConcerns as resolved_medical_concern_count' => function (Builder $concerns) {
                $concerns->where('status', GroomingMedicalConcern::STATUS_RESOLVED);
            },
        ]);
    }

    // ── FORMAT BOOKING FOR FRONTEND ───────────────────────
    private function formatBooking(Booking $booking, ?array $paymentSummary = null): array
    {
        $user = $booking->user;
        $walkin = $booking->walkin;
        $window = $booking->timeWindow;
        $bpets = ($booking->bookingPets ?? collect())
            ->sortBy('booking_pet_id')
            ->values();
        $firstBp = $bpets->first();
        $firstPet = $firstBp?->pet;
        $bpetsById = $bpets->keyBy('booking_pet_id');

        $petName = $firstPet?->pet_name ?? '—';
        if ($bpets->count() > 1) {
            $petName .= ' +'.($bpets->count() - 1).' more';
        }

        $petType = $this->formatPetTypeSummary($bpets);
        $breed = $firstPet?->breed ?? '—';

        // Build service label from booked services
        $bookedServices = $booking->bookingServices ?? collect();
        $serviceNames = $bookedServices
            ->map(fn ($bs) => $bs->service?->service_name)
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
            fn ($bs) => (float) ($bs->price_at_booking ?? 0),
        ), 2);
        $canUseSavedServicePrices = ! $paidTotal
            || ($bookedServicesTotal > 0 && abs($bookedServicesTotal - round($paidTotal, 2)) <= 0.01);
        $paymentSummary ??= $this->paymentReadiness()->summarize($booking);
        $paymentPetsById = collect($paymentSummary['pets'])->keyBy('booking_pet_id');
        $workflow = app(GroomingBookingWorkflowService::class)->describe($paymentSummary);

        return [
            // Fields the card templates read directly
            'id' => $booking->booking_id,
            'queueNumber' => $booking->queue_number ?? 0,
            'ownerName' => $user
                ? trim(($user->first_name ?? '').' '.($user->last_name ?? ''))
                : ($walkin ? trim("{$walkin->fname} {$walkin->lname}") : '—'),
            'contactNumber' => $user?->phone ?? $walkin?->phone ?? '—',
            'petName' => $petName,
            'petType' => $petType,
            'breed' => $breed,
            'petSize' => $firstPet?->size,
            'size' => $firstPet?->size,
            'serviceLabel' => $serviceLabel,
            'appointmentDate' => $booking->booking_date,
            'appointmentTime' => $window?->window_label ?? '—',
            'dropOffTime' => $booking->dropped_off_at
                ? Carbon::parse($booking->dropped_off_at)->format('g:i A')
                : null,
            'startedAt' => $booking->grooming_started_at
                ? Carbon::parse($booking->grooming_started_at)->format('g:i A')
                : null,
            'completedAt' => $booking->grooming_finished_at
                ? Carbon::parse($booking->grooming_finished_at)->format('g:i A')
                : null,
            'clientNotified' => false,
            'paid' => (bool) $booking->paid,
            'status' => $this->mapStatus($booking->status),
            'activeMedicalConcernCount' => (int) ($booking->active_medical_concern_count ?? 0),
            'active_medical_concern_count' => (int) ($booking->active_medical_concern_count ?? 0),
            'resolvedMedicalConcernCount' => (int) ($booking->resolved_medical_concern_count ?? 0),
            'resolved_medical_concern_count' => (int) ($booking->resolved_medical_concern_count ?? 0),

            // Extra fields for the View Details modal
            'bookingReference' => $booking->booking_reference,
            'specialNotes' => $booking->special_notes,
            'numberOfPets' => $booking->number_of_pets,
            'paidAmount' => $paidTotal,
            'paid_amount' => $paidTotal,
            'paymentReady' => $paymentSummary['payment_ready'],
            'payment_ready' => $paymentSummary['payment_ready'],
            'paymentBlockedReason' => $paymentSummary['payment_blocked_reason'],
            'payment_blocked_reason' => $paymentSummary['payment_blocked_reason'],
            'finalPaymentTotal' => $paymentSummary['final_booking_total'],
            'final_payment_total' => $paymentSummary['final_booking_total'],
            'paymentSummary' => $paymentSummary,
            'payment_summary' => $paymentSummary,
            'actionRequired' => $workflow['action_required'],
            'action_required' => $workflow['action_required'],
            'actionRequiredType' => $workflow['action_required_type'],
            'action_required_type' => $workflow['action_required_type'],
            'actionRequiredReason' => $workflow['action_required_reason'],
            'action_required_reason' => $workflow['action_required_reason'],
            'actionRequiredPetIds' => $workflow['action_required_pet_ids'],
            'action_required_pet_ids' => $workflow['action_required_pet_ids'],
            'payment' => $paidPayment ? [
                'id' => $paidPayment->payment_id ?? $paidPayment->id ?? null,
                'finalPrice' => (float) $paidPayment->total_amount,
                'final_price' => (float) $paidPayment->total_amount,
                'amountPaid' => (float) $paidPayment->amount_tendered,
                'amount_paid' => (float) $paidPayment->amount_tendered,
                'paymentMethod' => $paidPayment->payment_method,
                'payment_method' => $paidPayment->payment_method,
                'paidAt' => $paidPayment->paid_at
                    ? Carbon::parse($paidPayment->paid_at)->format('M j, Y g:i A')
                    : null,
                'paid_at' => $paidPayment->paid_at,
            ] : null,
            'pets' => $bpets->values()->map(function ($bp, $petIndex) use ($paymentPetsById) {
                $pet = $bp->pet;
                $groomingState = $this->bookingPetGroomingState($bp);
                $paymentPet = $paymentPetsById->get($bp->booking_pet_id, []);
                $hasActiveClinicReferral = (bool) ($paymentPet['active_clinic_referral'] ?? false);
                $hasClinicReferral = (bool) ($paymentPet['has_clinic_referral']
                    ?? $paymentPet['active_clinic_referral']
                    ?? false);

                return [
                    'id' => $bp->booking_pet_id,
                    'bookingPetId' => $bp->booking_pet_id,
                    'booking_pet_id' => $bp->booking_pet_id,
                    'petId' => $pet?->pet_id,
                    'pet_id' => $pet?->pet_id,
                    'pet_name' => $pet?->pet_name,
                    'name' => $pet?->pet_name,
                    'petType' => ucfirst($pet?->species ?? 'Dog'),
                    'pet_type' => $pet?->species,
                    'petSize' => $pet?->size,
                    'pet_size' => $pet?->size,
                    'petName' => $pet?->pet_name ?? '—',
                    'species' => ucfirst($pet?->species ?? '—'),
                    'breed' => $pet?->breed ?? '—',
                    'size' => $pet?->size ?? '—',
                    'furType' => $pet?->fur_type ?? '—',
                    'weight' => $pet?->weight ? $pet->weight.' kg' : '—',
                    'medicalConditions' => $pet?->medical_conditions ?? null,
                    'specialInstructions' => $bp->special_instructions ?? null,
                    'petQueueNumber' => $bp->pet_queue_number ?? $petIndex + 1,
                    'groomingStartedAt' => $bp->grooming_start_time
                        ? Carbon::parse($bp->grooming_start_time)->format('g:i A')
                        : null,
                    'isGroomingStarted' => (bool) $bp->grooming_start_time,
                    'groomingStartedAtIso' => $bp->grooming_start_time,
                    'groomingFinishedAt' => $bp->grooming_end_time
                        ? Carbon::parse($bp->grooming_end_time)->format('g:i A')
                        : null,
                    'groomingFinishedAtIso' => $bp->grooming_end_time,
                    'isGroomingFinished' => (bool) $bp->grooming_end_time,
                    'groomingState' => $groomingState,
                    'grooming_state' => $groomingState,
                    'groomingStateLabel' => $this->groomingStateLabel($groomingState),
                    'grooming_state_label' => $this->groomingStateLabel($groomingState),
                    'hasClinicReferral' => $hasClinicReferral,
                    'has_clinic_referral' => $hasClinicReferral,
                    'hasActiveClinicReferral' => $hasActiveClinicReferral,
                    'has_active_clinic_referral' => $hasActiveClinicReferral,
                    'clinicReferralStatus' => $paymentPet['clinic_referral_status'] ?? null,
                    'clinic_referral_status' => $paymentPet['clinic_referral_status'] ?? null,
                    'paymentReviewRequired' => $groomingState === BookingPet::GROOMING_STATE_STOPPED
                        && ($paymentPet['review_status'] ?? 'pending') !== 'completed',
                    'paymentReviewCompleted' => ($paymentPet['review_status'] ?? null) === 'completed',
                    'paymentReviewDecision' => $paymentPet['review_decision'] ?? null,
                    'paymentReviewDecisionLabel' => $paymentPet['review_decision_label'] ?? null,
                    'reviewedFinalCharge' => $paymentPet['final_pet_charge'] ?? null,
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
                    'id' => $bs->booking_service_id,
                    'bookingServiceId' => $bs->booking_service_id,
                    'booking_service_id' => $bs->booking_service_id,
                    'bookingPetId' => $bs->booking_pet_id,
                    'booking_pet_id' => $bs->booking_pet_id,
                    'petId' => $pet?->pet_id,
                    'pet_id' => $pet?->pet_id,
                    'petName' => $pet?->pet_name ?? '',
                    'pet_name' => $pet?->pet_name ?? '',
                    'serviceId' => $bs->service?->service_id,
                    'service_id' => $bs->service?->service_id,
                    'slug' => $bs->service?->slug,
                    'serviceSlug' => $bs->service?->slug,
                    'service_slug' => $bs->service?->slug,
                    'serviceName' => $bs->service?->service_name ?? 'Grooming Service',
                    'service_name' => $bs->service?->service_name ?? 'Grooming Service',
                    'description' => $bs->service?->description,
                    'name' => $bs->service?->service_name ?? '—',
                    'priceAtBooking' => $bs->price_at_booking,
                    'price_at_booking' => $bs->price_at_booking,
                    'durationMinutes' => (int) ($bs->service?->duration_minutes ?? 60),
                    'paidPrice' => $paidPrice,
                    'paid_price' => $paidPrice,
                    'paidPriceSource' => $paidPriceSource,
                    'paid_price_source' => $paidPriceSource,
                    'paymentTotal' => $paidTotal,
                    'payment_total' => $paidTotal,
                ];
            })->values(),
        ];
    }

    /**
     * Build the schedule-card pet type label from every pet in the booking.
     */
    private function formatPetTypeSummary($bookingPets): string
    {
        $typeCounts = collect($bookingPets)
            ->map(fn ($bookingPet) => strtolower(trim((string) ($bookingPet->pet?->species ?? ''))))
            ->filter()
            ->countBy();

        if ($typeCounts->isEmpty()) {
            return '—';
        }

        // Keep the two supported clinic pet types in the expected display order.
        $orderedTypes = collect(['dog', 'cat'])
            ->filter(fn ($type) => $typeCounts->has($type))
            ->merge($typeCounts->keys()->reject(fn ($type) => in_array($type, ['dog', 'cat'], true)));

        $labels = $orderedTypes
            ->map(function ($type) use ($typeCounts) {
                $label = ucfirst($type);

                return $typeCounts->get($type) > 1 ? $label.'s' : $label;
            })
            ->values();

        if ($labels->count() === 1) {
            return $labels->first();
        }

        if ($labels->count() === 2) {
            return $labels->first().' and '.$labels->last();
        }

        return $labels->slice(0, -1)->implode(', ').', and '.$labels->last();
    }

    // Formats a booking record for the archive page
    private function formatArchivedBooking(Booking $booking): array
    {
        $paymentSummary = $this->canUseSettledArchivePaymentSummary($booking)
            ? $this->settledArchivePaymentSummary($booking)
            : $this->paymentReadiness()->summarize($booking);
        $formatted = $this->formatBooking(
            $booking,
            $paymentSummary,
        );
        $formatted['archivedAt'] = $booking->archived_at
            ? Carbon::parse($booking->archived_at)->format('M j, Y g:i A')
            : '—';

        return $formatted;
    }

    private function canUseSettledArchivePaymentSummary(Booking $booking): bool
    {
        $bookingPets = $booking->bookingPets ?? collect();
        $hasOnlyOrdinaryFinishedPets = $bookingPets->isNotEmpty()
            && $bookingPets->every(fn (BookingPet $bookingPet) => (string) $bookingPet->grooming_state
                === BookingPet::GROOMING_STATE_FINISHED
                && $bookingPet->grooming_end_time !== null);
        $hasOnlyPositiveServiceSnapshots = ($booking->bookingServices ?? collect())
            ->every(fn ($bookingService) => $bookingService->addon_id === null
                && $bookingService->service_id !== null
                && (float) $bookingService->price_at_booking > 0);
        $paidPayment = $this->paidPaymentForBooking($booking);
        $recordedTotal = $paidPayment
            ? (float) $paidPayment->total_amount
            : ((bool) $booking->paid ? (float) $booking->total_amount : null);
        $snapshotTotal = (float) ($booking->bookingServices ?? collect())
            ->sum(fn ($bookingService) => (float) $bookingService->price_at_booking);
        $hasConsistentSettledTotal = $recordedTotal !== null
            && abs($recordedTotal - $snapshotTotal) <= 0.01;

        return $hasOnlyOrdinaryFinishedPets
            && $hasOnlyPositiveServiceSnapshots
            && $hasConsistentSettledTotal;
    }

    /**
     * Reconstruct the settled payment shape from relations already loaded for
     * the archive. This keeps the legacy booking response contract without
     * rerunning live readiness queries for every historical booking.
     */
    private function settledArchivePaymentSummary(Booking $booking): array
    {
        $bookingPets = ($booking->bookingPets ?? collect())
            ->sortBy('booking_pet_id')
            ->values();
        $servicesByPet = ($booking->bookingServices ?? collect())
            ->sortBy('booking_service_id')
            ->groupBy('booking_pet_id');
        $paidPayment = $this->paidPaymentForBooking($booking);
        $settledTotal = $paidPayment
            ? (float) $paidPayment->total_amount
            : (float) $booking->total_amount;

        $pets = $bookingPets->map(function (BookingPet $bookingPet) use ($servicesByPet) {
            $groomingState = $this->bookingPetGroomingState($bookingPet);
            $serviceLines = collect($servicesByPet->get($bookingPet->booking_pet_id, collect()))
                ->map(function ($bookingService) {
                    $savedPrice = (float) ($bookingService->price_at_booking ?? 0);
                    $isAddon = $bookingService->addon_id !== null;

                    return [
                        'booking_service_id' => (int) $bookingService->booking_service_id,
                        'line_type' => $isAddon ? 'add_on' : 'service',
                        'service_id' => $bookingService->service_id !== null
                            ? (int) $bookingService->service_id
                            : null,
                        'addon_id' => $bookingService->addon_id !== null
                            ? (int) $bookingService->addon_id
                            : null,
                        'label' => $isAddon
                            ? "Add-on #{$bookingService->addon_id}"
                            : ($bookingService->service?->service_name
                                ?? "Service #{$bookingService->service_id}"),
                        'price_at_booking' => number_format($savedPrice, 2, '.', ''),
                        'price_source' => $savedPrice > 0 ? 'booking_snapshot' : 'unpriced',
                    ];
                })
                ->values();
            $petSubtotal = $serviceLines->sum(
                fn (array $line) => (float) $line['price_at_booking'],
            );
            $wasStopped = $groomingState === BookingPet::GROOMING_STATE_STOPPED;

            return [
                'booking_pet_id' => (int) $bookingPet->booking_pet_id,
                'pet_id' => $bookingPet->pet_id !== null ? (int) $bookingPet->pet_id : null,
                'pet_name' => $bookingPet->pet?->pet_name ?? 'Pet',
                'pet_species' => $bookingPet->pet?->species,
                'grooming_state' => $groomingState,
                'grooming_state_label' => $this->groomingStateLabel($groomingState),
                'grooming_finish_time' => $bookingPet->grooming_end_time?->toIso8601String(),
                'payment_kind' => $wasStopped ? 'stopped_reviewed' : 'finished',
                'payment_ready' => true,
                'payment_blocked_reason' => null,
                'active_clinic_referral' => false,
                'clinic_referral_status' => null,
                'service_breakdown' => $serviceLines->all(),
                'original_pet_subtotal' => number_format($petSubtotal, 2, '.', ''),
                'final_pet_charge' => number_format($petSubtotal, 2, '.', ''),
                'adjustment' => '0.00',
                'review_status' => $wasStopped ? 'completed' : 'pending',
                'review_id' => null,
                'review_decision' => null,
                'review_decision_label' => null,
                'review_original_pet_subtotal' => null,
                'customer_explanation' => null,
                'reviewed_at' => null,
                'concern_public_id' => null,
            ];
        })->values();

        return [
            'payment_ready' => true,
            'payment_blocked_reason' => null,
            'final_booking_total' => number_format($settledTotal, 2, '.', ''),
            'zero_total' => abs($settledTotal) < 0.005,
            'pets' => $pets->all(),
        ];
    }

    private function paidPaymentForBooking(Booking $booking): ?Payment
    {
        if ($booking->relationLoaded('payments')) {
            return $booking->payments
                ->where('payment_status', 'paid')
                ->sortByDesc(fn ($payment) => $payment->paid_at
                    ? Carbon::parse($payment->paid_at)->timestamp
                    : 0)
                ->first();
        }

        if (! (bool) $booking->paid) {
            return null;
        }

        return $booking->payments()
            ->where('payment_status', 'paid')
            ->orderByDesc('paid_at')
            ->first();
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
                    'id' => 'payment-'.$payment->getKey(),
                    'type' => 'payment',
                    'title' => 'Payment collected',
                    'subtitle' => "\u{20B1}".number_format((float) $payment->total_amount).' - '.($ownerLastName ?: 'Customer'),
                    'time' => $payment->paid_at,
                ];
            });

        $checkIns = Booking::with(['user', 'walkin', 'bookingPets.pet'])
            ->whereNotNull('dropped_off_at')
            ->orderBy('dropped_off_at', 'desc')
            ->limit(6)
            ->get()
            ->map(function (Booking $booking) {
                return [
                    'id' => 'check-in-'.$booking->booking_id,
                    'type' => 'queued',
                    'title' => 'Checked in',
                    'subtitle' => $this->petNames($booking).' - '.($this->ownerName($booking) ?: 'Customer'),
                    'time' => $booking->dropped_off_at,
                ];
            });

        $groomingStarted = Booking::with(['user', 'bookingPets.pet', 'bookingServices.service'])
            ->whereNotNull('grooming_started_at')
            ->orderBy('grooming_started_at', 'desc')
            ->limit(6)
            ->get()
            ->map(function (Booking $booking) {
                return [
                    'id' => 'grooming-started-'.$booking->booking_id,
                    'type' => 'in_progress',
                    'title' => 'Grooming started',
                    'subtitle' => $this->petNames($booking).' - '.$this->serviceLabel($booking),
                    'time' => $booking->grooming_started_at,
                ];
            });

        $completed = Booking::with(['user', 'bookingPets.pet', 'bookingServices.service'])
            ->whereNotNull('grooming_finished_at')
            ->orderBy('grooming_finished_at', 'desc')
            ->limit(6)
            ->get()
            ->map(function (Booking $booking) {
                return [
                    'id' => 'completed-'.$booking->booking_id,
                    'type' => 'completed',
                    'title' => 'Grooming Service Completed',
                    'subtitle' => $this->petNames($booking).' - '.$this->serviceLabel($booking),
                    'time' => $booking->grooming_finished_at,
                ];
            });

        return $payments
            ->concat($checkIns)
            ->concat($groomingStarted)
            ->concat($completed)
            ->filter(fn ($activity) => ! empty($activity['time']))
            ->sortByDesc(fn ($activity) => Carbon::parse($activity['time'])->timestamp)
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
        if ($booking?->user) {
            return trim(($booking->user->first_name ?? '').' '.($booking->user->last_name ?? ''));
        }

        if ($booking?->walkin) {
            return trim("{$booking->walkin->fname} {$booking->walkin->lname}");
        }

        return '';
    }

    private function petNames(Booking $booking): string
    {
        $names = ($booking->bookingPets ?? collect())
            ->map(fn ($bookingPet) => $bookingPet->pet?->pet_name)
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

        return $names->slice(0, -1)->implode(', ').', and '.$names->last();
    }

    private function hasMultiplePets(Booking $booking): bool
    {
        return ($booking->bookingPets ?? collect())
            ->map(fn ($bookingPet) => $bookingPet->pet?->pet_name)
            ->filter()
            ->unique()
            ->count() > 1;
    }

    private function serviceLabel(Booking $booking): string
    {
        $services = ($booking->bookingServices ?? collect())
            ->map(fn ($bookingService) => $bookingService->service?->service_name)
            ->filter()
            ->unique()
            ->values();

        return $services->isNotEmpty() ? $services->implode(', ') : 'Grooming';
    }

    private function bookingPetGroomingState(BookingPet $bookingPet): string
    {
        $state = (string) $bookingPet->grooming_state;

        if (BookingPet::isValidGroomingState($state)) {
            return $state;
        }

        if ($bookingPet->grooming_end_time !== null) {
            return BookingPet::GROOMING_STATE_FINISHED;
        }

        if ($bookingPet->grooming_start_time !== null) {
            return BookingPet::GROOMING_STATE_IN_PROGRESS;
        }

        return BookingPet::GROOMING_STATE_NOT_STARTED;
    }

    private function groomingStateLabel(string $state): string
    {
        return match ($state) {
            BookingPet::GROOMING_STATE_IN_PROGRESS => 'In progress',
            BookingPet::GROOMING_STATE_PAUSED => 'Paused',
            BookingPet::GROOMING_STATE_STOPPED => 'Stopped',
            BookingPet::GROOMING_STATE_FINISHED => 'Finished',
            default => 'Not started',
        };
    }

    private function paymentReadiness(): GroomingPaymentReadinessService
    {
        return app(GroomingPaymentReadinessService::class);
    }

    // Maps DB status values to the tab keys the frontend uses
    private function mapStatus(string $status): string
    {
        return match ($status) {
            'waiting_to_arrive' => 'incoming',
            'checked_in' => 'queued',
            'in_progress' => 'in-progress',
            'for_pickup' => 'for-pickup',
            'for_payment' => 'for-payment',
            'released' => 'released',
            default => $status,
        };
    }
}
