<?php

namespace App\Models;

use Carbon\Carbon;
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
        return $this->hasMany(Booking::class, 'window_id', 'window_id');
    }

    public function clinicAppointments()
    {
        return $this->hasMany(ClinicAppointment::class, 'window_id', 'window_id');
    }

    public function displayLabel(): string
    {
        $label = trim((string) $this->window_label);

        if ($label !== '' && ! ctype_digit($label)) {
            return $label;
        }

        $formatTime = fn ($value) => Carbon::createFromFormat(
            'H:i:s',
            substr((string) $value, 0, 8),
        )->format('g:i A');

        return $formatTime($this->start_time).' - '.$formatTime($this->end_time);
    }
}
