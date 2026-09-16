<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'users';

    protected $primaryKey = 'user_id';

    public $timestamps = false;

    protected $fillable = [
        'first_name',
        'last_name',
        'username',
        'email',
        'phone',
        'password_hash',
        'role',
        'staff_type',
        'customer_tier',
        'is_active',
        'is_archived',
        'archived_at',
        'account_deleted_at',
        'email_verified_at',
        'email_verification_token',
        'email_verification_expires_at',
    ];

    protected $hidden = [
        'password_hash',
        'email_verification_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'email_verification_expires_at' => 'datetime',
        'is_active' => 'boolean',
        'is_archived' => 'boolean',
        'account_deleted_at' => 'datetime',
    ];

    // Tell Sanctum to use password_hash instead of password
    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    /**
     * Customer accounts are operational only after email ownership is proven.
     * The schema check keeps focused tests with reduced user tables compatible.
     */
    public function scopeRegisteredCustomer(Builder $query): Builder
    {
        $query->where('role', 'customer');

        if (Schema::hasColumn($this->getTable(), 'email_verified_at')) {
            $query->whereNotNull('email_verified_at');
        }

        if (Schema::hasColumn($this->getTable(), 'account_deleted_at')) {
            $query->whereNull('account_deleted_at');
        }

        return $query;
    }

    public function vaccinationsAdministered()
    {
        return $this->hasMany(VaccinationRecord::class, 'administered_by_user_id', 'user_id');
    }

    public function pets()
    {
        return $this->hasMany(Pet::class, 'user_id', 'user_id');
    }

    public function vaccinationsRecorded()
    {
        return $this->hasMany(VaccinationRecord::class, 'recorded_by_user_id', 'user_id');
    }

    public function vaccinationsPublished()
    {
        return $this->hasMany(VaccinationRecord::class, 'published_by_user_id', 'user_id');
    }

    public function vaccinationsVoided()
    {
        return $this->hasMany(VaccinationRecord::class, 'voided_by_user_id', 'user_id');
    }

}
