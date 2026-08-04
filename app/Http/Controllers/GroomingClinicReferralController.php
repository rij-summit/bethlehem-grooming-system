<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingPet;
use App\Models\CustomerNotification;
use App\Models\GroomingClinicReferral;
use App\Models\GroomingMedicalConcern;
use App\Models\GroomingMedicalConcernResponse;
use App\Models\Pet;
use App\Models\User;
use App\Services\GroomingClinicReferralStatement;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GroomingClinicReferralController extends Controller
{
    private const BLOCKED_BOOKING_STATUSES = [
        'released',
        'archived',
        'cancelled',
        'no_show',
    ];

    private const ACTIVE_REFERRAL_STATUSES = [
        GroomingClinicReferral::STATUS_PENDING_CONSENT,
        GroomingClinicReferral::STATUS_PENDING_CLINIC_ACCEPTANCE,
        GroomingClinicReferral::STATUS_ACCEPTED,
        GroomingClinicReferral::STATUS_UNDER_CLINIC_REVIEW,
    ];

    private const TERMINAL_REFERRAL_STATUSES = [
        GroomingClinicReferral::STATUS_COMPLETED,
        GroomingClinicReferral::STATUS_CANCELLED,
    ];

    public function __construct(
        private readonly GroomingClinicReferralStatement $statements,
    ) {}

    public function store(
        Request $request,
        int $bookingId,
        int $bookingPetId,
        int $concernId,
    ): JsonResponse {
        $this->trimRequestStrings($request, [
            'urgency',
            'referral_reason',
            'customer_explanation',
            'request_token',
            'emergency_without_consent_reason',
        ]);

        $validated = $request->validate([
            'urgency' => [
                'required',
                'string',
                Rule::in(GroomingClinicReferral::URGENCIES),
            ],
            'referral_reason' => ['required', 'string', 'max:5000'],
            'customer_explanation' => ['required', 'string', 'max:2000'],
            'request_token' => ['required', 'uuid'],
            'emergency_without_consent_reason' => [
                'nullable',
                'string',
                'max:5000',
                'required_if:urgency,'.GroomingClinicReferral::URGENCY_EMERGENCY,
                'prohibited_if:urgency,'.GroomingClinicReferral::URGENCY_ROUTINE,
            ],
            ...$this->serverControlledReferralRules(),
        ]);

        $emergencyReason = $this->nullableTrimmed(
            $validated['emergency_without_consent_reason'] ?? null,
        );

        try {
            return DB::transaction(function () use (
                $request,
                $bookingId,
                $bookingPetId,
                $concernId,
                $validated,
                $emergencyReason,
            ): JsonResponse {
                [$booking, $bookingPet, $pet, $concern] = $this->lockStaffContext(
                    $bookingId,
                    $bookingPetId,
                    $concernId,
                );

                $tokenReferral = GroomingClinicReferral::query()
                    ->where('request_token', $validated['request_token'])
                    ->lockForUpdate()
                    ->first();

                if ($tokenReferral) {
                    return $this->requestTokenReplay(
                        $tokenReferral,
                        $bookingId,
                        $bookingPetId,
                        $concernId,
                        $validated,
                        $emergencyReason,
                    );
                }

                $concernReferral = GroomingClinicReferral::query()
                    ->where('grooming_medical_concern_id', $concern->id)
                    ->lockForUpdate()
                    ->first();

                if ($concernReferral) {
                    return $this->existingReferralResponse($concernReferral);
                }

                $activeReferral = GroomingClinicReferral::query()
                    ->where('booking_pet_id', $bookingPet->booking_pet_id)
                    ->whereIn('status', self::ACTIVE_REFERRAL_STATUSES)
                    ->lockForUpdate()
                    ->first();

                if ($activeReferral) {
                    return $this->conflict(
                        'This pet already has an active clinic referral.',
                    );
                }

                if ($error = $this->creationEligibilityError(
                    $booking,
                    $bookingPet,
                    $concern,
                )) {
                    return $this->unprocessable($error);
                }

                [$ownerUser, $ownerName] = $this->resolveOwnerSnapshot(
                    $booking,
                    $pet,
                );
                $initialStatus = $this->initialStatus(
                    $validated['urgency'],
                    $emergencyReason,
                );
                $staff = $request->user();
                $referral = GroomingClinicReferral::create([
                    'request_token' => $validated['request_token'],
                    'grooming_medical_concern_id' => $concern->id,
                    'booking_id' => $booking->booking_id,
                    'booking_pet_id' => $bookingPet->booking_pet_id,
                    'pet_id' => $pet->pet_id,
                    'status' => $initialStatus,
                    'urgency' => $validated['urgency'],
                    'referral_reason' => $validated['referral_reason'],
                    'customer_explanation' => $validated['customer_explanation'],
                    'consent_required' => true,
                    'owner_user_id_at_referral' => $ownerUser?->user_id,
                    'owner_name_at_referral' => $ownerName,
                    'referred_by_user_id' => $staff->user_id,
                    'referred_by_name' => $this->displayName($staff, 'Staff'),
                    'referred_at' => now(),
                    'emergency_without_consent_reason' => $emergencyReason,
                    'grooming_clearance_status' => $bookingPet->grooming_state
                        === BookingPet::GROOMING_STATE_STOPPED
                            ? GroomingClinicReferral::CLEARANCE_NOT_APPLICABLE
                            : GroomingClinicReferral::CLEARANCE_PENDING,
                ]);

                $notification = null;

                if ($ownerUser) {
                    $notification = CustomerNotification::create([
                        'user_id' => $ownerUser->user_id,
                        'booking_id' => $booking->booking_id,
                        'grooming_clinic_referral_id' => $referral->id,
                        'type' => CustomerNotification::TYPE_GROOMING_CLINIC_REFERRAL_REQUESTED,
                        'message' => $this->notificationMessage(
                            $pet,
                            $referral->urgency,
                        ),
                        'is_read' => false,
                        'created_at' => now(),
                    ]);

                    $referral->forceFill([
                        'customer_notified_at' => now(),
                    ])->save();
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Clinic referral request recorded.',
                    'already_exists' => false,
                    'referral' => $this->staffReferral($referral->fresh(), $booking, $bookingPet, $pet, $concern),
                    'notification' => $notification
                        ? $this->notificationSummary($notification, $referral, $pet)
                        : null,
                ], 201);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->recoverReferralCreateConflict(
                $bookingId,
                $bookingPetId,
                $concernId,
                $validated,
                $emergencyReason,
            );
        }
    }

    public function staffShow(
        int $bookingId,
        int $bookingPetId,
        int $concernId,
    ): JsonResponse {
        [$booking, $bookingPet, $pet, $concern] = $this->findStaffContext(
            $bookingId,
            $bookingPetId,
            $concernId,
        );
        $referral = GroomingClinicReferral::query()
            ->where('grooming_medical_concern_id', $concern->id)
            ->first();

        if (! $referral) {
            $blockedReason = $this->creationEligibilityError(
                $booking,
                $bookingPet,
                $concern,
            );

            return response()->json([
                'success' => true,
                'exists' => false,
                'context' => $this->staffContext($booking, $bookingPet, $pet, $concern),
                'can_create' => $blockedReason === null,
                'blocked_reason' => $blockedReason,
                'financial_correction_review_required' => (bool) $booking->paid,
                'referral' => null,
            ]);
        }

        return response()->json([
            'success' => true,
            'exists' => true,
            'referral' => $this->staffReferral($referral, $booking, $bookingPet, $pet, $concern),
        ]);
    }

    public function customerShow(
        Request $request,
        int $petId,
        string $publicId,
    ): JsonResponse {
        $referral = $this->findCustomerReferral($request, $petId, $publicId);

        if (! $referral) {
            return $this->customerNotFound();
        }

        return response()->json([
            'success' => true,
            'referral' => $this->customerReferral($referral),
        ]);
    }

    public function customerConsent(
        Request $request,
        int $petId,
        string $publicId,
    ): JsonResponse {
        $this->trimRequestStrings($request, ['decision', 'signature_name']);
        $validated = $request->validate([
            'decision' => [
                'required',
                'string',
                Rule::in([
                    GroomingMedicalConcernResponse::DECISION_APPROVED,
                    GroomingMedicalConcernResponse::DECISION_DECLINED,
                ]),
            ],
            'signature_name' => ['required', 'string', 'max:200'],
            ...$this->serverControlledConsentRules(),
        ]);

        return $this->recordConsent(
            $request,
            $validated['decision'],
            $validated['signature_name'],
            null,
            $petId,
            $publicId,
        );
    }

    public function inPersonConsent(
        Request $request,
        int $bookingId,
        int $bookingPetId,
        int $concernId,
    ): JsonResponse {
        $this->trimRequestStrings($request, [
            'decision',
            'decision_maker_name',
            'signature_name',
        ]);
        $validated = $request->validate([
            'decision' => [
                'required',
                'string',
                Rule::in([
                    GroomingMedicalConcernResponse::DECISION_APPROVED,
                    GroomingMedicalConcernResponse::DECISION_DECLINED,
                ]),
            ],
            'decision_maker_name' => ['required', 'string', 'max:200'],
            'signature_name' => ['required', 'string', 'max:200'],
            ...$this->serverControlledConsentRules(),
        ]);

        return $this->recordConsent(
            $request,
            $validated['decision'],
            $validated['signature_name'],
            $validated['decision_maker_name'],
            null,
            null,
            $bookingId,
            $bookingPetId,
            $concernId,
        );
    }

    private function recordConsent(
        Request $request,
        string $decision,
        string $signatureName,
        ?string $decisionMakerName,
        ?int $petId = null,
        ?string $publicId = null,
        ?int $bookingId = null,
        ?int $bookingPetId = null,
        ?int $concernId = null,
    ): JsonResponse {
        try {
            return DB::transaction(function () use (
                $request,
                $decision,
                $signatureName,
                $decisionMakerName,
                $petId,
                $publicId,
                $bookingId,
                $bookingPetId,
                $concernId,
            ): JsonResponse {
                $portal = $publicId !== null;

                if ($portal) {
                    $referral = GroomingClinicReferral::query()
                        ->where('public_id', $publicId)
                        ->where('pet_id', $petId)
                        ->where('owner_user_id_at_referral', $request->user()->user_id)
                        ->lockForUpdate()
                        ->first();

                    if (! $referral || $request->user()->role !== 'customer') {
                        return $this->customerNotFound();
                    }
                } else {
                    [$booking, $bookingPet, $pet, $concern] = $this->lockStaffContext(
                        $bookingId,
                        $bookingPetId,
                        $concernId,
                    );
                    $referral = GroomingClinicReferral::query()
                        ->where('grooming_medical_concern_id', $concern->id)
                        ->where('booking_id', $booking->booking_id)
                        ->where('booking_pet_id', $bookingPet->booking_pet_id)
                        ->where('pet_id', $pet->pet_id)
                        ->lockForUpdate()
                        ->first();

                    if (! $referral) {
                        return $this->referralNotFound();
                    }

                    if ($referral->owner_user_id_at_referral !== null) {
                        return $this->conflict(
                            'Registered owners must submit clinic-referral consent through their own portal account.',
                        );
                    }
                }

                $pet = Pet::query()->whereKey($referral->pet_id)->lockForUpdate()->first();
                $concern = GroomingMedicalConcern::query()
                    ->whereKey($referral->grooming_medical_concern_id)
                    ->where('booking_id', $referral->booking_id)
                    ->where('booking_pet_id', $referral->booking_pet_id)
                    ->where('pet_id', $referral->pet_id)
                    ->lockForUpdate()
                    ->first();

                if (! $pet || ! $concern) {
                    return $portal ? $this->customerNotFound() : $this->referralNotFound();
                }

                $channel = $portal
                    ? GroomingMedicalConcernResponse::CHANNEL_PORTAL
                    : GroomingMedicalConcernResponse::CHANNEL_IN_PERSON_STAFF_CAPTURED;
                $respondentUserId = $portal ? $request->user()->user_id : null;
                $respondentName = $portal
                    ? $this->displayName($request->user(), 'Customer')
                    : $decisionMakerName;
                $existing = GroomingMedicalConcernResponse::query()
                    ->where('concern_id', $concern->id)
                    ->where('response_kind', GroomingMedicalConcernResponse::KIND_CLINIC_REFERRAL_CONSENT)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $this->consentReplay(
                        $referral,
                        $existing,
                        $decision,
                        $signatureName,
                        $channel,
                        $respondentUserId,
                        $respondentName,
                        $portal ? null : $request->user()->user_id,
                        $portal,
                    );
                }

                if ($referral->consent_response_id !== null) {
                    return $this->conflict(
                        'Clinic-referral consent has already been recorded and cannot be changed.',
                    );
                }

                if (in_array($referral->status, self::TERMINAL_REFERRAL_STATUSES, true)) {
                    return $this->conflict(
                        'This clinic referral is closed and cannot receive consent.',
                    );
                }

                if (! in_array($referral->status, [
                    GroomingClinicReferral::STATUS_PENDING_CONSENT,
                    GroomingClinicReferral::STATUS_PENDING_CLINIC_ACCEPTANCE,
                ], true)) {
                    return $this->conflict(
                        'This clinic referral is not awaiting a consent decision.',
                    );
                }

                $response = GroomingMedicalConcernResponse::create([
                    'concern_id' => $concern->id,
                    'responded_by_user_id' => $respondentUserId,
                    'responded_by_name' => $respondentName,
                    'response_channel' => $channel,
                    'captured_by_user_id' => $portal ? null : $request->user()->user_id,
                    'captured_by_name' => $portal
                        ? null
                        : $this->displayName($request->user(), 'Staff'),
                    'response_kind' => GroomingMedicalConcernResponse::KIND_CLINIC_REFERRAL_CONSENT,
                    'decision' => $decision,
                    'statement_text' => $this->statements->consent($pet),
                    'statement_version' => GroomingClinicReferralStatement::CONSENT_VERSION,
                    'signature_name' => $signatureName,
                    'responded_at' => now(),
                ]);

                $this->applyConsentTransition($referral, $response);

                return response()->json([
                    'success' => true,
                    'message' => 'Clinic-referral consent decision recorded.',
                    'already_recorded' => false,
                    'response' => $this->safeConsentResponse($response, ! $portal),
                    'referral' => $portal
                        ? $this->customerReferral($referral->fresh())
                        : $this->staffReferralFromModel($referral->fresh()),
                ], 201);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->recoverConsentConflict(
                $request,
                $decision,
                $signatureName,
                $decisionMakerName,
                $petId,
                $publicId,
                $bookingId,
                $bookingPetId,
                $concernId,
            );
        }
    }

    private function applyConsentTransition(
        GroomingClinicReferral $referral,
        GroomingMedicalConcernResponse $response,
    ): void {
        $changes = ['consent_response_id' => $response->id];

        if ($response->decision === GroomingMedicalConcernResponse::DECISION_APPROVED) {
            $changes['status'] = GroomingClinicReferral::STATUS_PENDING_CLINIC_ACCEPTANCE;
        } elseif (
            in_array($referral->urgency, [
                GroomingClinicReferral::URGENCY_URGENT,
                GroomingClinicReferral::URGENCY_EMERGENCY,
            ], true)
            && filled($referral->emergency_without_consent_reason)
            && $referral->status === GroomingClinicReferral::STATUS_PENDING_CLINIC_ACCEPTANCE
        ) {
            $changes['status'] = GroomingClinicReferral::STATUS_PENDING_CLINIC_ACCEPTANCE;
        } else {
            $changes += [
                'status' => GroomingClinicReferral::STATUS_CANCELLED,
                'cancelled_by_user_id' => null,
                'cancelled_by_name' => $response->responded_by_name,
                'cancelled_at' => now(),
                'cancellation_reason' => 'Customer declined clinic-referral consent.',
                'customer_cancellation_summary' => 'The clinic referral was cancelled because consent was declined.',
            ];
        }

        $referral->forceFill($changes)->save();
    }

    private function lockStaffContext(
        int $bookingId,
        int $bookingPetId,
        int $concernId,
    ): array {
        $booking = Booking::query()->whereKey($bookingId)->lockForUpdate()->first();
        $bookingPet = BookingPet::query()
            ->whereKey($bookingPetId)
            ->where('booking_id', $bookingId)
            ->lockForUpdate()
            ->first();
        $pet = $bookingPet?->pet_id
            ? Pet::query()->whereKey($bookingPet->pet_id)->lockForUpdate()->first()
            : null;
        $concern = $pet
            ? GroomingMedicalConcern::query()
                ->whereKey($concernId)
                ->where('booking_id', $bookingId)
                ->where('booking_pet_id', $bookingPetId)
                ->where('pet_id', $pet->pet_id)
                ->lockForUpdate()
                ->first()
            : null;

        if (! $booking || ! $bookingPet || ! $pet || ! $concern) {
            $this->throwContextNotFound();
        }

        return [$booking, $bookingPet, $pet, $concern];
    }

    private function findStaffContext(
        int $bookingId,
        int $bookingPetId,
        int $concernId,
    ): array {
        $booking = Booking::query()->whereKey($bookingId)->first();
        $bookingPet = BookingPet::query()
            ->whereKey($bookingPetId)
            ->where('booking_id', $bookingId)
            ->first();
        $pet = $bookingPet?->pet_id ? Pet::query()->find($bookingPet->pet_id) : null;
        $concern = $pet
            ? GroomingMedicalConcern::query()
                ->whereKey($concernId)
                ->where('booking_id', $bookingId)
                ->where('booking_pet_id', $bookingPetId)
                ->where('pet_id', $pet->pet_id)
                ->first()
            : null;

        if (! $booking || ! $bookingPet || ! $pet || ! $concern) {
            $this->throwContextNotFound();
        }

        return [$booking, $bookingPet, $pet, $concern];
    }

    private function creationEligibilityError(
        Booking $booking,
        BookingPet $bookingPet,
        GroomingMedicalConcern $concern,
    ): ?string {
        if (
            $booking->archived_at !== null
            || in_array($booking->status, self::BLOCKED_BOOKING_STATUSES, true)
        ) {
            return 'A clinic referral cannot be requested for this closed grooming booking.';
        }

        if (in_array($concern->status, [
            GroomingMedicalConcern::STATUS_RESOLVED,
            GroomingMedicalConcern::STATUS_CANCELLED,
        ], true)) {
            return 'A clinic referral cannot be requested for a closed medical concern.';
        }

        if (
            $bookingPet->grooming_state === BookingPet::GROOMING_STATE_FINISHED
            || $bookingPet->grooming_end_time !== null
        ) {
            return 'A normally finished pet must use the ordinary clinic intake workflow.';
        }

        if (
            $bookingPet->grooming_state === BookingPet::GROOMING_STATE_NOT_STARTED
            && ! (
                $concern->applied_grooming_action
                    === GroomingMedicalConcern::ACTION_STOP_GROOMING
                && $concern->action_applied_at !== null
            )
        ) {
            return 'A not-started pet requires Stop Grooming to be applied to this concern before referral.';
        }

        if (! in_array($bookingPet->grooming_state, [
            BookingPet::GROOMING_STATE_NOT_STARTED,
            BookingPet::GROOMING_STATE_IN_PROGRESS,
            BookingPet::GROOMING_STATE_PAUSED,
            BookingPet::GROOMING_STATE_STOPPED,
        ], true)) {
            return 'The selected pet is not eligible for the grooming clinic-referral workflow.';
        }

        return null;
    }

    private function resolveOwnerSnapshot(Booking $booking, Pet $pet): array
    {
        $userId = $booking->user_id;
        $walkin = null;

        if (! $userId && $booking->walkin_id) {
            $walkin = DB::table('walkins')
                ->where('id', $booking->walkin_id)
                ->lockForUpdate()
                ->first();
            $userId = $walkin?->user_id;
        }

        if (! $userId && ! $booking->walkin_id) {
            $userId = $pet->user_id;
        }

        $owner = $userId
            ? User::query()
                ->whereKey($userId)
                ->where('role', 'customer')
                ->lockForUpdate()
                ->first()
            : null;

        if ($owner) {
            return [$owner, $this->displayName($owner, 'Customer')];
        }

        if (! $walkin && $booking->walkin_id) {
            $walkin = DB::table('walkins')->where('id', $booking->walkin_id)->first();
        }

        $walkinName = $walkin
            ? trim(implode(' ', array_filter([
                $walkin->fname,
                $walkin->mname,
                $walkin->lname,
            ])))
            : '';

        return [null, $walkinName !== '' ? $walkinName : 'Unregistered walk-in'];
    }

    private function initialStatus(string $urgency, ?string $emergencyReason): string
    {
        return $urgency === GroomingClinicReferral::URGENCY_EMERGENCY
            || ($urgency === GroomingClinicReferral::URGENCY_URGENT && $emergencyReason !== null)
                ? GroomingClinicReferral::STATUS_PENDING_CLINIC_ACCEPTANCE
                : GroomingClinicReferral::STATUS_PENDING_CONSENT;
    }

    private function requestTokenReplay(
        GroomingClinicReferral $referral,
        int $bookingId,
        int $bookingPetId,
        int $concernId,
        array $validated,
        ?string $emergencyReason,
    ): JsonResponse {
        $same = (int) $referral->booking_id === $bookingId
            && (int) $referral->booking_pet_id === $bookingPetId
            && (int) $referral->grooming_medical_concern_id === $concernId
            && $referral->urgency === $validated['urgency']
            && $referral->referral_reason === $validated['referral_reason']
            && $referral->customer_explanation === $validated['customer_explanation']
            && $this->nullableTrimmed($referral->emergency_without_consent_reason)
                === $emergencyReason;

        return $same
            ? $this->existingReferralResponse($referral)
            : $this->conflict(
                'This request token is already associated with different clinic-referral data.',
            );
    }

    private function existingReferralResponse(GroomingClinicReferral $referral): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'This clinic referral request was already recorded.',
            'already_exists' => true,
            'referral' => $this->staffReferralFromModel($referral),
            'notification' => null,
        ]);
    }

    private function recoverReferralCreateConflict(
        int $bookingId,
        int $bookingPetId,
        int $concernId,
        array $validated,
        ?string $emergencyReason,
    ): JsonResponse {
        $byToken = GroomingClinicReferral::query()
            ->where('request_token', $validated['request_token'])
            ->first();

        if ($byToken) {
            return $this->requestTokenReplay(
                $byToken,
                $bookingId,
                $bookingPetId,
                $concernId,
                $validated,
                $emergencyReason,
            );
        }

        $byConcern = GroomingClinicReferral::query()
            ->where('grooming_medical_concern_id', $concernId)
            ->where('booking_id', $bookingId)
            ->where('booking_pet_id', $bookingPetId)
            ->first();

        if ($byConcern) {
            return $this->existingReferralResponse($byConcern);
        }

        return $this->conflict(
            'This pet already has an active clinic referral.',
        );
    }

    private function consentReplay(
        GroomingClinicReferral $referral,
        GroomingMedicalConcernResponse $response,
        string $decision,
        string $signatureName,
        string $channel,
        ?int $respondentUserId,
        string $respondentName,
        ?int $capturedByUserId,
        bool $portal,
    ): JsonResponse {
        $same = (int) $response->concern_id === (int) $referral->grooming_medical_concern_id
            && $response->response_kind === GroomingMedicalConcernResponse::KIND_CLINIC_REFERRAL_CONSENT
            && $response->decision === $decision
            && $response->signature_name === $signatureName
            && $response->response_channel === $channel
            && ($response->responded_by_user_id === null
                ? $respondentUserId === null
                : (int) $response->responded_by_user_id === $respondentUserId)
            && $response->responded_by_name === $respondentName
            && ($response->captured_by_user_id === null
                ? $capturedByUserId === null
                : (int) $response->captured_by_user_id === $capturedByUserId)
            && (int) $referral->consent_response_id === (int) $response->id;

        if (! $same) {
            return $this->conflict(
                'Clinic-referral consent has already been recorded and cannot be changed.',
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'This clinic-referral consent decision was already recorded.',
            'already_recorded' => true,
            'response' => $this->safeConsentResponse($response, ! $portal),
            'referral' => $portal
                ? $this->customerReferral($referral)
                : $this->staffReferralFromModel($referral),
        ]);
    }

    private function recoverConsentConflict(
        Request $request,
        string $decision,
        string $signatureName,
        ?string $decisionMakerName,
        ?int $petId,
        ?string $publicId,
        ?int $bookingId,
        ?int $bookingPetId,
        ?int $concernId,
    ): JsonResponse {
        $portal = $publicId !== null;
        $referral = $portal
            ? GroomingClinicReferral::query()
                ->where('public_id', $publicId)
                ->where('pet_id', $petId)
                ->where('owner_user_id_at_referral', $request->user()->user_id)
                ->first()
            : GroomingClinicReferral::query()
                ->where('booking_id', $bookingId)
                ->where('booking_pet_id', $bookingPetId)
                ->where('grooming_medical_concern_id', $concernId)
                ->first();

        if (! $referral) {
            return $portal ? $this->customerNotFound() : $this->referralNotFound();
        }

        $response = GroomingMedicalConcernResponse::query()
            ->where('concern_id', $referral->grooming_medical_concern_id)
            ->where('response_kind', GroomingMedicalConcernResponse::KIND_CLINIC_REFERRAL_CONSENT)
            ->first();

        if (! $response) {
            return $this->conflict('Clinic-referral consent could not be recorded safely.');
        }

        return $this->consentReplay(
            $referral,
            $response,
            $decision,
            $signatureName,
            $portal
                ? GroomingMedicalConcernResponse::CHANNEL_PORTAL
                : GroomingMedicalConcernResponse::CHANNEL_IN_PERSON_STAFF_CAPTURED,
            $portal ? $request->user()->user_id : null,
            $portal
                ? $this->displayName($request->user(), 'Customer')
                : (string) $decisionMakerName,
            $portal ? null : $request->user()->user_id,
            $portal,
        );
    }

    private function findCustomerReferral(
        Request $request,
        int $petId,
        string $publicId,
    ): ?GroomingClinicReferral {
        if ($request->user()->role !== 'customer') {
            return null;
        }

        return GroomingClinicReferral::query()
            ->where('public_id', $publicId)
            ->where('pet_id', $petId)
            ->where('owner_user_id_at_referral', $request->user()->user_id)
            ->first();
    }

    private function staffReferralFromModel(GroomingClinicReferral $referral): array
    {
        return $this->staffReferral(
            $referral,
            $referral->booking()->firstOrFail(),
            $referral->bookingPet()->firstOrFail(),
            $referral->pet()->firstOrFail(),
            $referral->groomingMedicalConcern()->firstOrFail(),
        );
    }

    private function staffReferral(
        GroomingClinicReferral $referral,
        Booking $booking,
        BookingPet $bookingPet,
        Pet $pet,
        GroomingMedicalConcern $concern,
    ): array {
        $response = $this->referralConsentResponse($referral);
        [$acceptanceEligible, $acceptanceBlockedReason] = $this->acceptanceAvailability(
            $referral,
            $concern,
        );

        return [
            'public_id' => $referral->public_id,
            'booking_reference' => $booking->booking_reference,
            'booking_id' => $booking->booking_id,
            'booking_pet_id' => $bookingPet->booking_pet_id,
            'pet_id' => $pet->pet_id,
            'pet_name' => $pet->pet_name,
            'pet_species' => $pet->species,
            'concern_public_id' => $concern->public_id,
            'status' => $referral->status,
            'status_label' => GroomingClinicReferral::statusLabel($referral->status),
            'urgency' => $referral->urgency,
            'urgency_label' => GroomingClinicReferral::urgencyLabel($referral->urgency),
            'referral_reason' => $referral->referral_reason,
            'customer_explanation' => $referral->customer_explanation,
            'consent_required' => (bool) $referral->consent_required,
            'consent_status' => $response ? 'recorded' : 'pending',
            'consent_decision' => $response?->decision,
            'consent_response_channel' => $response?->response_channel,
            'consent_response_channel_label' => $response
                ? GroomingMedicalConcernResponse::channelLabel($response->response_channel)
                : null,
            'consent_responded_by_name' => $response?->responded_by_name,
            'consent_captured_by_name' => $response?->captured_by_name,
            'consent_responded_at' => $response?->responded_at?->toIso8601String(),
            'consent_statement_version' => $response?->statement_version,
            'owner_account_linked' => $referral->owner_user_id_at_referral !== null,
            'owner_name_at_referral' => $referral->owner_name_at_referral,
            'referred_at' => $referral->referred_at?->toIso8601String(),
            'referred_by_name' => $referral->referred_by_name,
            'customer_notified_at' => $referral->customer_notified_at?->toIso8601String(),
            'emergency_without_consent' => filled($referral->emergency_without_consent_reason),
            'emergency_without_consent_reason' => $referral->emergency_without_consent_reason,
            'exact_pause_or_stop_applied' => $this->hasExactPauseOrStop($concern),
            'future_clinic_acceptance_eligible' => $acceptanceEligible,
            'future_clinic_acceptance_blocked_reason' => $acceptanceBlockedReason,
            'clinic_appointment_exists' => $referral->clinic_appointment_id !== null,
            'clinic_appointment_reference' => $referral->clinicAppointment?->appointment_reference,
            'grooming_clearance_status' => $referral->grooming_clearance_status,
            'grooming_clearance_status_label' => GroomingClinicReferral::groomingClearanceLabel(
                $referral->grooming_clearance_status,
            ),
            'cancelled_at' => $referral->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $referral->cancellation_reason,
            'customer_cancellation_summary' => $referral->customer_cancellation_summary,
            'resolved_at' => $referral->resolved_at?->toIso8601String(),
            'internal_resolution_notes' => $referral->internal_resolution_notes,
            'customer_resolution_summary' => $referral->customer_resolution_summary,
            'financial_correction_review_required' => (bool) $booking->paid,
        ];
    }

    private function customerReferral(GroomingClinicReferral $referral): array
    {
        $pet = $referral->pet()->first();
        $booking = $referral->booking()->first();
        $response = $this->referralConsentResponse($referral);
        $statement = $response?->statement_text
            ?? ($pet ? $this->statements->consent($pet) : null);
        $statementVersion = $response?->statement_version
            ?? GroomingClinicReferralStatement::CONSENT_VERSION;

        return [
            'public_id' => $referral->public_id,
            'pet_id' => $pet?->pet_id,
            'pet_name' => $pet?->pet_name,
            'pet_species' => $pet?->species,
            'booking_reference' => $booking?->booking_reference,
            'status' => $referral->status,
            'status_label' => GroomingClinicReferral::statusLabel($referral->status),
            'urgency' => $referral->urgency,
            'urgency_label' => GroomingClinicReferral::urgencyLabel($referral->urgency),
            'customer_explanation' => $referral->customer_explanation,
            'referred_at' => $referral->referred_at?->toIso8601String(),
            'consent_required' => (bool) $referral->consent_required,
            'consent_state' => $response ? 'recorded' : 'pending',
            'consent_decision' => $response?->decision,
            'consent_statement' => $statement,
            'consent_statement_version' => $statementVersion,
            'clinic_accepted' => $referral->accepted_at !== null,
            'clinic_appointment_reference' => $referral->clinicAppointment?->appointment_reference,
            'clinic_assessment_started' => $referral->clinic_review_started_at !== null,
            'customer_cancellation_summary' => $referral->customer_cancellation_summary,
            'customer_resolution_summary' => $referral->customer_resolution_summary,
        ];
    }

    private function staffContext(
        Booking $booking,
        BookingPet $bookingPet,
        Pet $pet,
        GroomingMedicalConcern $concern,
    ): array {
        return [
            'booking_reference' => $booking->booking_reference,
            'booking_id' => $booking->booking_id,
            'booking_pet_id' => $bookingPet->booking_pet_id,
            'pet_id' => $pet->pet_id,
            'pet_name' => $pet->pet_name,
            'pet_species' => $pet->species,
            'grooming_state' => $bookingPet->grooming_state,
            'concern_public_id' => $concern->public_id,
        ];
    }

    private function referralConsentResponse(
        GroomingClinicReferral $referral,
    ): ?GroomingMedicalConcernResponse {
        if (! $referral->consent_response_id) {
            return null;
        }

        return GroomingMedicalConcernResponse::query()
            ->whereKey($referral->consent_response_id)
            ->where('concern_id', $referral->grooming_medical_concern_id)
            ->where('response_kind', GroomingMedicalConcernResponse::KIND_CLINIC_REFERRAL_CONSENT)
            ->first();
    }

    private function acceptanceAvailability(
        GroomingClinicReferral $referral,
        GroomingMedicalConcern $concern,
    ): array {
        if (! $this->hasExactPauseOrStop($concern)) {
            return [false, 'Apply Pause Grooming or Stop Grooming to this exact concern before clinic acceptance.'];
        }

        if ($referral->status !== GroomingClinicReferral::STATUS_PENDING_CLINIC_ACCEPTANCE) {
            return [false, $referral->status === GroomingClinicReferral::STATUS_PENDING_CONSENT
                ? 'Clinic-referral consent is still pending.'
                : 'The referral is not pending clinic acceptance.'];
        }

        $response = $this->referralConsentResponse($referral);
        $approved = $response?->decision
            === GroomingMedicalConcernResponse::DECISION_APPROVED;
        $emergencyPath = in_array($referral->urgency, [
            GroomingClinicReferral::URGENCY_URGENT,
            GroomingClinicReferral::URGENCY_EMERGENCY,
        ], true) && filled($referral->emergency_without_consent_reason);

        if (! $approved && ! $emergencyPath) {
            return [false, 'Approved clinic-referral consent is required before clinic acceptance.'];
        }

        return [true, null];
    }

    private function hasExactPauseOrStop(GroomingMedicalConcern $concern): bool
    {
        return in_array($concern->applied_grooming_action, [
            GroomingMedicalConcern::ACTION_PAUSE_GROOMING,
            GroomingMedicalConcern::ACTION_STOP_GROOMING,
        ], true) && $concern->action_applied_at !== null;
    }

    private function safeConsentResponse(
        GroomingMedicalConcernResponse $response,
        bool $includeCaptureMetadata,
    ): array {
        $safe = [
            'decision' => $response->decision,
            'response_channel' => $response->response_channel,
            'response_channel_label' => GroomingMedicalConcernResponse::channelLabel(
                $response->response_channel,
            ),
            'responded_by_name' => $response->responded_by_name,
            'responded_at' => $response->responded_at?->toIso8601String(),
            'statement_version' => $response->statement_version,
        ];

        if ($includeCaptureMetadata) {
            $safe['captured_by_name'] = $response->captured_by_name;
        }

        return $safe;
    }

    private function notificationMessage(Pet $pet, string $urgency): string
    {
        return sprintf(
            'A %s clinic referral was requested for %s. Your consent is required; please review the referral details.',
            strtolower(GroomingClinicReferral::urgencyLabel($urgency)),
            $pet->pet_name,
        );
    }

    private function notificationSummary(
        CustomerNotification $notification,
        GroomingClinicReferral $referral,
        Pet $pet,
    ): array {
        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'message' => $notification->message,
            'is_read' => (bool) $notification->is_read,
            'created_at' => $notification->created_at?->toIso8601String(),
            'referral_public_id' => $referral->public_id,
            'pet_id' => $pet->pet_id,
            'pet_name' => $pet->pet_name,
        ];
    }

    private function serverControlledReferralRules(): array
    {
        return collect([
            'id', 'public_id', 'booking_id', 'booking_pet_id', 'pet_id',
            'grooming_medical_concern_id', 'concern_id', 'status',
            'consent_required', 'consent_response_id', 'owner_user_id_at_referral',
            'owner_name_at_referral', 'referred_by_user_id', 'referred_by_name',
            'referred_at', 'customer_notified_at', 'clinic_appointment_id',
            'grooming_clearance_status', 'accepted_by_user_id', 'accepted_by_name',
            'accepted_at', 'clinic_review_started_by_user_id',
            'clinic_review_started_by_name', 'clinic_review_started_at',
            'cancelled_by_user_id', 'cancelled_by_name', 'cancelled_at',
            'cancellation_reason', 'customer_cancellation_summary',
            'resolved_by_user_id', 'resolved_by_name', 'resolved_at',
            'internal_resolution_notes', 'customer_resolution_summary', 'created_at',
        ])->mapWithKeys(fn (string $field) => [$field => ['prohibited']])->all();
    }

    private function serverControlledConsentRules(): array
    {
        return collect([
            'concern_id', 'referral_id', 'response_kind', 'response_channel',
            'responded_by_user_id', 'responded_by_name', 'responded_at',
            'statement_text', 'statement_version', 'statement_hash',
            'captured_by_user_id', 'captured_by_name', 'owner_user_id', 'created_at',
        ])->mapWithKeys(fn (string $field) => [$field => ['prohibited']])->all();
    }

    private function trimRequestStrings(Request $request, array $fields): void
    {
        $trimmed = [];

        foreach ($fields as $field) {
            if ($request->exists($field) && is_string($request->input($field))) {
                $trimmed[$field] = trim($request->input($field));
            }
        }

        if ($trimmed !== []) {
            $request->merge($trimmed);
        }
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function displayName(User $user, string $fallback): string
    {
        $name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));

        return $name !== '' ? $name : $fallback;
    }

    private function throwContextNotFound(): never
    {
        throw new HttpResponseException($this->referralNotFound());
    }

    private function referralNotFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Clinic referral context not found.',
        ], 404);
    }

    private function customerNotFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Clinic referral not found.',
        ], 404);
    }

    private function conflict(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 409);
    }

    private function unprocessable(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 422);
    }
}
