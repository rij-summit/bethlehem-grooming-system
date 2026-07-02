<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Walkin extends Model
{
    protected $table = 'walkins';

    protected $fillable = [
        'fname',
        'lname',
        'mname',
        'email',
        'phone',
        'sedation_consent',
        'terms_agreed',
        'user_id',
    ];

    protected $casts = [
        'sedation_consent' => 'boolean',
        'terms_agreed'     => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function booking()
    {
        return $this->hasOne(Booking::class, 'walkin_id', 'id');
    }
}
