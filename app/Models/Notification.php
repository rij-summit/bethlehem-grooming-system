<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Notification extends Model
{
    public const CATEGORY_GROOMING = 'grooming';

    public const CATEGORY_CLINIC = 'clinic';

    public const CATEGORY_PAYMENTS = 'payments';

    public const CATEGORY_CANCELLATIONS = 'cancellations';

    public const TYPE_CLINIC_BOOKED = 'clinic_booked';

    public const TYPE_CLINIC_WALK_IN = 'clinic_walk_in';

    public const TYPE_CLINIC_PAYMENT_DUE = 'clinic_payment_due';

    public const TYPE_CLINIC_PAYMENT_CONFIRMED = 'clinic_payment_confirmed';

    public const TYPE_CLINIC_CANCELLED = 'clinic_cancelled';

    protected $table = 'notifications';

    protected $primaryKey = 'notification_id';

    public $timestamps = false;

    protected $fillable = [
        'type',
        'booking_id',
        'clinic_appointment_id',
        'message',
        'is_read',
        'created_at',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }

    public function clinicAppointment()
    {
        return $this->belongsTo(ClinicAppointment::class, 'clinic_appointment_id');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function categoryTypes(): array
    {
        return [
            self::CATEGORY_GROOMING => [
                'booked',
                'rescheduled',
                'no_show',
                'booking_confirmed',
                'status_update',
                'queue_update',
                'walk_in_registered',
            ],
            self::CATEGORY_CLINIC => [
                self::TYPE_CLINIC_BOOKED,
                self::TYPE_CLINIC_WALK_IN,
            ],
            self::CATEGORY_PAYMENTS => [
                'payment_due',
                'payment_confirmed',
                self::TYPE_CLINIC_PAYMENT_DUE,
                self::TYPE_CLINIC_PAYMENT_CONFIRMED,
            ],
            self::CATEGORY_CANCELLATIONS => [
                'cancelled',
                self::TYPE_CLINIC_CANCELLED,
            ],
        ];
    }

    public static function categoryForType(?string $type): string
    {
        foreach (self::categoryTypes() as $category => $types) {
            if (in_array($type, $types, true)) {
                return $category;
            }
        }

        return self::CATEGORY_GROOMING;
    }

    public static function createForClinic(
        ClinicAppointment $appointment,
        string $type,
        string $message,
    ): ?self {
        if (
            ! Schema::hasTable('notifications')
            || ! Schema::hasColumn('notifications', 'clinic_appointment_id')
        ) {
            return null;
        }

        return self::create([
            'type' => $type,
            'booking_id' => null,
            'clinic_appointment_id' => $appointment->id,
            'message' => $message,
            'is_read' => false,
            'created_at' => now(),
        ]);
    }
}
