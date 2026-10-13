<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicCharge extends Model
{
    // Future catalog charges retain this reference; description/unit_price/amount
    // are immutable charge snapshots, never resolved from current catalog prices.
    public function clinicService(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ClinicService::class);
    }

    protected $fillable = [
        'kind', 'description', 'inventory_item_id', 'vaccination_record_id', 'clinic_service_id',
        'quantity', 'unit_price', 'amount',
    ];

    protected $casts = [
        'pet_weight_kg' => 'decimal:2', 'min_weight_kg' => 'decimal:2', 'max_weight_kg' => 'decimal:2',
        'quantity' => 'decimal:2', 'unit_price' => 'decimal:2', 'amount' => 'decimal:2',
    ];
}
