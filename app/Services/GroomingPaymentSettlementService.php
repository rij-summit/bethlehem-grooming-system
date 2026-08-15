<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\CustomerNotification;
use App\Models\Payment;
use Illuminate\Support\Facades\Schema;

class GroomingPaymentSettlementService
{
    public function __construct(
        private readonly GroomingPaymentReadinessService $paymentReadiness,
        private readonly GroomingClinicReferralAssessmentService $clinicAssessment,
    ) {}

    /**
     * Create the shared paid-payment audit record used by manual and automatic
     * grooming settlement. The caller must hold the booking row lock and must
     * validate the authoritative total before calling this method.
     */
    public function recordPaidPayment(
        Booking $booking,
        string $finalTotal,
        string $amountTendered,
        string $paymentMethod,
        ?string $notes,
        ?int $processedBy,
    ): Payment {
        $attributes = [
            'booking_id' => $booking->booking_id,
            'total_amount' => $finalTotal,
            'amount_tendered' => $amountTendered,
            'change_amount' => $this->paymentReadiness->centsToMoney(
                $this->paymentReadiness->moneyToCents($amountTendered)
                    - $this->paymentReadiness->moneyToCents($finalTotal),
            ),
            'payment_method' => $paymentMethod,
            'payment_status' => 'paid',
            'notes' => $notes,
            'paid_at' => now(),
        ];

        if (Schema::hasColumn('payments', 'processed_by')) {
            $attributes['processed_by'] = $processedBy;
        }

        return Payment::create($attributes);
    }

    /**
     * Automatically record an exact zero-total grooming payment after a staff
     * member has made the final No charge decision. Positive totals and any
     * incomplete pet or clinic work remain in the normal workflow.
     *
     * The caller must run this inside the same transaction that stores the
     * immutable stopped-grooming review and holds the booking row lock.
     */
    public function settleZeroTotalIfReady(
        Booking $booking,
        ?int $processedBy,
        bool $lockForUpdate = false,
    ): array {
        $summary = $this->paymentReadiness->summarize($booking, $lockForUpdate);
        $base = [
            'automatically_processed' => false,
            'already_processed' => false,
            'zero_total' => (bool) ($summary['zero_total'] ?? false),
            'automatic_processing_blocked_reason' => null,
            'payment' => null,
            'payment_summary' => $summary,
            'booking_status' => (string) $booking->status,
        ];

        if (! ($summary['payment_ready'] ?? false)) {
            return [
                ...$base,
                'automatic_processing_blocked_reason' => $summary['payment_blocked_reason'],
            ];
        }

        if (! ($summary['zero_total'] ?? false)) {
            return $base;
        }

        $existingPayment = Payment::query()
            ->where('booking_id', $booking->booking_id)
            ->where('payment_status', 'paid')
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate())
            ->first();

        if ((bool) $booking->paid || $existingPayment) {
            $isExactCompletedReplay = (bool) $booking->paid
                && $booking->status === 'released'
                && $existingPayment !== null
                && $this->paymentReadiness->moneyToCents($existingPayment->total_amount) === 0;

            return [
                ...$base,
                'already_processed' => $isExactCompletedReplay,
                'automatic_processing_blocked_reason' => $isExactCompletedReplay
                    ? null
                    : 'This booking already has payment activity and cannot be automatically processed.',
                'payment' => $isExactCompletedReplay ? $existingPayment : null,
            ];
        }

        if ($booking->status !== 'for_payment') {
            return [
                ...$base,
                'automatic_processing_blocked_reason' => 'The booking is not ready for final payment processing.',
            ];
        }

        $clinicBlockedReason = $this->clinicAssessment->pickupBlockedReason(
            $booking,
            $lockForUpdate,
        );
        if ($clinicBlockedReason !== null) {
            return [
                ...$base,
                'automatic_processing_blocked_reason' => $clinicBlockedReason,
            ];
        }

        $payment = $this->recordPaidPayment(
            $booking,
            '0.00',
            '0.00',
            'others',
            'Zero-total completion: no payment required. Automatically recorded after the final No charge stopped-grooming review.',
            $processedBy,
        );

        $booking->forceFill([
            'status' => 'released',
            'paid' => true,
            'total_amount' => '0.00',
            'archived_at' => null,
        ])->save();

        $this->notifyOwnerReadyForPickup($booking);

        return [
            ...$base,
            'automatically_processed' => true,
            'payment' => $payment,
            'booking_status' => 'released',
        ];
    }

    private function notifyOwnerReadyForPickup(Booking $booking): void
    {
        if (! $booking->user_id || ! Schema::hasTable('customer_notifications')) {
            return;
        }

        CustomerNotification::firstOrCreate(
            [
                'user_id' => $booking->user_id,
                'booking_id' => $booking->booking_id,
                'type' => 'ready_for_pickup',
            ],
            [
                'message' => 'Your pet is now Ready for Pickup. No grooming payment is required for this booking.',
                'is_read' => false,
                'created_at' => now(),
            ],
        );
    }
}
