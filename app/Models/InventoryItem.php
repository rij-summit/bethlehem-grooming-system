<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
    protected $table      = 'inventory_items';
    protected $primaryKey = 'item_id';

    protected $fillable = [
        'item_name',
        'barcode',
        'category',
        'unit',
        'description',
        'unit_cost',
        'selling_price',
        'quantity_on_hand',
        'reorder_level',
        'is_active',
    ];

    protected $casts = [
        'unit_cost'        => 'decimal:2',
        'selling_price'    => 'decimal:2',
        'quantity_on_hand' => 'decimal:2',
        'reorder_level'    => 'decimal:2',
        'is_active'        => 'boolean',
    ];

    // ── Computed attributes ────────────────────────────────────────────────────

    public function getLowStockAttribute(): bool
    {
        if ((float) $this->reorder_level <= 0) {
            return false;
        }
        return (float) $this->quantity_on_hand <= (float) $this->reorder_level;
    }

    // ── Relationships ──────────────────────────────────────────────────────────

    public function transactions()
    {
        return $this->hasMany(InventoryTransaction::class, 'item_id', 'item_id');
    }

    public function stockIns()
    {
        return $this->hasMany(InventoryTransaction::class, 'item_id', 'item_id')
                    ->where('type', 'stock_in');
    }

    public function stockOuts()
    {
        return $this->hasMany(InventoryTransaction::class, 'item_id', 'item_id')
                    ->where('type', 'stock_out');
    }

    // Returns stock_in records that have an expiry_date (for expiry monitoring)
    public function batches()
    {
        return $this->hasMany(InventoryTransaction::class, 'item_id', 'item_id')
                    ->where('type', 'stock_in')
                    ->whereNotNull('expiry_date')
                    ->orderBy('expiry_date');
    }
}
