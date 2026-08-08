<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingPet;
use App\Models\BookingService;
use App\Models\GroomingMedicalConcern;
use App\Models\GroomingStoppedPaymentReview;
use App\Models\Pet;
use App\Models\User;
use App\Services\GroomingBookingWorkflowService;
use App\Services\GroomingPaymentSettlementService;
use App\Services\GroomingServicePriceResolver;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GroomingStoppedPaymentReviewController extends Controller
{
    private const MAX_INTERNAL_REASON_LENGTH = 5000;

    private const MAX_CUSTOMER_EXPLANATION_LENGTH = 2000;

    private const INVALID_BOOKING_STATUSES = [
        'cancelled',
        'no_show',
        'released',
        'archived',
    ];

    public function __construct(
        private readonly GroomingServicePriceResolver $servicePrices,
    ) {}

    public function show(int $bookingId, int $bookingPetId)
    {
        $context = $this->findBookingPetContext($bookingId, $bookingPetId);
        $serviceContext = $this->serviceContext($context);
        $review = GroomingStoppedPaymentReview::query()
            ->where('booking_id', $bookingId)
            ->where('booking_pet_id', $bookingPetId)
            ->where('pet_id', $context->pet_id)
            ->first();
        $concern = $review
            ? $this->findScopedConcern($context, $review->grooming_medical_concern_id)
            : $this->findAppliedStopConcern($context);

        return response()->json([
            'success' => true,
            'review' => $this->formatReview(
                $context,
                $serviceContext,
                $concern,
                $review,
            ),
        ]);
    }

    public function store(
        Request $request,
        int $bookingId,
        int $bookingPetId,
        int $concernId,
    ) {
        $context = $this->findBookingPetContext($bookingId, $bookingPetId);
        $this->findScopedConcern($context, $concernId);
        $validated = $this->validatePayload($request);

        try {
            return DB::transaction(function () use (
                $request,
                $bookingId,
                $bookingPetId,
                $concernId,
                $validated,
            ) {
                $context = $this->findBookingPetContext(
                    $bookingId,
                    $bookingPetId,
                    lockForUpdate: true,
                );
                $concern = $this->findScopedConcern(
                    $context,
                    $concernId,
                    lockForUpdate: true,
                );
                $serviceContext = $this->serviceContext(
                    $context,
                    lockForUpdate: true,
                );
                $existingReview = $this->findExistingReview(
                    $context,
                    $concern,
                    lockForUpdate: true,
                );

                if ($existingReview) {
                    return $this->duplicateResponse(
                        $context,
                        $serviceContext,
                        $concern,
                        $existingReview,
                        $validated,
                    );
                }

                $eligibility = $this->reviewEligibility($context, $concern);
                if (! $eligibility['allowed']) {
                    return $this->unprocessable($eligibility['blocked_reason']);
                }

                $finalCharge = $this->resolveFinalCharge(
                    $validated,
                    $serviceContext['subtotal'],
                );
                $reviewer = $request->user();

                $review = GroomingStoppedPaymentReview::create([
                    'booking_id' => $context->booking_id,
                    'booking_pet_id' => $context->booking_pet_id,
                    'pet_id' => $context->pet_id,
                    'grooming_medical_concern_id' => $concern->id,
                    'decision' => $validated['decision'],
                    'original_pet_subtotal' => $serviceContext['subtotal'],
                    'final_pet_charge' => $finalCharge,
                    'internal_reason' => $validated['internal_reason'],
                    'customer_explanation' => $validated['customer_explanation'],
                    'reviewed_by_user_id' => $reviewer->user_id,
                    'reviewed_by_name' => $this->formatAuthenticatedUserName($reviewer),
                    'reviewed_at' => now(),
                ]);
                $paymentReadiness = $this->advanceBookingAfterReview(
                    $context,
                    $validated['decision'],
                    $reviewer->user_id,
                );

                return response()->json([
                    'success' => true,
                    'message' => $paymentReadiness['automatically_processed']
                        ? 'Payment review completed. No payment was required, and the booking is ready for pickup.'
                        : 'Stopped-grooming payment review completed.',
                    'review' => $this->formatReview(
                        $context,
                        $serviceContext,
                        $concern,
                        $review,
                    ),
                    'payment_readiness' => $paymentReadiness,
                ], 201);
            });
        } catch (UniqueConstraintViolationException) {
            $context = $this->findBookingPetContext($bookingId, $bookingPetId);
            $concern = $this->findScopedConcern($context, $concernId);
            $serviceContext = $this->serviceContext($context);
            $existingReview = $this->findExistingReview($context, $concern);

            if (! $existingReview) {
                return $this->conflict(
                    'A stopped-grooming payment review was completed concurrently. Refresh and try again.',
                );
            }

            return $this->duplicateResponse(
                $context,
                $serviceContext,
                $concern,
                $existingReview,
                $validated,
            );
        }
    }

    private function validatePayload(Request $request): array
    {
        foreach (['decision', 'internal_reason', 'customer_explanation'] as $field) {
            if ($request->exists($field) && is_string($request->input($field))) {
                $request->merge([$field => trim($request->input($field))]);
            }
        }

        $allowedFields = [
            'decision',
            'final_pet_charge',
            'internal_reason',
            'customer_explanation',
        ];
        $rules = [
            'decision' => [
                'required',
                'string',
                Rule::in(GroomingStoppedPaymentReview::DECISIONS),
            ],
            'final_pet_charge' => [
                Rule::requiredIf(
                    $request->input('decision')
                        === GroomingStoppedPaymentReview::DECISION_PARTIAL_CHARGE,
                ),
                'numeric',
                'decimal:0,2',
                'min:0',
                'max:999999.99',
            ],
            'internal_reason' => [
                'required',
                'string',
                'max:'.self::MAX_INTERNAL_REASON_LENGTH,
            ],
            'customer_explanation' => [
                'required',
                'string',
                'max:'.self::MAX_CUSTOMER_EXPLANATION_LENGTH,
            ],
        ];

        foreach (array_keys($request->all()) as $field) {
            if (! in_array($field, $allowedFields, true)) {
                $rules[$field] = ['prohibited'];
            }
        }

        return $request->validate($rules);
    }

    private function resolveFinalCharge(array $validated, string $originalSubtotal): string
    {
        $originalCents = $this->moneyToCents($originalSubtotal);
        $submitted = array_key_exists('final_pet_charge', $validated)
            ? $this->centsToMoney($this->moneyToCents($validated['final_pet_charge']))
            : null;
        $submittedCents = $submitted !== null
            ? $this->moneyToCents($submitted)
            : null;

        if ($validated['decision'] === GroomingStoppedPaymentReview::DECISION_FULL_CHARGE) {
            if ($originalCents === 0) {
                throw ValidationException::withMessages([
                    'decision' => 'A zero-subtotal pet must use No charge.',
                ]);
            }

            if ($submittedCents !== null && $submittedCents !== $originalCents) {
                throw ValidationException::withMessages([
                    'final_pet_charge' => 'Full charge must equal the original pet subtotal.',
                ]);
            }

            return $this->centsToMoney($originalCents);
        }

        if ($validated['decision'] === GroomingStoppedPaymentReview::DECISION_PARTIAL_CHARGE) {
            if ($submittedCents === null || $submittedCents <= 0) {
                throw ValidationException::withMessages([
                    'final_pet_charge' => 'Partial charge must be greater than zero.',
                ]);
            }

            if ($submittedCents >= $originalCents) {
                throw ValidationException::withMessages([
                    'final_pet_charge' => 'Partial charge must be less than the original pet subtotal.',
                ]);
            }

            return $submitted;
        }

        if ($submittedCents !== null && $submittedCents !== 0) {
            throw ValidationException::withMessages([
                'final_pet_charge' => 'No charge must have a final pet charge of zero.',
            ]);
        }

        return '0.00';
    }

    private function findBookingPetContext(
        int $bookingId,
        int $bookingPetId,
        bool $lockForUpdate = false,
    ): BookingPet {
        $bookingQuery = Booking::query()->whereKey($bookingId);
        $bookingPetQuery = BookingPet::query()
            ->whereKey($bookingPetId)
            ->where('booking_id', $bookingId);

        if ($lockForUpdate) {
            $bookingQuery->lockForUpdate();
            $bookingPetQuery->lockForUpdate();
        }

        $booking = $bookingQuery->first();
        $bookingPet = $bookingPetQuery->first();

        if (! $booking || ! $bookingPet || $bookingPet->pet_id === null) {
            $this->throwBookingPetNotFound();
        }

        $petQuery = Pet::query()->whereKey($bookingPet->pet_id);
        if ($lockForUpdate) {
            $petQuery->lockForUpdate();
        }
        $pet = $petQuery->first();

        if (! $pet) {
            $this->throwBookingPetNotFound();
        }

        $bookingPet->setRelation('booking', $booking);
        $bookingPet->setRelation('pet', $pet);

        return $bookingPet;
    }

    private function findScopedConcern(
        BookingPet $context,
        int $concernId,
        bool $lockForUpdate = false,
    ): GroomingMedicalConcern {
        $query = GroomingMedicalConcern::query()
            ->whereKey($concernId)
            ->where('booking_id', $context->booking_id)
            ->where('booking_pet_id', $context->booking_pet_id)
            ->where('pet_id', $context->pet_id);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $concern = $query->first();

        if (! $concern) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'Medical concern not found.',
            ], 404));
        }

        return $concern;
    }

    private function findAppliedStopConcern(BookingPet $context): ?GroomingMedicalConcern
    {
        return GroomingMedicalConcern::query()
            ->where('booking_id', $context->booking_id)
            ->where('booking_pet_id', $context->booking_pet_id)
            ->where('pet_id', $context->pet_id)
            ->where(
                'recommended_grooming_action',
                GroomingMedicalConcern::ACTION_STOP_GROOMING,
            )
            ->where(
                'applied_grooming_action',
                GroomingMedicalConcern::ACTION_STOP_GROOMING,
            )
            ->orderByDesc('action_applied_at')
            ->orderByDesc('id')
            ->first();
    }

    private function findExistingReview(
        BookingPet $context,
        GroomingMedicalConcern $concern,
        bool $lockForUpdate = false,
    ): ?GroomingStoppedPaymentReview {
        $query = GroomingStoppedPaymentReview::query()
            ->where(function ($query) use ($context, $concern) {
                $query->where('booking_pet_id', $context->booking_pet_id)
                    ->orWhere('grooming_medical_concern_id', $concern->id);
            });

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function serviceContext(
        BookingPet $context,
        bool $lockForUpdate = false,
    ): array {
        $query = BookingService::query()
            ->where('booking_id', $context->booking_id)
            ->where('booking_pet_id', $context->booking_pet_id)
            ->with('service')
            ->orderBy('booking_service_id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $rows = $query->get([
            'booking_service_id',
            'service_id',
            'addon_id',
            'price_at_booking',
        ]);
        $serviceNames = $rows->pluck('service.service_name', 'service_id');
        $addonNames = DB::table('addons')
            ->whereIn('addon_id', $rows->pluck('addon_id')->filter()->unique())
            ->pluck('addon_name', 'addon_id');
        $subtotalInCents = 0;

        $lines = $rows->map(function (BookingService $row) use (
            $serviceNames,
            $addonNames,
            $context,
            &$subtotalInCents,
        ) {
            $resolvedPrice = $this->servicePrices->bookingServicePrice(
                $row,
                $context->pet?->size,
            );
            $price = $resolvedPrice['amount'];
            $subtotalInCents += $this->moneyToCents($price);
            $isAddon = $row->addon_id !== null;

            return [
                'booking_service_id' => $row->booking_service_id,
                'line_type' => $isAddon ? 'add_on' : 'service',
                'service_id' => $row->service_id,
                'addon_id' => $row->addon_id,
                'label' => $isAddon
                    ? ($addonNames[$row->addon_id] ?? "Add-on #{$row->addon_id}")
                    : ($serviceNames[$row->service_id] ?? "Service #{$row->service_id}"),
                'price_at_booking' => $price,
                'price_source' => $resolvedPrice['source'],
            ];
        })->values()->all();

        return [
            'lines' => $lines,
            'subtotal' => $this->centsToMoney($subtotalInCents),
        ];
    }

    private function reviewEligibility(
        BookingPet $context,
        ?GroomingMedicalConcern $concern,
    ): array {
        $blocked = fn (string $reason) => [
            'allowed' => false,
            'blocked_reason' => $reason,
        ];
        $booking = $context->booking;

        if (
            in_array($booking->status, self::INVALID_BOOKING_STATUSES, true)
            || $booking->archived_at !== null
        ) {
            return $blocked('This booking is no longer eligible for a stopped-grooming payment review.');
        }

        if ((bool) $booking->paid) {
            return $blocked('A stopped-grooming payment review cannot be completed after payment.');
        }

        if ($context->grooming_state !== BookingPet::GROOMING_STATE_STOPPED) {
            return $blocked('Only a stopped grooming pet requires this payment review.');
        }

        if ($context->grooming_end_time !== null) {
            return $blocked('A stopped pet with a normal grooming finish timestamp cannot be reviewed.');
        }

        if (! $concern) {
            return $blocked('No applied Stop Grooming concern is available for this pet.');
        }

        if ($concern->status === GroomingMedicalConcern::STATUS_CANCELLED) {
            return $blocked('A cancelled concern cannot support a stopped-grooming payment review.');
        }

        if (
            $concern->recommended_grooming_action
                !== GroomingMedicalConcern::ACTION_STOP_GROOMING
            || $concern->applied_grooming_action
                !== GroomingMedicalConcern::ACTION_STOP_GROOMING
            || $concern->action_applied_at === null
            || $concern->action_applied_by_user_id === null
        ) {
            return $blocked('The selected concern does not contain a fully audited applied Stop Grooming action.');
        }

        return ['allowed' => true, 'blocked_reason' => null];
    }

    private function duplicateResponse(
        BookingPet $context,
        array $serviceContext,
        GroomingMedicalConcern $concern,
        GroomingStoppedPaymentReview $review,
        array $validated,
    ) {
        if (! $this->isExactReplay($review, $concern, $validated)) {
            return $this->conflict(
                'A different immutable payment review already exists for this stopped pet or concern.',
            );
        }

        $paymentReadiness = $this->advanceBookingAfterReview(
            $context,
            $validated['decision'],
            request()->user()?->user_id,
        );

        return response()->json([
            'success' => true,
            'message' => 'This stopped-grooming payment review was already completed.',
            'review' => $this->formatReview(
                $context,
                $serviceContext,
                $concern,
                $review,
                alreadyReviewed: true,
            ),
            'payment_readiness' => $paymentReadiness,
        ]);
    }

    private function isExactReplay(
        GroomingStoppedPaymentReview $review,
        GroomingMedicalConcern $concern,
        array $validated,
    ): bool {
        if (
            (int) $review->grooming_medical_concern_id !== (int) $concern->id
            || $review->decision !== $validated['decision']
            || $review->internal_reason !== $validated['internal_reason']
            || $review->customer_explanation !== $validated['customer_explanation']
        ) {
            return false;
        }

        if (array_key_exists('final_pet_charge', $validated)) {
            return $this->moneyToCents($review->final_pet_charge)
                === $this->moneyToCents($validated['final_pet_charge']);
        }

        return $review->decision !== GroomingStoppedPaymentReview::DECISION_PARTIAL_CHARGE;
    }

    private function formatReview(
        BookingPet $context,
        array $serviceContext,
        ?GroomingMedicalConcern $concern,
        ?GroomingStoppedPaymentReview $review,
        bool $alreadyReviewed = false,
    ): array {
        $eligibility = $review
            ? ['allowed' => false, 'blocked_reason' => 'A completed immutable review already exists.']
            : $this->reviewEligibility($context, $concern);
        $originalSubtotal = $review?->original_pet_subtotal
            ?? $serviceContext['subtotal'];

        return [
            'review_status' => $review ? 'completed' : 'pending',
            'review_id' => $review?->id,
            'booking_reference' => $context->booking?->booking_reference,
            'booking_pet_id' => $context->booking_pet_id,
            'pet_id' => $context->pet_id,
            'pet_name' => $context->pet?->pet_name,
            'pet_species' => $context->pet?->species,
            'grooming_state' => $context->grooming_state,
            'grooming_state_label' => $this->groomingStateLabel(
                (string) $context->grooming_state,
            ),
            'review_creation_allowed' => $eligibility['allowed'],
            'review_creation_blocked_reason' => $eligibility['blocked_reason'],
            'service_breakdown' => $serviceContext['lines'],
            'original_pet_subtotal' => $this->centsToMoney(
                $this->moneyToCents($originalSubtotal),
            ),
            'concern_id' => $concern?->id,
            'concern_public_id' => $concern?->public_id,
            'applied_stop_grooming_at' => $concern?->action_applied_at?->toIso8601String(),
            'decision' => $review?->decision,
            'decision_label' => $review
                ? GroomingStoppedPaymentReview::decisionLabel($review->decision)
                : null,
            'final_pet_charge' => $review?->final_pet_charge,
            'adjustment' => $review?->adjustmentAmount(),
            'internal_reason' => $review?->internal_reason,
            'customer_explanation' => $review?->customer_explanation,
            'reviewed_by_name' => $review?->reviewed_by_name,
            'reviewed_at' => $review?->reviewed_at?->toIso8601String(),
            'already_reviewed' => $alreadyReviewed,
            'immutable' => $review !== null,
            'payment_integration_pending' => false,
            'booking_status' => $context->booking?->status,
        ];
    }

    private function advanceBookingAfterReview(
        BookingPet $context,
        string $decision,
        ?int $processedBy,
    ): array {
        $booking = $context->booking;
        $summary = app(GroomingBookingWorkflowService::class)->reconcile(
            $booking,
            true,
        );
        $automaticSettlement = [
            'automatically_processed' => false,
            'already_processed' => false,
            'automatic_processing_blocked_reason' => null,
            'booking_status' => (string) $booking->status,
        ];

        if ($decision === GroomingStoppedPaymentReview::DECISION_NO_CHARGE) {
            $automaticSettlement = app(GroomingPaymentSettlementService::class)
                ->settleZeroTotalIfReady($booking, $processedBy, true);
            $summary = $automaticSettlement['payment_summary'];
        }

        return [
            'payment_ready' => $summary['payment_ready'],
            'payment_blocked_reason' => $summary['payment_blocked_reason'],
            'final_booking_total' => $summary['final_booking_total'],
            'zero_total' => (bool) ($summary['zero_total'] ?? false),
            'booking_status' => $automaticSettlement['booking_status'],
            'automatically_processed' => $automaticSettlement['automatically_processed'],
            'already_processed' => $automaticSettlement['already_processed'],
            'automatic_processing_blocked_reason' => $automaticSettlement['automatic_processing_blocked_reason'],
        ];
    }

    private function moneyToCents(string|int|float|null $amount): int
    {
        $value = trim((string) ($amount ?? '0'));
        $isNegative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');
        $cents = ((int) ($whole === '' ? '0' : $whole) * 100) + (int) $fraction;

        return $isNegative ? -$cents : $cents;
    }

    private function centsToMoney(int $cents): string
    {
        $prefix = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return sprintf('%s%d.%02d', $prefix, intdiv($absolute, 100), $absolute % 100);
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

    private function formatAuthenticatedUserName(User $user): string
    {
        $name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));

        return $name !== '' ? $name : "Staff user #{$user->user_id}";
    }

    private function throwBookingPetNotFound(): never
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Grooming booking pet not found.',
        ], 404));
    }

    private function unprocessable(string $message)
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 422);
    }

    private function conflict(string $message)
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 409);
    }
}
