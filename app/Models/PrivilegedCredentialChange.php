<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrivilegedCredentialChange extends Model
{
    protected $fillable = [
        'requested_by_user_id',
        'target_user_id',
        'target_role',
        'new_username',
        'new_password_hash',
        'changes_password',
        'code_hash',
        'failed_attempts',
        'last_sent_at',
        'expires_at',
        'confirmed_at',
    ];

    protected $hidden = [
        'new_password_hash',
        'code_hash',
    ];

    protected $casts = [
        'changes_password' => 'boolean',
        'failed_attempts' => 'integer',
        'last_sent_at' => 'datetime',
        'expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by_user_id', 'user_id');
    }

    public function target()
    {
        return $this->belongsTo(User::class, 'target_user_id', 'user_id');
    }
}
