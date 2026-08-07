<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerNotification extends Model
{
    public const TYPE_BOOKING_CANCELLED = 'booking_cancelled';

    public const TYPE_GROOMING_MEDICAL_CONCERN = 'grooming_medical_concern';

    public const TYPE_GROOMING_CLINIC_REFERRAL_REQUESTED = 'grooming_clinic_referral_requested';

    public const TYPE_GROOMING_CLINIC_REFERRAL_ACCEPTED = 'grooming_clinic_referral_accepted';

    public const TYPE_GROOMING_CLINIC_ASSESSMENT_STARTED = 'grooming_clinic_assessment_started';

    public const TYPE_GROOMING_CLINIC_ASSESSMENT_COMPLETED = 'grooming_clinic_assessment_completed';

    protected $table = 'customer_notifications';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'booking_id',
        'grooming_medical_concern_id',
        'grooming_clinic_referral_id',
        'type',
        'message',
        'is_read',
        'created_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function groomingMedicalConcern()
    {
        return $this->belongsTo(
            GroomingMedicalConcern::class,
            'grooming_medical_concern_id',
        );
    }

    public function groomingClinicReferral()
    {
        return $this->belongsTo(
            GroomingClinicReferral::class,
            'grooming_clinic_referral_id',
        );
    }
}
