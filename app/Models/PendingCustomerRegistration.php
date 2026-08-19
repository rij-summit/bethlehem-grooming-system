<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class PendingCustomerRegistration extends Model
{
    use Notifiable;

    protected $fillable = [
        'first_name',
        'last_name',
        'username',
        'phone',
        'email',
        'password_hash',
        'email_verification_token',
        'email_verification_expires_at',
    ];

    protected $hidden = [
        'password_hash',
        'email_verification_token',
    ];

    protected $casts = [
        'email_verification_expires_at' => 'datetime',
    ];
}
