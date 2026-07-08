<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicRecord extends Model
{
    protected $table = 'clinic_records';

    protected $fillable = [
        'clinic_appointment_id',
        'chief_complaint',
        'diagnosis',
        'findings',
        'treatment_given',
        'follow_up_date',
        'follow_up_notes',
        'vet_notes',
    ];

    protected $casts = [
        'follow_up_date' => 'date',
    ];

    public function appointment()
    {
        return $this->belongsTo(ClinicAppointment::class, 'clinic_appointment_id', 'id');
    }

    public function medications()
    {
        return $this->hasMany(ClinicMedication::class, 'clinic_record_id', 'id');
    }

    public function attachments()
    {
        return $this->hasMany(ClinicAttachment::class, 'clinic_record_id', 'id');
    }
}
