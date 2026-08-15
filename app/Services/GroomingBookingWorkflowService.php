<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingPet;

class GroomingBookingWorkflowService
{
    private const TERMINAL_STATUSES = [
        'cancelled',
        'completed',
        'no_show',
        'released',
        'archived',
    ];

    public function __construct(
        private readonly GroomingPaymentReadinessService $paymentReadiness,
    ) {}

    /**
     * Recalculate the safe parent-booking transition after a pet, referral,
     * clinic assessment, or stopped-payment review changes.
     *
     * Human financial decisions remain outside this service. It only advances
     * an unpaid booking when every pet already has an authoritative charge.
     */
    public function reconcile(Booking $booking, bool $lockForUpdate = false): array
    {
        $summary = $this->paymentReadiness->summarize($booking, $lockForUpdate);
        $before = (string) $booking->status;

        if (
            ($summary['payment_ready'] ?? false)
            && ! (bool) $booking->paid
            && ! in_array($before, self::TERMINAL_STATUSES, true)
            && $booking->archived_at === null
            && $before !== 'for_payment'
        ) {
            $booking->forceFill(['status' => 'for_payment'])->save();
        }

        return [
            ...$summary,
            ...$this->describe($summary),
            'booking_status' => (string) $booking->status,
            'previous_booking_status' => $before,
            'status_changed' => $before !== (string) $booking->status,
        ];
    }

    /**
     * Describe work that requires a staff decision without changing data.
     */
    public function describe(array $paymentSummary): array
    {
        $pets = collect($paymentSummary['pets'] ?? []);
        $actionPets = $pets->filter(fn (array $pet): bool => ($pet['grooming_state'] ?? null) === BookingPet::GROOMING_STATE_STOPPED
            && ($pet['review_status'] ?? 'pending') !== 'completed'
            && ! (bool) ($pet['active_clinic_referral'] ?? false)
        )->values();
        $firstPetName = trim((string) ($actionPets->first()['pet_name'] ?? ''));

        return [
            'action_required' => $actionPets->isNotEmpty(),
            'action_required_type' => $actionPets->isNotEmpty()
                ? 'stopped_payment_review'
                : null,
            'action_required_reason' => $actionPets->isNotEmpty()
                ? 'Stopped-grooming payment review is required'
                    .($firstPetName !== '' ? " for {$firstPetName}." : '.')
                : null,
            'action_required_pet_ids' => $actionPets
                ->pluck('booking_pet_id')
                ->map(fn ($id): int => (int) $id)
                ->all(),
        ];
    }
}
