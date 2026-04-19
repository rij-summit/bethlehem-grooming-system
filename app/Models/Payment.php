<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $table      = 'payments';
    protected $primaryKey = 'payment_id';
    const UPDATED_AT      = null;

    protected $fillable = [
        'booking_id',
        'total_amount',
        'amount_tendered',
        'change_amount',
        'payment_method',
        'payment_status',
        'notes',
        'paid_at',
        'processed_by',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }
}
