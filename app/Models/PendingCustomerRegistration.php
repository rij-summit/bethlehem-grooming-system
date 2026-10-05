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
        'email_verification_code_hash',
        'email_verification_attempts',
        'email_verification_last_sent_at',
        'registration_edit_token_hash',
    ];

    protected $hidden = [
        'password_hash',
        'email_verification_token',
        'email_verification_code_hash',
        'registration_edit_token_hash',
    ];

    protected $casts = [
        'email_verification_expires_at' => 'datetime',
        'email_verification_last_sent_at' => 'datetime',
        'email_verification_attempts' => 'integer',
    ];
}
