<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerNotification extends Model
{
    protected $table = 'customer_notifications';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'booking_id',
        'grooming_medical_concern_id',
        'type',
        'message',
        'is_read',
        'created_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function groomingMedicalConcern()
    {
        return $this->belongsTo(
            GroomingMedicalConcern::class,
            'grooming_medical_concern_id',
        );
    }
}
