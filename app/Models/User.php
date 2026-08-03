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
