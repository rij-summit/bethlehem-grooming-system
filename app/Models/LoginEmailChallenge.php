<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginEmailChallenge extends Model
{
    protected $fillable = [
        'user_id',
        'token_hash',
        'poll_token_hash',
        'remember_me',
        'expires_at',
        'approved_at',
    ];

    protected $hidden = [
        'token_hash',
        'poll_token_hash',
    ];

    protected $casts = [
        'remember_me' => 'boolean',
        'expires_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
