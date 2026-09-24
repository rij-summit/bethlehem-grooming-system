<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicAppointment extends Model
{
    protected $table = 'clinic_appointments';

    protected $fillable = [
        'appointment_reference',
        'appointment_type',
        'case_type',
        'status',
        'queue_number',
        'appointment_date',
        'window_id',
        'user_id',
        'walkin_id',
        'pet_id',
        'chief_complaint',
        'common_concerns',
        'total_amount',
        'paid',
        'notes',
        'checked_in_at',
        'consultation_started_at',
        'consultation_finished_at',
        'archived_at',
    ];

    protected $casts = [
        'common_concerns'         => 'array',
        'paid'                    => 'boolean',
        'appointment_date'        => 'date',
        'checked_in_at'           => 'datetime',
        'consultation_started_at' => 'datetime',
        'consultation_finished_at'=> 'datetime',
        'archived_at'             => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function walkin()
    {
        return $this->belongsTo(Walkin::class, 'walkin_id', 'id');
    }

    public function pet()
    {
        return $this->belongsTo(Pet::class, 'pet_id', 'pet_id');
    }

    public function timeWindow()
    {
        return $this->belongsTo(TimeWindow::class, 'window_id', 'window_id');
    }

    public function record()
    {
        return $this->hasOne(ClinicRecord::class, 'clinic_appointment_id', 'id');
    }

    public function vitals()
    {
        return $this->hasOne(ClinicVital::class, 'clinic_appointment_id', 'id');
    }

    public function vaccinationRecords()
    {
        return $this->hasMany(VaccinationRecord::class, 'clinic_appointment_id', 'id');
    }
}
