<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingStaffAccount extends Model
{
    protected $fillable = [
        'requested_by_user_id',
        'staff_type',
        'username',
        'email',
        'password_hash',
        'code_hash',
        'failed_attempts',
        'expires_at',
        'last_sent_at',
    ];

    protected $hidden = [
        'password_hash',
        'code_hash',
    ];

    protected $casts = [
        'failed_attempts' => 'integer',
        'expires_at' => 'datetime',
        'last_sent_at' => 'datetime',
    ];

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by_user_id', 'user_id');
    }
}
