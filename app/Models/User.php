<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'users';
    protected $primaryKey = 'user_id';
    public $timestamps = false;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'password_hash',
        'role',
        'customer_tier',
        'is_active',
    ];

    protected $hidden = [
        'password_hash',
    ];

    // Tell Sanctum to use password_hash instead of password
    public function getAuthPassword()
    {
        return $this->password_hash;
    }
}
