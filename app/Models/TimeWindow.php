<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TimeWindow extends Model
{
    protected $table = 'time_windows';
    protected $primaryKey = 'window_id';
    public $timestamps = false;

    protected $fillable = [
        'start_time',
        'end_time',
        'is_active',
        'max_slots',
        'window_label',
    ];

    public function bookings()
    {
        return $this->hasMany(Booking::class, 'time_window_id', 'window_id');
    }
}
