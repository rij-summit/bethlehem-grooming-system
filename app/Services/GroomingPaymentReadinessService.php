<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingPet;
use App\Models\BookingService;
use App\Models\GroomingClinicReferral;
use App\Models\GroomingMedicalConcern;
use App\Models\GroomingStoppedPaymentReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GroomingPaymentReadinessService
{
    public function __construct(
        private readonly GroomingServicePriceResolver $servicePrices,
    ) {}

    /**
     * Build the authoritative per-pet payment readiness and total for a booking.
     *
     * Finished pets contribute their saved booking-service prices. Stopped pets
     * contribute only an exact, immutable stopped-payment review. Every other
     * state blocks final payment.
     */
    public function summarize(Booking $booking, bool $lockForUpdate = false): array
    {
        $petQuery = BookingPet::query()
            ->where('booking_id', $booking->booking_id)
            ->with('pet')
            ->orderBy('booking_pet_id');
        $hasReviewFoundation = Schema::hasTable('grooming_stopped_payment_reviews')
            && Schema::hasTable('grooming_medical_concerns');
        $hasReferralFoundation = Schema::hasTable('grooming_clinic_referrals');
        if ($hasReviewFoundation) {
            $petQuery->with('groomingStoppedPaymentReview.groomingMedicalConcern');
        }
        if ($hasReferralFoundation) {
            $petQuery->with('groomingClinicReferrals:id,booking_id,booking_pet_id,pet_id,status');
        }
        $serviceQuery = BookingService::query()
            ->where('booking_id', $booking->booking_id)
            ->with('service')
            ->orderBy('booking_service_id');

        if ($lockForUpdate) {
            $petQuery->lockForUpdate();
            $serviceQuery->lockForUpdate();
        }

        $bookingPets = $petQuery->get();
        $serviceRows = $serviceQuery->get();
        $serviceNames = $this->lookupLabels(
            'services',
            'service_id',
            'service_name',
            $serviceRows->pluck('service_id')->filter()->unique()->all(),
        );
        $addonNames = $this->lookupLabels(
            'addons',
            'addon_id',
            'addon_name',
            $serviceRows->pluck('addon_id')->filter()->unique()->all(),
        );
        $servicesByPet = $serviceRows->groupBy('booking_pet_id');
        $bookingTotalCents = 0;
        $firstBlockedReason = null;

        $pets = $bookingPets->map(function (BookingPet $bookingPet) use (
            $servicesByPet,
            $serviceNames,
            $addonNames,
            $hasReviewFoundation,
            $hasReferralFoundation,
            &$bookingTotalCents,
            &$firstBlockedReason,
        ) {
            $lines = collect($servicesByPet->get($bookingPet->booking_pet_id, collect()))
                ->map(function (BookingService $line) use (
                    $serviceNames,
                    $addonNames,
                    $bookingPet,
                ) {
                    $isAddon = $line->addon_id !== null;
                    $resolvedPrice = $this->servicePrices->bookingServicePrice(
                        $line,
                        $bookingPet->pet?->size,
                    );

                    return [
                        'booking_service_id' => (int) $line->booking_service_id,
                        'line_type' => $isAddon ? 'add_on' : 'service',
                        'service_kind' => $isAddon
                            ? 'ala_carte'
                            : (config('grooming_services.services.'.($line->service?->slug ?? '').'.kind')
                                === 'package' ? 'package' : 'ala_carte'),
                        'service_id' => $line->service_id !== null ? (int) $line->service_id : null,
                        'addon_id' => $line->addon_id !== null ? (int) $line->addon_id : null,
                        'label' => $isAddon
                            ? ($addonNames[(int) $line->addon_id] ?? "Add-on #{$line->addon_id}")
                            : ($serviceNames[(int) $line->service_id] ?? "Service #{$line->service_id}"),
                        'price_at_booking' => $resolvedPrice['amount'],
                        'price_source' => $resolvedPrice['source'],
                    ];
                })
                ->values();
            $originalSubtotalCents = $lines->sum(
                fn (array $line) => $this->moneyToCents($line['price_at_booking']),
            );
            $state = (string) ($bookingPet->grooming_state
                ?: BookingPet::GROOMING_STATE_NOT_STARTED);
            $review = $hasReviewFoundation
                ? $bookingPet->groomingStoppedPaymentReview
                : null;
            $concern = $review?->groomingMedicalConcern;
            $paymentKind = 'blocked';
            $paymentReady = false;
            $blockedReason = null;
            $finalChargeCents = null;
            $clinicReferrals = $hasReferralFoundation
                ? $bookingPet->groomingClinicReferrals->filter(
                    fn (GroomingClinicReferral $referral) => $referral->status
                        !== GroomingClinicReferral::STATUS_CANCELLED
                        && (int) $referral->booking_id === (int) $bookingPet->booking_id
                        && (int) $referral->pet_id === (int) $bookingPet->pet_id,
                )
                : collect();
            $activeReferral = $clinicReferrals->first(
                fn (GroomingClinicReferral $referral) => in_array(
                    $referral->status,
                    [
                        GroomingClinicReferral::STATUS_PENDING_CONSENT,
                        GroomingClinicReferral::STATUS_PENDING_CLINIC_ACCEPTANCE,
                        GroomingClinicReferral::STATUS_ACCEPTED,
                        GroomingClinicReferral::STATUS_UNDER_CLINIC_REVIEW,
                    ],
                    true,
                ),
            );
            $clinicReferral = $activeReferral ?? $clinicReferrals->last();

            if ($activeReferral) {
                $blockedReason = 'Grooming payment is unavailable while '
                    .$this->petLabel($bookingPet).' has an active clinic referral.';
            } elseif ($state === BookingPet::GROOMING_STATE_FINISHED) {
                if ($bookingPet->grooming_end_time === null) {
                    $blockedReason = $this->petLabel($bookingPet)
                        .' is marked Finished but has no grooming finish time.';
                } else {
                    $paymentKind = 'finished';
                    $paymentReady = true;
                    $finalChargeCents = $originalSubtotalCents;
                }
            } elseif ($state === BookingPet::GROOMING_STATE_STOPPED) {
                if ($bookingPet->grooming_end_time !== null) {
                    $blockedReason = $this->petLabel($bookingPet)
                        .' is stopped but has a normal grooming finish time.';
                } elseif (! $this->isExactStoppedReview($bookingPet, $review, $concern)) {
                    $blockedReason = 'Payment review is required for '.$this->petLabel($bookingPet).'.';
                } else {
                    $paymentKind = 'stopped_reviewed';
                    $paymentReady = true;
                    $finalChargeCents = $this->moneyToCents($review->final_pet_charge);
                }
            } else {
                $blockedReason = $this->petLabel($bookingPet).' is '
                    .$this->groomingStateLabel($state).'.';
            }

            if ($paymentReady) {
                $bookingTotalCents += $finalChargeCents;
            } elseif ($firstBlockedReason === null) {
                $firstBlockedReason = $blockedReason;
            }

            return [
                'booking_pet_id' => (int) $bookingPet->booking_pet_id,
                'pet_id' => $bookingPet->pet_id !== null ? (int) $bookingPet->pet_id : null,
                'pet_name' => $bookingPet->pet?->pet_name ?? 'Pet',
                'pet_species' => $bookingPet->pet?->species,
                'grooming_state' => $state,
                'grooming_state_label' => $this->groomingStateLabel($state),
                'grooming_finish_time' => $bookingPet->grooming_end_time?->toIso8601String(),
                'payment_kind' => $paymentKind,
                'payment_ready' => $paymentReady,
                'payment_blocked_reason' => $blockedReason,
                'active_clinic_referral' => $activeReferral !== null,
                'has_clinic_referral' => $clinicReferral !== null,
                'clinic_referral_status' => $clinicReferral?->status,
                'service_breakdown' => $lines->all(),
                'original_pet_subtotal' => $this->centsToMoney($originalSubtotalCents),
                'final_pet_charge' => $finalChargeCents !== null
                    ? $this->centsToMoney($finalChargeCents)
                    : null,
                'adjustment' => $review
                    ? $this->centsToMoney(
                        $this->moneyToCents($review->original_pet_subtotal)
                        - $this->moneyToCents($review->final_pet_charge),
                    )
                    : '0.00',
                'review_status' => $review ? 'completed' : 'pending',
                'review_id' => $review?->id,
                'review_decision' => $review?->decision,
                'review_decision_label' => $review
                    ? GroomingStoppedPaymentReview::decisionLabel($review->decision)
                    : null,
                'review_original_pet_subtotal' => $review?->original_pet_subtotal,
                'customer_explanation' => $review?->customer_explanation,
                'reviewed_at' => $review?->reviewed_at?->toIso8601String(),
                'concern_public_id' => $concern?->public_id,
            ];
        })->values();

        if ($bookingPets->isEmpty()) {
            $firstBlockedReason = 'This booking has no pets to prepare for payment.';
        }

        return [
            'payment_ready' => $bookingPets->isNotEmpty() && $firstBlockedReason === null,
            'payment_blocked_reason' => $firstBlockedReason,
            'final_booking_total' => $firstBlockedReason === null
                ? $this->centsToMoney($bookingTotalCents)
                : null,
            'zero_total' => $firstBlockedReason === null && $bookingTotalCents === 0,
            'pets' => $pets->all(),
        ];
    }

    public function moneyToCents(string|int|float|null $amount): int
    {
        $value = trim((string) ($amount ?? '0'));
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');
        $cents = ((int) ($whole === '' ? 0 : $whole) * 100) + (int) $fraction;

        return $negative ? -$cents : $cents;
    }

    public function centsToMoney(int $cents): string
    {
        $prefix = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return sprintf('%s%d.%02d', $prefix, intdiv($absolute, 100), $absolute % 100);
    }

    private function isExactStoppedReview(
        BookingPet $bookingPet,
        ?GroomingStoppedPaymentReview $review,
        ?GroomingMedicalConcern $concern,
    ): bool {
        return $review !== null
            && $concern !== null
            && (int) $review->booking_id === (int) $bookingPet->booking_id
            && (int) $review->booking_pet_id === (int) $bookingPet->booking_pet_id
            && (int) $review->pet_id === (int) $bookingPet->pet_id
            && (int) $concern->id === (int) $review->grooming_medical_concern_id
            && (int) $concern->booking_id === (int) $bookingPet->booking_id
            && (int) $concern->booking_pet_id === (int) $bookingPet->booking_pet_id
            && (int) $concern->pet_id === (int) $bookingPet->pet_id
            && $concern->status !== GroomingMedicalConcern::STATUS_CANCELLED
            && $concern->recommended_grooming_action === GroomingMedicalConcern::ACTION_STOP_GROOMING
            && $concern->applied_grooming_action === GroomingMedicalConcern::ACTION_STOP_GROOMING
            && $concern->action_applied_at !== null
            && $concern->action_applied_by_user_id !== null;
    }

    private function petLabel(BookingPet $bookingPet): string
    {
        return $bookingPet->pet?->pet_name ?? "Booking pet #{$bookingPet->booking_pet_id}";
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

    private function lookupLabels(
        string $table,
        string $key,
        string $label,
        array $ids,
    ): array {
        if ($ids === [] || ! Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table)
            ->whereIn($key, $ids)
            ->pluck($label, $key)
            ->mapWithKeys(fn ($value, $id) => [(int) $id => $value])
            ->all();
    }
}
