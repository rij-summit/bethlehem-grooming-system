<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordResetRequest extends Model
{
    protected $fillable = [
        'user_id',
        'token_hash',
        'expires_at',
        'last_sent_at',
        'verified_token_hash',
        'verification_attempts',
    ];

    protected $hidden = [
        'token_hash',
        'verified_token_hash',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'verification_attempts' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
