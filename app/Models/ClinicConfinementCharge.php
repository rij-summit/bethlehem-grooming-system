<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicConfinementCharge extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['started_at' => 'datetime', 'stopped_at' => 'datetime', 'removed_at' => 'datetime'];

    public function caseService()
    {
        return $this->belongsTo(ClinicCaseService::class, 'clinic_case_service_id');
    }
}
