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

    public function groomingMedicalConcernResponsesCaptured()
    {
        return $this->hasMany(
            GroomingMedicalConcernResponse::class,
            'captured_by_user_id',
            'user_id',
        );
    }

    public function groomingStoppedPaymentReviews()
    {
        return $this->hasMany(
            GroomingStoppedPaymentReview::class,
            'reviewed_by_user_id',
            'user_id',
        );
    }

    public function groomingClinicReferralsOwnedAtReferral()
    {
        return $this->hasMany(
            GroomingClinicReferral::class,
            'owner_user_id_at_referral',
            'user_id',
        );
    }

    public function groomingClinicReferralsReferred()
    {
        return $this->hasMany(
            GroomingClinicReferral::class,
            'referred_by_user_id',
            'user_id',
        );
    }

    public function groomingClinicReferralsAccepted()
    {
        return $this->hasMany(
            GroomingClinicReferral::class,
            'accepted_by_user_id',
            'user_id',
        );
    }

    public function groomingClinicReferralReviewsStarted()
    {
        return $this->hasMany(
            GroomingClinicReferral::class,
            'clinic_review_started_by_user_id',
            'user_id',
        );
    }

    public function groomingClinicReferralsCancelled()
    {
        return $this->hasMany(
            GroomingClinicReferral::class,
            'cancelled_by_user_id',
            'user_id',
        );
    }

    public function groomingClinicReferralsResolved()
    {
        return $this->hasMany(
            GroomingClinicReferral::class,
            'resolved_by_user_id',
            'user_id',
        );
    }
}
