<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnregisteredCustomer extends Model
{
    protected $fillable = [
        'first_name',
        'last_name',
        'middle_name',
        'phone',
        'email',
        'created_by_user_id',
        'is_archived',
        'archived_at',
    ];

    protected $casts = [
        'is_archived' => 'boolean',
        'archived_at' => 'datetime',
    ];

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id', 'user_id');
    }

    public function pets()
    {
        return $this->hasMany(Pet::class, 'unregistered_customer_id');
    }

    public function walkins()
    {
        return $this->hasMany(Walkin::class, 'unregistered_customer_id');
    }
}
