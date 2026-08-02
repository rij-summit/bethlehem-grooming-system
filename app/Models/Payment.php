<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Payment extends Model
{
    protected $table = 'payments';

    protected $primaryKey = 'payment_id';

    const UPDATED_AT = null;

    protected $fillable = [
        'booking_id',
        'total_amount',
        'amount_tendered',
        'change_amount',
        'payment_method',
        'payment_status',
        'notes',
        'paid_at',
        'processed_by',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'total_amount' => 'decimal:2',
        'amount_tendered' => 'decimal:2',
        'change_amount' => 'decimal:2',
    ];

    /**
     * The active schema uses payment_id while the fresh-install migration uses
     * Laravel's conventional id. Resolve that known drift without depending on
     * either key name in payment workflows.
     */
    public function getKeyName()
    {
        return Schema::hasColumn($this->getTable(), 'payment_id')
            ? 'payment_id'
            : 'id';
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }
}
