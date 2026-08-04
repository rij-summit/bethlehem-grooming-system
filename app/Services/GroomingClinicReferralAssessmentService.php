<?php

namespace App\Services;

use App\Exceptions\ClinicReferralAssessmentException;
use App\Models\Booking;
use App\Models\BookingPet;
use App\Models\ClinicAppointment;
use App\Models\CustomerNotification;
use App\Models\GroomingClinicReferral;
use App\Models\GroomingMedicalConcern;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class GroomingClinicReferralAssessmentService
{
    private const ACTIVE_REFERRAL_STATUSES = [
        GroomingClinicReferral::STATUS_PENDING_CONSENT,
        GroomingClinicReferral::STATUS_PENDING_CLINIC_ACCEPTANCE,
        GroomingClinicReferral::STATUS_ACCEPTED,
        GroomingClinicReferral::STATUS_UNDER_CLINIC_REVIEW,
    ];

    /** @return array{appointment: ClinicAppointment, referral: ?GroomingClinicReferral, referral_linked: bool, already_synchronized: bool} */
    public function start(int $appointmentId, User $staff): array
    {
        return DB::transaction(function () use ($appointmentId, $staff): array {
            $appointment = ClinicAppointment::query()
                ->whereKey($appointmentId)
                ->lockForUpdate()
                ->first();

            if (! $appointment) {
                throw new ClinicReferralAssessmentException('Clinic appointment not found.', 404);
            }

            $referral = Schema::hasTable('grooming_clinic_referrals')
                ? GroomingClinicReferral::query()
                    ->where('clinic_appointment_id', $appointment->id)
                    ->lockForUpdate()
                    ->first()
                : null;

            if (! $referral) {
                if ($appointment->status !== 'checked_in') {
                    throw new ClinicReferralAssessmentException(
                        'Appointment must be checked in first.',
                        422,
                    );
                }

                $appointment->forceFill([
                    'status' => 'in_consultation',
                    'consultation_started_at' => now(),
                ])->save();

                return $this->result($appointment, null, false);
            }

            [$booking, $bookingPet, $pet, $concern] = $this->lockedContext(
                $appointment,
                $referral,
            );

            if ($this->isExactStartReplay($appointment, $referral, $concern)) {
                return $this->result($appointment, $referral, true);
            }

            if (
                $appointment->status !== 'checked_in'
                || $referral->status !== GroomingClinicReferral::STATUS_ACCEPTED
                || $concern->status !== GroomingMedicalConcern::STATUS_REFERRED_TO_CLINIC
            ) {
                throw new ClinicReferralAssessmentException(
                    'The clinic appointment and grooming referral are not ready to start together.',
                );
            }

            $this->assertExactAppliedStop($bookingPet, $concern);

            $startedAt = now();
            $staffName = $this->displayName($staff, 'Clinic staff');

            $appointment->forceFill([
                'status' => 'in_consultation',
                'consultation_started_at' => $startedAt,
            ])->save();
            $referral->forceFill([
                'status' => GroomingClinicReferral::STATUS_UNDER_CLINIC_REVIEW,
                'clinic_review_started_by_user_id' => $staff->user_id,
                'clinic_review_started_by_name' => $staffName,
                'clinic_review_started_at' => $startedAt,
            ])->save();
            $concern->forceFill([
                'status' => GroomingMedicalConcern::STATUS_UNDER_CLINIC_REVIEW,
            ])->save();

            $this->notifyOwner(
                $referral,
                $booking,
                $pet,
                CustomerNotification::TYPE_GROOMING_CLINIC_ASSESSMENT_STARTED,
                sprintf(
                    '%s\'s clinic assessment has started. Grooming for this pet has been stopped for this visit. Further diagnostics, medication, treatment, or charges may require separate approval.',
                    $pet->pet_name,
                ),
            );

            return $this->result($appointment, $referral, false);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{appointment: ClinicAppointment, referral: ?GroomingClinicReferral, referral_linked: bool, already_synchronized: bool, payment_readiness: ?array}
     */
    public function finish(int $appointmentId, User $staff, array $input): array
    {
        return DB::transaction(function () use ($appointmentId, $staff, $input): array {
            $appointment = ClinicAppointment::query()
                ->whereKey($appointmentId)
                ->lockForUpdate()
                ->first();

            if (! $appointment) {
                throw new ClinicReferralAssessmentException('Clinic appointment not found.', 404);
            }

            $referral = Schema::hasTable('grooming_clinic_referrals')
                ? GroomingClinicReferral::query()
                    ->where('clinic_appointment_id', $appointment->id)
                    ->lockForUpdate()
                    ->first()
                : null;

            if (! $referral) {
                if ($appointment->status !== 'in_consultation') {
                    throw new ClinicReferralAssessmentException(
                        'Appointment is not in consultation.',
                        422,
                    );
                }

                $appointment->forceFill([
                    'status' => 'for_payment',
                    'consultation_finished_at' => now(),
                ])->save();

                return [
                    ...$this->result($appointment, null, false),
                    'payment_readiness' => null,
                ];
            }

            $validated = $this->validateCompletionInput($input);
            [$booking, $bookingPet, $pet, $concern] = $this->lockedContext(
                $appointment,
                $referral,
            );

            if ($this->isExactFinishReplay($appointment, $referral, $concern)) {
                if (
                    $referral->internal_resolution_notes !== $validated['internal_resolution_notes']
                    || $referral->customer_resolution_summary !== $validated['customer_resolution_summary']
                ) {
                    throw new ClinicReferralAssessmentException(
                        'The clinic assessment was already completed with different permanent summaries.',
                    );
                }

                return [
                    ...$this->result($appointment, $referral, true),
                    'payment_readiness' => Schema::hasTable('booking_services')
                        ? app(GroomingPaymentReadinessService::class)->summarize($booking, true)
                        : null,
                ];
            }

            if (
                $appointment->status !== 'in_consultation'
                || $referral->status !== GroomingClinicReferral::STATUS_UNDER_CLINIC_REVIEW
                || $concern->status !== GroomingMedicalConcern::STATUS_UNDER_CLINIC_REVIEW
            ) {
                throw new ClinicReferralAssessmentException(
                    'The clinic appointment and grooming referral are not ready to complete together.',
                );
            }

            $this->assertExactAppliedStop($bookingPet, $concern);

            $completedAt = now();
            $staffName = $this->displayName($staff, 'Clinic staff');

            $appointment->forceFill([
                'status' => 'for_payment',
                'consultation_finished_at' => $completedAt,
            ])->save();
            $referral->forceFill([
                'status' => GroomingClinicReferral::STATUS_COMPLETED,
                'grooming_clearance_status' => GroomingClinicReferral::CLEARANCE_NOT_APPLICABLE,
                'resolved_by_user_id' => $staff->user_id,
                'resolved_by_name' => $staffName,
                'resolved_at' => $completedAt,
                'internal_resolution_notes' => $validated['internal_resolution_notes'],
                'customer_resolution_summary' => $validated['customer_resolution_summary'],
            ])->save();
            $concern->forceFill([
                'status' => GroomingMedicalConcern::STATUS_RESOLVED,
                'resolved_by_user_id' => $staff->user_id,
                'resolved_by_name' => $staffName,
                'resolved_at' => $completedAt,
                'internal_resolution_notes' => $this->appendAuditEntry(
                    $concern->internal_resolution_notes,
                    sprintf(
                        '[Clinic assessment completed | %s | %s] %s',
                        $completedAt->toIso8601String(),
                        $staffName,
                        $validated['internal_resolution_notes'],
                    ),
                ),
                'customer_resolution_summary' => $validated['customer_resolution_summary'],
            ])->save();

            $this->notifyOwner(
                $referral,
                $booking,
                $pet,
                CustomerNotification::TYPE_GROOMING_CLINIC_ASSESSMENT_COMPLETED,
                sprintf(
                    '%s\'s initial clinic assessment is complete. Grooming will not continue during this visit. Please review the remaining clinic payment, care, and pickup requirements.',
                    $pet->pet_name,
                ),
            );

            $paymentReadiness = Schema::hasTable('booking_services')
                ? app(GroomingPaymentReadinessService::class)->summarize($booking, true)
                : null;
            if (
                ($paymentReadiness['payment_ready'] ?? false)
                && ! (bool) $booking->paid
                && ! in_array($booking->status, ['cancelled', 'no_show', 'released', 'archived'], true)
                && $booking->archived_at === null
            ) {
                $booking->forceFill(['status' => 'for_payment'])->save();
            }

            return [
                ...$this->result($appointment, $referral, false),
                'payment_readiness' => $paymentReadiness,
            ];
        }, 3);
    }

    public function pickupBlockedReason(Booking $booking, bool $lockForUpdate = false): ?string
    {
        if (! Schema::hasTable('grooming_clinic_referrals')) {
            return null;
        }
        $query = GroomingClinicReferral::query()
            ->where('booking_id', $booking->booking_id);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $referrals = $query->get();
        $appointmentIds = $referrals->pluck('clinic_appointment_id')->filter()->values();
        $appointments = ClinicAppointment::query()->whereIn('id', $appointmentIds);
        if ($lockForUpdate) {
            $appointments->lockForUpdate();
        }
        $appointments = $appointments->get()->keyBy('id');
        $referrals->each(function (GroomingClinicReferral $referral) use ($appointments): void {
            $referral->setRelation(
                'clinicAppointment',
                $referral->clinic_appointment_id
                    ? $appointments->get($referral->clinic_appointment_id)
                    : null,
            );
        });
        $active = $referrals->first(fn (GroomingClinicReferral $referral) => in_array(
            $referral->status,
            self::ACTIVE_REFERRAL_STATUSES,
            true,
        ));
        if ($active) {
            return 'Physical pickup is unavailable while a linked clinic referral is active.';
        }

        $incomplete = $referrals->first(function (GroomingClinicReferral $referral): bool {
            if ($referral->status !== GroomingClinicReferral::STATUS_COMPLETED) {
                return false;
            }

            return ! $referral->clinicAppointment
                || $referral->clinicAppointment->status !== 'completed'
                || ! (bool) $referral->clinicAppointment->paid;
        });

        return $incomplete
            ? 'The grooming workflow is complete, but the linked clinic appointment must be paid and completed before pickup.'
            : null;
    }

    /** @return array{0: Booking, 1: BookingPet, 2: Pet, 3: GroomingMedicalConcern} */
    private function lockedContext(
        ClinicAppointment $appointment,
        GroomingClinicReferral $referral,
    ): array {
        $booking = Booking::query()->whereKey($referral->booking_id)->lockForUpdate()->first();
        $bookingPet = BookingPet::query()
            ->whereKey($referral->booking_pet_id)
            ->where('booking_id', $referral->booking_id)
            ->where('pet_id', $referral->pet_id)
            ->lockForUpdate()
            ->first();
        $pet = Pet::query()->whereKey($referral->pet_id)->lockForUpdate()->first();
        $concern = GroomingMedicalConcern::query()
            ->whereKey($referral->grooming_medical_concern_id)
            ->where('booking_id', $referral->booking_id)
            ->where('booking_pet_id', $referral->booking_pet_id)
            ->where('pet_id', $referral->pet_id)
            ->lockForUpdate()
            ->first();

        if (
            ! $booking
            || ! $bookingPet
            || ! $pet
            || ! $concern
            || (int) $appointment->pet_id !== (int) $referral->pet_id
            || (int) $appointment->id !== (int) $referral->clinic_appointment_id
        ) {
            throw new ClinicReferralAssessmentException(
                'The clinic appointment and grooming referral context no longer match.',
            );
        }

        return [$booking, $bookingPet, $pet, $concern];
    }

    private function assertExactAppliedStop(
        BookingPet $bookingPet,
        GroomingMedicalConcern $concern,
    ): void {
        if ($bookingPet->grooming_state !== BookingPet::GROOMING_STATE_STOPPED) {
            throw new ClinicReferralAssessmentException(
                'Stop Grooming must be applied to this pet before the clinic consultation can begin.',
            );
        }

        if (
            $bookingPet->grooming_end_time !== null
            || $concern->applied_grooming_action !== GroomingMedicalConcern::ACTION_STOP_GROOMING
            || $concern->action_applied_at === null
            || $concern->action_applied_by_user_id === null
        ) {
            throw new ClinicReferralAssessmentException(
                'Stop Grooming must be applied to this exact concern before clinic assessment can proceed.',
            );
        }
    }

    /** @param array<string, mixed> $input */
    private function validateCompletionInput(array $input): array
    {
        foreach (['internal_resolution_notes', 'customer_resolution_summary'] as $field) {
            if (array_key_exists($field, $input) && is_string($input[$field])) {
                $input[$field] = trim($input[$field]);
            }
        }

        return Validator::make($input, [
            'internal_resolution_notes' => ['required', 'string', 'max:5000'],
            'customer_resolution_summary' => ['required', 'string', 'max:2000'],
            'grooming_state' => ['prohibited'],
            'referral_status' => ['prohibited'],
            'concern_status' => ['prohibited'],
            'grooming_clearance_status' => ['prohibited'],
            'resolved_by_user_id' => ['prohibited'],
            'resolved_by_name' => ['prohibited'],
            'resolved_at' => ['prohibited'],
            'payment_status' => ['prohibited'],
        ])->validate();
    }

    private function isExactStartReplay(
        ClinicAppointment $appointment,
        GroomingClinicReferral $referral,
        GroomingMedicalConcern $concern,
    ): bool {
        return $appointment->status === 'in_consultation'
            && $referral->status === GroomingClinicReferral::STATUS_UNDER_CLINIC_REVIEW
            && $concern->status === GroomingMedicalConcern::STATUS_UNDER_CLINIC_REVIEW
            && $appointment->consultation_started_at !== null
            && $referral->clinic_review_started_by_user_id !== null
            && filled($referral->clinic_review_started_by_name)
            && $referral->clinic_review_started_at !== null;
    }

    private function isExactFinishReplay(
        ClinicAppointment $appointment,
        GroomingClinicReferral $referral,
        GroomingMedicalConcern $concern,
    ): bool {
        return $appointment->status === 'for_payment'
            && $referral->status === GroomingClinicReferral::STATUS_COMPLETED
            && $referral->grooming_clearance_status === GroomingClinicReferral::CLEARANCE_NOT_APPLICABLE
            && $concern->status === GroomingMedicalConcern::STATUS_RESOLVED
            && $appointment->consultation_finished_at !== null
            && $referral->resolved_at !== null;
    }

    private function notifyOwner(
        GroomingClinicReferral $referral,
        Booking $booking,
        Pet $pet,
        string $type,
        string $message,
    ): void {
        if (! $referral->owner_user_id_at_referral) {
            return;
        }

        $ownerExists = User::query()
            ->whereKey($referral->owner_user_id_at_referral)
            ->where('role', 'customer')
            ->exists();
        if (! $ownerExists) {
            return;
        }

        CustomerNotification::firstOrCreate(
            [
                'grooming_clinic_referral_id' => $referral->id,
                'type' => $type,
            ],
            [
                'user_id' => $referral->owner_user_id_at_referral,
                'booking_id' => $booking->booking_id,
                'message' => $message,
                'is_read' => false,
                'created_at' => now(),
            ],
        );
    }

    /** @return array{appointment: ClinicAppointment, referral: ?GroomingClinicReferral, referral_linked: bool, already_synchronized: bool} */
    private function result(
        ClinicAppointment $appointment,
        ?GroomingClinicReferral $referral,
        bool $alreadySynchronized,
    ): array {
        return [
            'appointment' => $appointment,
            'referral' => $referral,
            'referral_linked' => $referral !== null,
            'already_synchronized' => $alreadySynchronized,
        ];
    }

    private function appendAuditEntry(?string $existing, string $entry): string
    {
        $existing = trim((string) $existing);

        return $existing === '' ? $entry : $existing."\n".$entry;
    }

    private function displayName(User $user, string $fallback): string
    {
        $name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));

        return $name !== '' ? $name : $fallback;
    }
}
