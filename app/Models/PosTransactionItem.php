<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosTransactionItem extends Model
{
    protected $table      = 'pos_transaction_items';
    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
        'pos_id',
        'item_id',
        'quantity',
        'price_at_sale',
        'subtotal',
    ];

    protected $casts = [
        'quantity'      => 'decimal:2',
        'price_at_sale' => 'decimal:2',
        'subtotal'      => 'decimal:2',
    ];

    public function posTransaction()
    {
        return $this->belongsTo(PosTransaction::class, 'pos_id', 'pos_id');
    }

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'item_id', 'item_id');
    }
}
