<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicCharge extends Model
{
    protected $fillable = [
        'kind', 'description', 'inventory_item_id', 'vaccination_record_id',
        'quantity', 'unit_price', 'amount',
    ];

    protected $casts = [
        'pet_weight_kg' => 'decimal:2', 'min_weight_kg' => 'decimal:2', 'max_weight_kg' => 'decimal:2',
        'quantity' => 'decimal:2', 'unit_price' => 'decimal:2', 'amount' => 'decimal:2',
    ];
}
