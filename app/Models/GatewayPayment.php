<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class GatewayPayment extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:2', 'snapshot' => 'array',
        'livemode' => 'boolean', 'paid_at' => 'datetime',
    ];

    public function payable()
    {
        return $this->morphTo();
    }

    public static function forGrooming(Booking $booking)
    {
        return static::query()->where('provider', 'paymongo')->where('context', 'grooming')
            ->where('payable_type', $booking->getMorphClass())->where('payable_id', $booking->getKey());
    }

    /** Call while holding the booking row lock, also used by existing correction actions. */
    public static function assertGroomingUnlocked(Booking $booking): void
    {
        if (Schema::hasTable('gateway_payments') && static::forGrooming($booking)->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['payment' => 'Cancel the active online payment first.']);
        }
    }
}
