<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryItemWeightPrice extends Model
{
    protected $fillable = ['min_weight_kg', 'max_weight_kg', 'price'];

    protected $casts = [
        'min_weight_kg' => 'decimal:2', 'max_weight_kg' => 'decimal:2', 'price' => 'decimal:2',
    ];

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'item_id', 'item_id');
    }
}
