<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class GroomingStoppedPaymentReview extends Model
{
    public const DECISION_FULL_CHARGE = 'full_charge';

    public const DECISION_PARTIAL_CHARGE = 'partial_charge';

    public const DECISION_NO_CHARGE = 'no_charge';

    public const DECISIONS = [
        self::DECISION_FULL_CHARGE,
        self::DECISION_PARTIAL_CHARGE,
        self::DECISION_NO_CHARGE,
    ];

    public const UPDATED_AT = null;

    protected $table = 'grooming_stopped_payment_reviews';

    protected $fillable = [
        'booking_id',
        'booking_pet_id',
        'pet_id',
        'grooming_medical_concern_id',
        'decision',
        'original_pet_subtotal',
        'final_pet_charge',
        'internal_reason',
        'customer_explanation',
        'reviewed_by_user_id',
        'reviewed_by_name',
        'reviewed_at',
    ];

    protected $casts = [
        'original_pet_subtotal' => 'decimal:2',
        'final_pet_charge' => 'decimal:2',
        'reviewed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException(
                'Stopped-grooming payment reviews are immutable and cannot be updated.',
            );
        });

        static::deleting(function (): never {
            throw new LogicException(
                'Stopped-grooming payment reviews are immutable and cannot be deleted.',
            );
        });
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }

    public function bookingPet()
    {
        return $this->belongsTo(BookingPet::class, 'booking_pet_id', 'booking_pet_id');
    }

    public function pet()
    {
        return $this->belongsTo(Pet::class, 'pet_id', 'pet_id');
    }

    public function groomingMedicalConcern()
    {
        return $this->belongsTo(
            GroomingMedicalConcern::class,
            'grooming_medical_concern_id',
        );
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id', 'user_id');
    }

    public static function isValidDecision(string $decision): bool
    {
        return in_array($decision, self::DECISIONS, true);
    }

    public static function decisionLabel(string $decision): string
    {
        return match ($decision) {
            self::DECISION_FULL_CHARGE => 'Full charge',
            self::DECISION_PARTIAL_CHARGE => 'Partial charge',
            self::DECISION_NO_CHARGE => 'No charge',
            default => 'Unknown decision',
        };
    }

    public function adjustmentAmount(): string
    {
        $adjustmentInCents = $this->moneyToCents($this->original_pet_subtotal)
            - $this->moneyToCents($this->final_pet_charge);

        return $this->centsToMoney($adjustmentInCents);
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
}
