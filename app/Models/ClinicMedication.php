<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicMedication extends Model
{
    protected $table = 'clinic_medications';

    protected $fillable = [
        'clinic_record_id',
        'drug_name',
        'dosage',
        'frequency',
        'duration',
        'instructions',
    ];
}
