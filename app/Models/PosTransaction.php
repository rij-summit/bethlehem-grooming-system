<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosTransaction extends Model
{
    protected $table      = 'pos_transactions';
    protected $primaryKey = 'pos_id';

    // Only created_at — no updated_at
    public $timestamps = false;

    protected $fillable = [
        'cashier_id',
        'total_amount',
        'amount_tendered',
        'change_amount',
        'notes',
    ];

    protected $casts = [
        'total_amount'    => 'decimal:2',
        'amount_tendered' => 'decimal:2',
        'change_amount'   => 'decimal:2',
        'created_at'      => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            $model->created_at = now();
        });
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id', 'user_id');
    }

    public function items()
    {
        return $this->hasMany(PosTransactionItem::class, 'pos_id', 'pos_id');
    }
}
