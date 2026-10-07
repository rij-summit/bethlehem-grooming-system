<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryTransaction extends Model
{
    protected $table      = 'inventory_transactions';
    protected $primaryKey = 'transaction_id';

    // Only created_at — no updated_at
    public $timestamps = false;

    protected $fillable = [
        'item_id',
        'type',
        'quantity',
        'unit_cost_at_time',
        'selling_price_at_time',
        'reason',
        'batch_number',
        'expiry_date',
        'reference_type',
        'reference_id',
        'notes',
        'performed_by',
    ];

    protected $casts = [
        'quantity'              => 'decimal:2',
        'unit_cost_at_time'     => 'decimal:2',
        'selling_price_at_time' => 'decimal:2',
        'expiry_date'           => 'date',
        'created_at'            => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            $model->created_at = now();
        });
    }

    // ── Relationships ──────────────────────────────────────────────────────────

    // The saved receipt id is unique even when no manufacturer batch is supplied.
    // Deriving the reference also gives historical receipts an identifier without
    // rewriting the inventory ledger.
    public function getStockInReferenceAttribute(): ?string
    {
        if ($this->type !== 'stock_in') {
            return null;
        }

        return sprintf('SI-%s-%05d', $this->created_at?->format('Y') ?? '0000', $this->transaction_id);
    }

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'item_id', 'item_id');
    }

    public function performedBy()
    {
        return $this->belongsTo(User::class, 'performed_by', 'user_id');
    }

    public function groomingBooking()
    {
        return $this->belongsTo(Booking::class, 'reference_id', 'booking_id');
    }

    public function clinicAppointment()
    {
        return $this->belongsTo(ClinicAppointment::class, 'reference_id', 'id');
    }
}
