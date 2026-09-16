<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Support\Facades\Schema;

class GroomingPaymentSettlementService
{
    public function __construct(
        private readonly GroomingPaymentReadinessService $paymentReadiness,
    ) {}

    /**
     * Create the shared paid-payment audit record used by grooming settlement.
     * The caller must hold the booking row lock and must
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

}
