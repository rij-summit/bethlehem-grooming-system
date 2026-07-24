<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

class GroomingMedicalConcern extends Model
{
    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MODERATE = 'moderate';

    public const SEVERITY_URGENT = 'urgent';

    public const SEVERITIES = [
        self::SEVERITY_LOW,
        self::SEVERITY_MODERATE,
        self::SEVERITY_URGENT,
    ];

    public const ACTION_CONTINUE_WITH_OBSERVATION = 'continue_with_observation';

    public const ACTION_PAUSE_GROOMING = 'pause_grooming';

    public const ACTION_STOP_GROOMING = 'stop_grooming';

    public const GROOMING_ACTIONS = [
        self::ACTION_CONTINUE_WITH_OBSERVATION,
        self::ACTION_PAUSE_GROOMING,
        self::ACTION_STOP_GROOMING,
    ];

    public const STATUS_OPEN = 'open';

    public const STATUS_AWAITING_CUSTOMER = 'awaiting_customer';

    public const STATUS_REFERRED_TO_CLINIC = 'referred_to_clinic';

    public const STATUS_UNDER_CLINIC_REVIEW = 'under_clinic_review';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_AWAITING_CUSTOMER,
        self::STATUS_REFERRED_TO_CLINIC,
        self::STATUS_UNDER_CLINIC_REVIEW,
        self::STATUS_RESOLVED,
        self::STATUS_CANCELLED,
    ];

    public const CUSTOMER_RESPONSE_NOT_REQUIRED = 'not_required';

    public const CUSTOMER_RESPONSE_PENDING = 'pending';

    public const CUSTOMER_RESPONSE_ACKNOWLEDGED = 'acknowledged';

    public const CUSTOMER_RESPONSE_APPROVED = 'approved';

    public const CUSTOMER_RESPONSE_DECLINED = 'declined';

    public const CUSTOMER_RESPONSE_STATUSES = [
        self::CUSTOMER_RESPONSE_NOT_REQUIRED,
        self::CUSTOMER_RESPONSE_PENDING,
        self::CUSTOMER_RESPONSE_ACKNOWLEDGED,
        self::CUSTOMER_RESPONSE_APPROVED,
        self::CUSTOMER_RESPONSE_DECLINED,
    ];

    protected $fillable = [
        'public_id',
        'report_token',
        'booking_id',
        'booking_pet_id',
        'pet_id',
        'reported_by_user_id',
        'reported_by_name',
        'reported_at',
        'category',
        'severity',
        'internal_description',
        'customer_message',
        'recommended_grooming_action',
        'applied_grooming_action',
        'action_applied_at',
        'action_applied_by_user_id',
        'status',
        'acknowledgment_required',
        'consent_required',
        'customer_response_status',
        'customer_notified_at',
        'clinic_appointment_id',
        'customer_resolution_summary',
        'internal_resolution_notes',
        'resolved_at',
        'resolved_by_user_id',
        'resolved_by_name',
    ];

    protected $casts = [
        'reported_at' => 'datetime',
        'action_applied_at' => 'datetime',
        'acknowledgment_required' => 'boolean',
        'consent_required' => 'boolean',
        'customer_notified_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $concern): void {
            if (! filled($concern->public_id)) {
                $concern->public_id = (string) Str::uuid();
            }
        });

        static::deleting(function (): never {
            throw new LogicException(
                'Grooming medical concerns are historical records and cannot be deleted.',
            );
        });
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

    public function reportedBy()
    {
        return $this->belongsTo(User::class, 'reported_by_user_id', 'user_id');
    }

    public function actionAppliedBy()
    {
        return $this->belongsTo(User::class, 'action_applied_by_user_id', 'user_id');
    }

    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id', 'user_id');
    }

    public function clinicAppointment()
    {
        return $this->belongsTo(ClinicAppointment::class, 'clinic_appointment_id', 'id');
    }

    public function responses()
    {
        return $this->hasMany(GroomingMedicalConcernResponse::class, 'concern_id');
    }

    public function notifications()
    {
        return $this->hasMany(CustomerNotification::class, 'grooming_medical_concern_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            self::STATUS_RESOLVED,
            self::STATUS_CANCELLED,
        ]);
    }

    public function scopeAwaitingCustomer(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_AWAITING_CUSTOMER);
    }

    public function scopeResolved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_RESOLVED);
    }

    public function scopeCustomerVisible(Builder $query): Builder
    {
        return $query->whereNotNull('customer_notified_at');
    }

    public function isCustomerVisible(): bool
    {
        return $this->customer_notified_at !== null;
    }

    public static function isValidSeverity(string $severity): bool
    {
        return in_array($severity, self::SEVERITIES, true);
    }

    public static function isValidGroomingAction(string $action): bool
    {
        return in_array($action, self::GROOMING_ACTIONS, true);
    }

    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }

    public static function isValidCustomerResponseStatus(string $status): bool
    {
        return in_array($status, self::CUSTOMER_RESPONSE_STATUSES, true);
    }
}
