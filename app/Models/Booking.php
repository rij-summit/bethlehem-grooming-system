<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\BookingService;

class Booking extends Model
{
    protected $table = 'bookings';
    protected $primaryKey = 'booking_id';
    public $timestamps = false;

    protected $fillable = [
        'booking_reference',
        'user_id',
        'window_id',
        'booking_date',
        'number_of_pets',
        'booking_type',
        'status',
        'queue_number',
        'special_notes',
        'cancellation_reason',
        'total_amount',
        'reschedule_count',
        'cancel_count',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function timeWindow()
    {
        return $this->belongsTo(TimeWindow::class, 'window_id', 'window_id');
    }

    // Booking has many pets
    public function bookingPets()
    {
        return $this->hasMany(BookingPet::class, 'booking_id', 'booking_id');
    }

    // Booking has many services
    public function bookingServices()
    {
        return $this->hasMany(BookingService::class, 'booking_id', 'booking_id');
    }
}
