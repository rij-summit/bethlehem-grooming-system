<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

class GroomingClinicReferral extends Model
{
    public const STATUS_PENDING_CONSENT = 'pending_consent';

    public const STATUS_PENDING_CLINIC_ACCEPTANCE = 'pending_clinic_acceptance';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_UNDER_CLINIC_REVIEW = 'under_clinic_review';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING_CONSENT,
        self::STATUS_PENDING_CLINIC_ACCEPTANCE,
        self::STATUS_ACCEPTED,
        self::STATUS_UNDER_CLINIC_REVIEW,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    public const URGENCY_ROUTINE = 'routine';

    public const URGENCY_URGENT = 'urgent';

    public const URGENCY_EMERGENCY = 'emergency';

    public const URGENCIES = [
        self::URGENCY_ROUTINE,
        self::URGENCY_URGENT,
        self::URGENCY_EMERGENCY,
    ];

    public const CLEARANCE_PENDING = 'pending';

    public const CLEARANCE_CLEARED_TO_RESUME = 'cleared_to_resume';

    public const CLEARANCE_DO_NOT_RESUME = 'do_not_resume';

    public const CLEARANCE_NOT_APPLICABLE = 'not_applicable';

    public const GROOMING_CLEARANCE_STATUSES = [
        self::CLEARANCE_PENDING,
        self::CLEARANCE_CLEARED_TO_RESUME,
        self::CLEARANCE_DO_NOT_RESUME,
        self::CLEARANCE_NOT_APPLICABLE,
    ];

    public const UPDATED_AT = null;

    private const STATUS_LABELS = [
        self::STATUS_PENDING_CONSENT => 'Pending consent',
        self::STATUS_PENDING_CLINIC_ACCEPTANCE => 'Pending clinic acceptance',
        self::STATUS_ACCEPTED => 'Accepted',
        self::STATUS_UNDER_CLINIC_REVIEW => 'Under clinic review',
        self::STATUS_COMPLETED => 'Completed',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    private const URGENCY_LABELS = [
        self::URGENCY_ROUTINE => 'Routine',
        self::URGENCY_URGENT => 'Urgent',
        self::URGENCY_EMERGENCY => 'Emergency',
    ];

    private const CLEARANCE_LABELS = [
        self::CLEARANCE_PENDING => 'Pending',
        self::CLEARANCE_CLEARED_TO_RESUME => 'Cleared to resume',
        self::CLEARANCE_DO_NOT_RESUME => 'Do not resume',
        self::CLEARANCE_NOT_APPLICABLE => 'Not Applicable — Grooming Session Stopped',
    ];

    private const IMMUTABLE_COLUMNS = [
        'public_id',
        'request_token',
        'grooming_medical_concern_id',
        'booking_id',
        'booking_pet_id',
        'pet_id',
        'referral_reason',
        'customer_explanation',
        'urgency',
        'consent_required',
        'owner_user_id_at_referral',
        'owner_name_at_referral',
        'referred_by_user_id',
        'referred_by_name',
        'referred_at',
        'created_at',
    ];

    protected $fillable = [
        'public_id',
        'request_token',
        'grooming_medical_concern_id',
        'booking_id',
        'booking_pet_id',
        'pet_id',
        'clinic_appointment_id',
        'status',
        'urgency',
        'referral_reason',
        'customer_explanation',
        'consent_required',
        'consent_response_id',
        'owner_user_id_at_referral',
        'owner_name_at_referral',
        'referred_by_user_id',
        'referred_by_name',
        'referred_at',
        'customer_notified_at',
        'emergency_without_consent_reason',
        'accepted_by_user_id',
        'accepted_by_name',
        'accepted_at',
        'clinic_review_started_by_user_id',
        'clinic_review_started_by_name',
        'clinic_review_started_at',
        'grooming_clearance_status',
        'cancelled_by_user_id',
        'cancelled_by_name',
        'cancelled_at',
        'cancellation_reason',
        'customer_cancellation_summary',
        'resolved_by_user_id',
        'resolved_by_name',
        'resolved_at',
        'internal_resolution_notes',
        'customer_resolution_summary',
    ];

    protected $casts = [
        'consent_required' => 'boolean',
        'referred_at' => 'datetime',
        'customer_notified_at' => 'datetime',
        'accepted_at' => 'datetime',
        'clinic_review_started_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $referral): void {
            if (! filled($referral->public_id)) {
                $referral->public_id = (string) Str::uuid();
            }
        });

        static::updating(function (self $referral): void {
            $changedImmutableColumns = array_intersect(
                array_keys($referral->getDirty()),
                self::IMMUTABLE_COLUMNS,
            );

            if ($changedImmutableColumns !== []) {
                throw new LogicException(
                    'Original grooming clinic-referral facts cannot be changed.',
                );
            }
        });

        static::deleting(function (): never {
            throw new LogicException(
                'Grooming clinic referrals are historical records and cannot be deleted.',
            );
        });
    }

    public function groomingMedicalConcern()
    {
        return $this->belongsTo(
            GroomingMedicalConcern::class,
            'grooming_medical_concern_id',
        );
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }

    public function bookingPet()
    {
        return $this->belongsTo(BookingPet::class, 'booking_pet_id', 'booking_pet_id');
    }

    public function pet()
    {
        return $this->belongsTo(Pet::class, 'pet_id', 'pet_id');
    }

    public function clinicAppointment()
    {
        return $this->belongsTo(ClinicAppointment::class, 'clinic_appointment_id');
    }

    public function consentResponse()
    {
        return $this->belongsTo(
            GroomingMedicalConcernResponse::class,
            'consent_response_id',
        );
    }

    public function ownerAtReferral()
    {
        return $this->belongsTo(User::class, 'owner_user_id_at_referral', 'user_id');
    }

    public function referredBy()
    {
        return $this->belongsTo(User::class, 'referred_by_user_id', 'user_id');
    }

    public function acceptedBy()
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id', 'user_id');
    }

    public function clinicReviewStartedBy()
    {
        return $this->belongsTo(
            User::class,
            'clinic_review_started_by_user_id',
            'user_id',
        );
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id', 'user_id');
    }

    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id', 'user_id');
    }

    public function notifications()
    {
        return $this->hasMany(
            CustomerNotification::class,
            'grooming_clinic_referral_id',
        );
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
        ]);
    }

    public function scopePendingConsent(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING_CONSENT);
    }

    public function scopePendingClinicAcceptance(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING_CLINIC_ACCEPTANCE);
    }

    public function scopeUnderClinicReview(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_UNDER_CLINIC_REVIEW);
    }

    public function scopeTerminal(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
        ]);
    }

    public function scopeUrgentOrEmergency(Builder $query): Builder
    {
        return $query->whereIn('urgency', [
            self::URGENCY_URGENT,
            self::URGENCY_EMERGENCY,
        ]);
    }

    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }

    public static function isValidUrgency(string $urgency): bool
    {
        return in_array($urgency, self::URGENCIES, true);
    }

    public static function isValidGroomingClearanceStatus(string $status): bool
    {
        return in_array($status, self::GROOMING_CLEARANCE_STATUSES, true);
    }

    public static function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? 'Unknown status';
    }

    public static function urgencyLabel(string $urgency): string
    {
        return self::URGENCY_LABELS[$urgency] ?? 'Unknown urgency';
    }

    public static function groomingClearanceLabel(string $status): string
    {
        return self::CLEARANCE_LABELS[$status] ?? 'Unknown clearance';
    }
}
