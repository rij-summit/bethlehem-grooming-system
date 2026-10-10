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
        'quantity' => 'decimal:2', 'unit_price' => 'decimal:2', 'amount' => 'decimal:2',
    ];
}
