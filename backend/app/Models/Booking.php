<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    protected $table = 'bookings';
    protected $primaryKey = 'booking_id';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'booking_id',
        'booking_reference',
        'user_id',
        'window_id',
        'booking_date',
        'booking_type',
        'status',
        'queue_number',
        'walkin_name',
        'walkin_phone',
        'total_amount',
        'special_notes',
    ];

    protected $casts = [];
}
