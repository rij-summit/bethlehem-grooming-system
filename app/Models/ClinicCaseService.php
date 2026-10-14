<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ClinicCaseService extends Model
{
    public const STATUSES = ['ordered', 'performed', 'cancelled'];

    protected $fillable = ['clinic_service_id', 'request_key', 'quantity', 'notes'];

    protected $casts = [
        'quantity' => 'decimal:2', 'applied_price' => 'decimal:2', 'service_snapshot' => 'array',
        'ordered_at' => 'datetime', 'performed_at' => 'datetime', 'cancelled_at' => 'datetime',
    ];

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(ClinicAppointment::class, 'clinic_appointment_id');
    }

    public function clinicService(): BelongsTo
    {
        return $this->belongsTo(ClinicService::class);
    }

    public function charge(): HasOne
    {
        return $this->hasOne(ClinicCharge::class);
    }

    public function confinement(): HasOne
    {
        return $this->hasOne(ClinicConfinement::class);
    }

    public function confinementCharge(): HasOne
    {
        return $this->hasOne(ClinicConfinementCharge::class);
    }
}
