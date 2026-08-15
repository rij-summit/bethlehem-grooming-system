<?php

namespace App\Services;

use App\Exceptions\ClinicReferralAcceptanceException;
use App\Models\Booking;
use App\Models\BookingPet;
use App\Models\ClinicAppointment;
use App\Models\ClinicClosure;
use App\Models\CustomerNotification;
use App\Models\GroomingClinicReferral;
use App\Models\GroomingMedicalConcern;
use App\Models\GroomingMedicalConcernResponse;
use App\Models\Pet;
use App\Models\User;
use App\Models\Walkin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GroomingClinicReferralAcceptanceService
{
    private const QUEUE_STATUSES = [
        GroomingClinicReferral::STATUS_PENDING_CLINIC_ACCEPTANCE,
        GroomingClinicReferral::STATUS_ACCEPTED,
        GroomingClinicReferral::STATUS_UNDER_CLINIC_REVIEW,
        GroomingClinicReferral::STATUS_COMPLETED,
    ];

    private const ACTIVE_APPOINTMENT_STATUSES = [
        'waiting_to_arrive',
        'checked_in',
        'in_consultation',
        'for_payment',
    ];

    private const BLOCKED_BOOKING_STATUSES = [
        'released',
        'archived',
        'cancelled',
        'no_show',
    ];

    public function __construct(
        private readonly ClinicAppointmentSequence $clinicSequence,
    ) {}

    /**
     * @param  array{status?: string, urgency?: string, search?: string}  $filters
     * @return array{referrals: array<int, array<string, mixed>>, pending_count: int}
     */
    public function queue(array $filters): array
    {
        $query = GroomingClinicReferral::query()
            ->with($this->responseRelations())
            ->whereIn('status', self::QUEUE_STATUSES);

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (filled($filters['urgency'] ?? null)) {
            $query->where('urgency', $filters['urgency']);
        }

        if (filled($filters['search'] ?? null)) {
            $search = trim((string) $filters['search']);
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery
                    ->where('public_id', 'like', "%{$search}%")
                    ->orWhere('owner_name_at_referral', 'like', "%{$search}%")
                    ->orWhereHas('booking', fn (Builder $booking) => $booking
                        ->where('booking_reference', 'like', "%{$search}%"))
                    ->orWhereHas('pet', fn (Builder $pet) => $pet
                        ->where('pet_name', 'like', "%{$search}%"));
            });
        }

        $referrals = $query
            ->orderByRaw(
                "CASE urgency WHEN 'emergency' THEN 1 WHEN 'urgent' THEN 2 WHEN 'routine' THEN 3 ELSE 4 END",
            )
            ->orderBy('referred_at')
            ->orderBy('id')
            ->get()
            ->map(fn (GroomingClinicReferral $referral) => $this->present($referral))
            ->values()
            ->all();

        return [
            'referrals' => $referrals,
            'pending_count' => GroomingClinicReferral::query()
                ->where('status', GroomingClinicReferral::STATUS_PENDING_CLINIC_ACCEPTANCE)
                ->count(),
        ];
    }

    public function findForStaff(string $publicId): ?GroomingClinicReferral
    {
        return GroomingClinicReferral::query()
            ->where('public_id', $publicId)
            ->with($this->responseRelations())
            ->first();
    }

    /** @return array<string, mixed> */
    public function present(GroomingClinicReferral $referral): array
    {
        $referral->loadMissing($this->responseRelations());
        $booking = $referral->booking;
        $bookingPet = $referral->bookingPet;
        $pet = $referral->pet;
        $concern = $referral->groomingMedicalConcern;
        $response = $this->exactConsentResponse($referral);
        $availability = $this->acceptanceAvailability(
            $referral,
            $booking,
            $bookingPet,
            $pet,
            $concern,
            $response,
        );
        $appointment = $referral->clinicAppointment;
        $stoppedReview = $bookingPet && Schema::hasTable('grooming_stopped_payment_reviews')
            ? $bookingPet->groomingStoppedPaymentReview
            : null;
        $paymentReadiness = $booking && Schema::hasTable('booking_services')
            ? app(GroomingPaymentReadinessService::class)->summarize($booking)
            : null;
        $pickupBlockedReason = $booking
            ? app(GroomingClinicReferralAssessmentService::class)
                ->pickupBlockedReason($booking)
            : null;

        return [
            'public_id' => $referral->public_id,
            'status' => $referral->status,
            'status_label' => GroomingClinicReferral::statusLabel($referral->status),
            'urgency' => $referral->urgency,
            'urgency_label' => GroomingClinicReferral::urgencyLabel($referral->urgency),
            'referred_at' => $referral->referred_at?->toIso8601String(),
            'referred_by_name' => $referral->referred_by_name,
            'booking_id' => $booking?->booking_id,
            'booking_reference' => $booking?->booking_reference,
            'booking_pet_id' => $bookingPet?->booking_pet_id,
            'pet' => $pet ? [
                'pet_id' => $pet->pet_id,
                'name' => $pet->pet_name,
                'species' => $pet->species,
                'breed' => $pet->breed,
                'size' => $pet->size,
                'weight' => $pet->weight,
            ] : null,
            'owner_name' => $referral->owner_name_at_referral,
            'owner_account_linked' => $referral->owner_user_id_at_referral !== null,
            'concern' => $concern ? [
                'public_id' => $concern->public_id,
                'category' => $concern->category,
                'severity' => $concern->severity,
                'status' => $concern->status,
                'applied_grooming_action' => $concern->applied_grooming_action,
                'action_applied_at' => $concern->action_applied_at?->toIso8601String(),
            ] : null,
            'referral_reason' => $referral->referral_reason,
            'customer_explanation' => $referral->customer_explanation,
            'grooming_state' => $bookingPet?->grooming_state,
            'grooming_state_label' => $this->groomingStateLabel($bookingPet?->grooming_state),
            'consent_required' => (bool) $referral->consent_required,
            'consent_state' => $response ? 'recorded' : 'pending',
            'consent_decision' => $response?->decision,
            'consent_response_channel' => $response?->response_channel,
            'consent_response_channel_label' => $response
                ? GroomingMedicalConcernResponse::channelLabel($response->response_channel)
                : null,
            'consent_responded_by_name' => $response?->responded_by_name,
            'consent_responded_at' => $response?->responded_at?->toIso8601String(),
            'intake_before_consent_documented' => filled(
                $referral->emergency_without_consent_reason,
            ),
            'can_accept' => $availability['can_accept'],
            'acceptance_blocked_reasons' => $availability['blocked_reasons'],
            'financial_correction_review_required' => (bool) $booking?->paid,
            'accepted_by_name' => $referral->accepted_by_name,
            'accepted_at' => $referral->accepted_at?->toIso8601String(),
            'clinic_review_started_by_name' => $referral->clinic_review_started_by_name,
            'clinic_review_started_at' => $referral->clinic_review_started_at?->toIso8601String(),
            'assessment_started' => $referral->clinic_review_started_at !== null,
            'assessment_completed' => $referral->resolved_at !== null,
            'resolved_by_name' => $referral->resolved_by_name,
            'resolved_at' => $referral->resolved_at?->toIso8601String(),
            'internal_resolution_notes' => $referral->internal_resolution_notes,
            'customer_resolution_summary' => $referral->customer_resolution_summary,
            'grooming_outcome' => in_array($referral->status, [
                GroomingClinicReferral::STATUS_ACCEPTED,
                GroomingClinicReferral::STATUS_UNDER_CLINIC_REVIEW,
                GroomingClinicReferral::STATUS_COMPLETED,
            ], true) ? 'stopped' : null,
            'grooming_outcome_label' => in_array($referral->status, [
                GroomingClinicReferral::STATUS_ACCEPTED,
                GroomingClinicReferral::STATUS_UNDER_CLINIC_REVIEW,
                GroomingClinicReferral::STATUS_COMPLETED,
            ], true) ? 'Grooming Session Stopped' : null,
            'stopped_payment_review_status' => $stoppedReview ? 'completed' : 'pending',
            'grooming_payment_ready' => $paymentReadiness['payment_ready'] ?? false,
            'grooming_payment_blocked_reason' => $paymentReadiness['payment_blocked_reason'] ?? null,
            'physical_pickup_blocked_reason' => $pickupBlockedReason,
            'accepted_notification_sent' => $referral->notifications
                ->contains('type', CustomerNotification::TYPE_GROOMING_CLINIC_REFERRAL_ACCEPTED),
            'assessment_started_notification_sent' => $referral->notifications
                ->contains('type', CustomerNotification::TYPE_GROOMING_CLINIC_ASSESSMENT_STARTED),
            'assessment_completed_notification_sent' => $referral->notifications
                ->contains('type', CustomerNotification::TYPE_GROOMING_CLINIC_ASSESSMENT_COMPLETED),
            'clinic_appointment' => $appointment ? [
                'id' => $appointment->id,
                'reference' => $appointment->appointment_reference,
                'status' => $appointment->status,
                'status_label' => $this->appointmentStatusLabel($appointment->status),
                'queue_number' => $appointment->queue_number,
                'appointment_date' => $appointment->appointment_date?->toDateString(),
                'consultation_started' => $appointment->consultation_started_at !== null,
                'consultation_started_at' => $appointment->consultation_started_at?->toIso8601String(),
                'consultation_completed' => $appointment->consultation_finished_at !== null,
                'consultation_completed_at' => $appointment->consultation_finished_at?->toIso8601String(),
            ] : null,
            'grooming_clearance_status' => $referral->grooming_clearance_status,
            'grooming_clearance_status_label' => GroomingClinicReferral::groomingClearanceLabel(
                $referral->grooming_clearance_status,
            ),
            'effective_clinic_date' => now()->toDateString(),
        ];
    }

    /** @return array{already_accepted: bool, referral: array<string, mixed>} */
    public function accept(string $publicId, User $staff): array
    {
        return DB::transaction(function () use ($publicId, $staff): array {
            $referral = GroomingClinicReferral::query()
                ->where('public_id', $publicId)
                ->lockForUpdate()
                ->first();

            if (! $referral) {
                throw new ClinicReferralAcceptanceException('Clinic referral not found.', 404);
            }

            if (
                $referral->status === GroomingClinicReferral::STATUS_ACCEPTED
                && $referral->clinic_appointment_id !== null
            ) {
                $existingAppointment = ClinicAppointment::query()
                    ->whereKey($referral->clinic_appointment_id)
                    ->where('pet_id', $referral->pet_id)
                    ->lockForUpdate()
                    ->first();

                if (! $existingAppointment) {
                    throw new ClinicReferralAcceptanceException(
                        'The accepted referral no longer matches its clinic appointment.',
                    );
                }

                $referral->load($this->responseRelations());

                return [
                    'already_accepted' => true,
                    'referral' => $this->present($referral),
                ];
            }

            $booking = Booking::query()
                ->whereKey($referral->booking_id)
                ->lockForUpdate()
                ->first();
            $bookingPet = BookingPet::query()
                ->whereKey($referral->booking_pet_id)
                ->where('booking_id', $referral->booking_id)
                ->where('pet_id', $referral->pet_id)
                ->lockForUpdate()
                ->first();
            $pet = Pet::query()
                ->whereKey($referral->pet_id)
                ->lockForUpdate()
                ->first();
            $concern = GroomingMedicalConcern::query()
                ->whereKey($referral->grooming_medical_concern_id)
                ->where('booking_id', $referral->booking_id)
                ->where('booking_pet_id', $referral->booking_pet_id)
                ->where('pet_id', $referral->pet_id)
                ->lockForUpdate()
                ->first();
            $response = $this->lockedConsentResponse($referral);

            $availability = $this->acceptanceAvailability(
                $referral,
                $booking,
                $bookingPet,
                $pet,
                $concern,
                $response,
                lockAppointments: true,
            );

            if (! $availability['can_accept']) {
                throw new ClinicReferralAcceptanceException(
                    $availability['blocked_reasons'][0]
                        ?? 'This clinic referral cannot be accepted.',
                );
            }

            $appointmentDate = now()->toDateString();
            $sequence = $this->clinicSequence->reserve($appointmentDate, true);
            $owner = $referral->owner_user_id_at_referral
                ? User::query()->whereKey($referral->owner_user_id_at_referral)->first()
                : null;
            $walkin = $owner ? null : $this->resolveUnregisteredWalkin($booking, $referral);

            $appointment = ClinicAppointment::create([
                'appointment_reference' => $sequence['appointment_reference'],
                'appointment_type' => 'walk_in',
                'status' => 'checked_in',
                'queue_number' => $sequence['queue_number'],
                'appointment_date' => $appointmentDate,
                'window_id' => null,
                'user_id' => $owner?->user_id,
                'walkin_id' => $walkin?->id,
                'pet_id' => $pet->pet_id,
                'chief_complaint' => $referral->customer_explanation,
                'total_amount' => 0,
                'paid' => false,
                'checked_in_at' => now(),
            ]);

            $referral->forceFill([
                'clinic_appointment_id' => $appointment->id,
                'status' => GroomingClinicReferral::STATUS_ACCEPTED,
                'accepted_by_user_id' => $staff->user_id,
                'accepted_by_name' => $this->displayName($staff, 'Clinic staff'),
                'accepted_at' => now(),
            ])->save();

            $concern->forceFill([
                'status' => GroomingMedicalConcern::STATUS_REFERRED_TO_CLINIC,
            ])->save();

            if ($owner) {
                CustomerNotification::firstOrCreate(
                    [
                        'grooming_clinic_referral_id' => $referral->id,
                        'type' => CustomerNotification::TYPE_GROOMING_CLINIC_REFERRAL_ACCEPTED,
                    ],
                    [
                        'user_id' => $owner->user_id,
                        'booking_id' => $booking->booking_id,
                        'message' => sprintf(
                            '%s\'s clinic referral was accepted as %s. Your pet has entered the clinic intake queue. Further veterinary procedures or charges may require separate approval.',
                            $pet->pet_name,
                            $appointment->appointment_reference,
                        ),
                        'is_read' => false,
                        'created_at' => now(),
                    ],
                );
            }

            $referral->load($this->responseRelations());

            return [
                'already_accepted' => false,
                'referral' => $this->present($referral),
            ];
        }, 3);
    }

    /**
     * @return array{can_accept: bool, blocked_reasons: array<int, string>}
     */
    private function acceptanceAvailability(
        GroomingClinicReferral $referral,
        ?Booking $booking,
        ?BookingPet $bookingPet,
        ?Pet $pet,
        ?GroomingMedicalConcern $concern,
        ?GroomingMedicalConcernResponse $response,
        bool $lockAppointments = false,
    ): array {
        $reasons = [];

        if ($referral->status !== GroomingClinicReferral::STATUS_PENDING_CLINIC_ACCEPTANCE) {
            $reasons[] = match ($referral->status) {
                GroomingClinicReferral::STATUS_PENDING_CONSENT => 'Clinic-referral consent is still pending.',
                GroomingClinicReferral::STATUS_ACCEPTED => 'This referral has already been accepted.',
                GroomingClinicReferral::STATUS_UNDER_CLINIC_REVIEW => 'This referral is already under clinic review.',
                default => 'This referral is closed and cannot be accepted.',
            };
        }

        if (! $booking || ! $bookingPet || ! $pet || ! $concern) {
            $reasons[] = 'The referral context no longer matches the grooming record.';

            return ['can_accept' => false, 'blocked_reasons' => array_values(array_unique($reasons))];
        }

        if (
            (int) $bookingPet->booking_id !== (int) $referral->booking_id
            || (int) $bookingPet->pet_id !== (int) $referral->pet_id
            || (int) $concern->booking_id !== (int) $referral->booking_id
            || (int) $concern->booking_pet_id !== (int) $referral->booking_pet_id
            || (int) $concern->pet_id !== (int) $referral->pet_id
        ) {
            $reasons[] = 'The referral context no longer matches the grooming record.';
        }

        if (in_array($concern->status, [
            GroomingMedicalConcern::STATUS_RESOLVED,
            GroomingMedicalConcern::STATUS_CANCELLED,
        ], true)) {
            $reasons[] = 'The related medical concern is already closed.';
        }

        if (
            in_array($booking->status, self::BLOCKED_BOOKING_STATUSES, true)
            || $booking->archived_at !== null
        ) {
            $reasons[] = 'The grooming booking is no longer active for clinic intake.';
        }

        if (
            $bookingPet->grooming_state === BookingPet::GROOMING_STATE_FINISHED
            || $bookingPet->grooming_end_time !== null
        ) {
            $reasons[] = 'Normally finished pets cannot be accepted through this referral queue.';
        }

        $appliedAction = $concern->applied_grooming_action;
        $hasAppliedAction = in_array($appliedAction, [
            GroomingMedicalConcern::ACTION_PAUSE_GROOMING,
            GroomingMedicalConcern::ACTION_STOP_GROOMING,
        ], true)
            && $concern->action_applied_at !== null
            && $concern->action_applied_by_user_id !== null;

        if (! $hasAppliedAction) {
            $reasons[] = 'Apply Pause Grooming or Stop Grooming to this exact concern first.';
        } elseif (
            ($appliedAction === GroomingMedicalConcern::ACTION_PAUSE_GROOMING
                && $bookingPet->grooming_state !== BookingPet::GROOMING_STATE_PAUSED)
            || ($appliedAction === GroomingMedicalConcern::ACTION_STOP_GROOMING
                && $bookingPet->grooming_state !== BookingPet::GROOMING_STATE_STOPPED)
        ) {
            $reasons[] = 'The pet grooming state no longer matches the applied concern action.';
        }

        $approved = $response?->decision
            === GroomingMedicalConcernResponse::DECISION_APPROVED;
        $overrideDocumented = filled($referral->emergency_without_consent_reason);

        if (
            $referral->consent_response_id !== null
            && $response === null
        ) {
            $reasons[] = 'The linked clinic-referral consent record is invalid.';
        } elseif ($referral->urgency === GroomingClinicReferral::URGENCY_ROUTINE && ! $approved) {
            $reasons[] = 'Approved clinic-referral consent is required for a Routine referral.';
        } elseif (
            $referral->urgency === GroomingClinicReferral::URGENCY_URGENT
            && ! $approved
            && ! $overrideDocumented
        ) {
            $reasons[] = 'Approved consent or a documented urgent intake-before-consent reason is required.';
        } elseif (
            $referral->urgency === GroomingClinicReferral::URGENCY_EMERGENCY
            && ! $overrideDocumented
        ) {
            $reasons[] = 'A documented emergency intake-before-consent reason is required.';
        }

        if (
            $referral->urgency === GroomingClinicReferral::URGENCY_ROUTINE
            && $this->clinicIsClosedOn(now()->toDateString())
        ) {
            $reasons[] = 'The clinic is closed or blocked for the effective clinic date.';
        }

        if ($referral->clinic_appointment_id !== null) {
            $reasons[] = 'This referral already has a clinic appointment.';
        }

        $appointmentQuery = ClinicAppointment::query()
            ->where('pet_id', $pet->pet_id)
            ->whereIn('status', self::ACTIVE_APPOINTMENT_STATUSES);
        if ($referral->clinic_appointment_id !== null) {
            $appointmentQuery->where('id', '!=', $referral->clinic_appointment_id);
        }
        if ($lockAppointments) {
            $appointmentQuery->lockForUpdate();
        }
        if ($appointmentQuery->exists()) {
            $reasons[] = 'This pet already has an active clinic appointment.';
        }

        return [
            'can_accept' => $reasons === [],
            'blocked_reasons' => array_values(array_unique($reasons)),
        ];
    }

    private function lockedConsentResponse(
        GroomingClinicReferral $referral,
    ): ?GroomingMedicalConcernResponse {
        if (! $referral->consent_response_id) {
            return null;
        }

        return GroomingMedicalConcernResponse::query()
            ->whereKey($referral->consent_response_id)
            ->where('concern_id', $referral->grooming_medical_concern_id)
            ->where('response_kind', GroomingMedicalConcernResponse::KIND_CLINIC_REFERRAL_CONSENT)
            ->lockForUpdate()
            ->first();
    }

    private function exactConsentResponse(
        GroomingClinicReferral $referral,
    ): ?GroomingMedicalConcernResponse {
        $response = $referral->consentResponse;

        return $response
            && (int) $response->concern_id === (int) $referral->grooming_medical_concern_id
            && $response->response_kind === GroomingMedicalConcernResponse::KIND_CLINIC_REFERRAL_CONSENT
                ? $response
                : null;
    }

    private function clinicIsClosedOn(string $date): bool
    {
        return ClinicClosure::query()
            ->where('is_active', true)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->where(function (Builder $query) use ($date): void {
                $query->where('type', 'blocked_date')
                    ->orWhere(function (Builder $stopToday) use ($date): void {
                        $stopToday
                            ->where('type', 'stop_today')
                            ->whereDate('start_date', $date);
                    });
            })
            ->exists();
    }

    private function resolveUnregisteredWalkin(
        Booking $booking,
        GroomingClinicReferral $referral,
    ): Walkin {
        if ($booking->walkin_id) {
            $walkin = Walkin::query()
                ->whereKey($booking->walkin_id)
                ->lockForUpdate()
                ->first();

            if ($walkin) {
                return $walkin;
            }
        }

        return Walkin::create([
            'fname' => $referral->owner_name_at_referral,
            'lname' => '',
            'mname' => null,
            'email' => null,
            'phone' => 'Not provided',
            'sedation_consent' => false,
            'terms_agreed' => false,
            'user_id' => null,
            'appointment_type' => 'clinic',
            'chief_complaint' => $referral->customer_explanation,
        ]);
    }

    /** @return array<int, string> */
    private function responseRelations(): array
    {
        return [
            'booking',
            'bookingPet',
            'pet',
            'groomingMedicalConcern',
            'consentResponse',
            'clinicAppointment',
            'notifications',
        ];
    }

    private function groomingStateLabel(?string $state): string
    {
        return match ($state) {
            BookingPet::GROOMING_STATE_NOT_STARTED => 'Not started',
            BookingPet::GROOMING_STATE_IN_PROGRESS => 'In progress',
            BookingPet::GROOMING_STATE_PAUSED => 'Paused',
            BookingPet::GROOMING_STATE_STOPPED => 'Stopped',
            BookingPet::GROOMING_STATE_FINISHED => 'Finished',
            default => 'Unknown',
        };
    }

    private function appointmentStatusLabel(?string $status): string
    {
        return match ($status) {
            'waiting_to_arrive' => 'Waiting to Arrive',
            'checked_in' => 'Checked In',
            'in_consultation' => 'In Consultation',
            'for_payment' => 'For Payment',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'no_show' => 'No Show',
            default => 'Unknown',
        };
    }

    private function displayName(User $user, string $fallback): string
    {
        $name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));

        return $name !== '' ? $name : $fallback;
    }
}
