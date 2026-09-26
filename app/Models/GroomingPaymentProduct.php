<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GroomingPaymentProduct extends Model
{
    public $timestamps = false;

    protected $fillable = ['payment_id', 'item_id', 'item_name', 'quantity', 'price_at_sale', 'subtotal'];

    protected $casts = [
        'price_at_sale' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];
}
