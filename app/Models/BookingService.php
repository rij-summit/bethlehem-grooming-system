<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingService extends Model
{
    protected $table = 'booking_services';
    protected $primaryKey = 'booking_service_id';
    public $timestamps = false;

    protected $fillable = [
        'booking_id',
        'booking_pet_id',
        'service_id',
        'addon_id',
        'price_at_booking',
    ];

    public function service()
    {
        return $this->belongsTo(Service::class, 'service_id', 'service_id');
    }
}
