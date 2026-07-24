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
        'username',
        'email',
        'phone',
        'password_hash',
        'role',
        'customer_tier',
        'is_active',
        'is_archived',
        'archived_at',
    ];

    protected $hidden = [
        'password_hash',
    ];

    // Tell Sanctum to use password_hash instead of password
    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    public function vaccinationsAdministered()
    {
        return $this->hasMany(VaccinationRecord::class, 'administered_by_user_id', 'user_id');
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

    public function groomingMedicalConcernsReported()
    {
        return $this->hasMany(GroomingMedicalConcern::class, 'reported_by_user_id', 'user_id');
    }

    public function groomingMedicalConcernActionsApplied()
    {
        return $this->hasMany(GroomingMedicalConcern::class, 'action_applied_by_user_id', 'user_id');
    }

    public function groomingMedicalConcernsResolved()
    {
        return $this->hasMany(GroomingMedicalConcern::class, 'resolved_by_user_id', 'user_id');
    }

    public function groomingMedicalConcernResponses()
    {
        return $this->hasMany(
            GroomingMedicalConcernResponse::class,
            'responded_by_user_id',
            'user_id',
        );
    }
}
