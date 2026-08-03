<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class GroomingMedicalConcernResponse extends Model
{
    public const KIND_ACKNOWLEDGMENT = 'acknowledgment';

    public const KIND_CONSENT = 'consent';

    public const KIND_CLINIC_REFERRAL_CONSENT = 'clinic_referral_consent';

    public const KINDS = [
        self::KIND_ACKNOWLEDGMENT,
        self::KIND_CONSENT,
        self::KIND_CLINIC_REFERRAL_CONSENT,
    ];

    public const CHANNEL_PORTAL = 'portal';

    public const CHANNEL_IN_PERSON_STAFF_CAPTURED = 'in_person_staff_captured';

    public const CHANNELS = [
        self::CHANNEL_PORTAL,
        self::CHANNEL_IN_PERSON_STAFF_CAPTURED,
    ];

    private const CHANNEL_LABELS = [
        self::CHANNEL_PORTAL => 'Portal',
        self::CHANNEL_IN_PERSON_STAFF_CAPTURED => 'In-person, staff captured',
    ];

    public const DECISION_ACKNOWLEDGED = 'acknowledged';

    public const DECISION_APPROVED = 'approved';

    public const DECISION_DECLINED = 'declined';

    public const DECISIONS = [
        self::DECISION_ACKNOWLEDGED,
        self::DECISION_APPROVED,
        self::DECISION_DECLINED,
    ];

    public const UPDATED_AT = null;

    protected $fillable = [
        'concern_id',
        'responded_by_user_id',
        'responded_by_name',
        'response_channel',
        'captured_by_user_id',
        'captured_by_name',
        'response_kind',
        'decision',
        'statement_text',
        'statement_version',
        'signature_name',
        'responded_at',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException(
                'Grooming medical concern responses are immutable and cannot be updated.',
            );
        });

        static::deleting(function (): never {
            throw new LogicException(
                'Grooming medical concern responses are immutable and cannot be deleted.',
            );
        });
    }

    public function concern()
    {
        return $this->belongsTo(GroomingMedicalConcern::class, 'concern_id');
    }

    public function respondedBy()
    {
        return $this->belongsTo(User::class, 'responded_by_user_id', 'user_id');
    }

    public function capturedBy()
    {
        return $this->belongsTo(User::class, 'captured_by_user_id', 'user_id');
    }

    public function clinicReferralConsent()
    {
        return $this->hasOne(
            GroomingClinicReferral::class,
            'consent_response_id',
        );
    }

    public static function isValidKind(string $kind): bool
    {
        return in_array($kind, self::KINDS, true);
    }

    public static function isValidDecisionForKind(string $kind, string $decision): bool
    {
        return match ($kind) {
            self::KIND_ACKNOWLEDGMENT => $decision === self::DECISION_ACKNOWLEDGED,
            self::KIND_CONSENT,
            self::KIND_CLINIC_REFERRAL_CONSENT => in_array($decision, [
                self::DECISION_APPROVED,
                self::DECISION_DECLINED,
            ], true),
            default => false,
        };
    }

    public static function isValidChannel(string $channel): bool
    {
        return in_array($channel, self::CHANNELS, true);
    }

    public static function channelLabel(string $channel): string
    {
        return self::CHANNEL_LABELS[$channel] ?? 'Unknown';
    }
}
