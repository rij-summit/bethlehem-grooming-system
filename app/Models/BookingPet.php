<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingPet extends Model
{
    protected $table = 'booking_pets';
    protected $primaryKey = 'booking_pet_id';
    public $timestamps = false;

    protected $fillable = [
        'booking_id',
        'pet_id',
        'pet_queue_date',
        'pet_queue_number',
        'special_instructions',
        'groomer_id',
        'grooming_start_time',
        'grooming_end_time',
    ];

    protected $casts = [
        'pet_queue_date' => 'date',
        'pet_queue_number' => 'integer',
        'grooming_start_time' => 'datetime',
        'grooming_end_time' => 'datetime',
    ];

    // BookingPet belongs to a booking
    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }

    // BookingPet belongs to a pet
    public function pet()
    {
        return $this->belongsTo(Pet::class, 'pet_id', 'pet_id');
    }
}
