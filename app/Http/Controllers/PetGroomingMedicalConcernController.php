<?php

namespace App\Http\Controllers;

use App\Models\GroomingMedicalConcern;
use App\Models\GroomingMedicalConcernResponse;
use App\Models\Pet;
use App\Models\User;
use App\Services\GroomingMedicalConcernResponseStatement;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PetGroomingMedicalConcernController extends Controller
{
    public function __construct(
        private readonly GroomingMedicalConcernResponseStatement $statements,
    ) {}

    private const SEVERITY_LABELS = [
        GroomingMedicalConcern::SEVERITY_LOW => 'Low',
        GroomingMedicalConcern::SEVERITY_MODERATE => 'Moderate',
        GroomingMedicalConcern::SEVERITY_URGENT => 'Urgent',
    ];

    private const GROOMING_ACTION_LABELS = [
        GroomingMedicalConcern::ACTION_CONTINUE_WITH_OBSERVATION => 'Continue with observation',
        GroomingMedicalConcern::ACTION_PAUSE_GROOMING => 'Pause grooming',
        GroomingMedicalConcern::ACTION_STOP_GROOMING => 'Stop grooming',
    ];

    private const STATUS_LABELS = [
        GroomingMedicalConcern::STATUS_OPEN => 'Open',
        GroomingMedicalConcern::STATUS_AWAITING_CUSTOMER => 'Awaiting customer',
        GroomingMedicalConcern::STATUS_REFERRED_TO_CLINIC => 'Referred to clinic',
        GroomingMedicalConcern::STATUS_UNDER_CLINIC_REVIEW => 'Under clinic review',
        GroomingMedicalConcern::STATUS_RESOLVED => 'Resolved',
        GroomingMedicalConcern::STATUS_CANCELLED => 'Cancelled',
    ];

    private const CUSTOMER_RESPONSE_LABELS = [
        GroomingMedicalConcern::CUSTOMER_RESPONSE_NOT_REQUIRED => 'Not required',
        GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING => 'Pending',
        GroomingMedicalConcern::CUSTOMER_RESPONSE_ACKNOWLEDGED => 'Acknowledged',
        GroomingMedicalConcern::CUSTOMER_RESPONSE_APPROVED => 'Approved',
        GroomingMedicalConcern::CUSTOMER_RESPONSE_DECLINED => 'Declined',
    ];

    private const REQUIRED_ACTION_NONE = 'none';

    private const REQUIRED_ACTION_ACKNOWLEDGMENT = 'acknowledgment';

    private const REQUIRED_ACTION_CONSENT = 'consent';

    private const REQUIRED_ACTION_LABELS = [
        self::REQUIRED_ACTION_NONE => 'None',
        self::REQUIRED_ACTION_ACKNOWLEDGMENT => 'Acknowledge',
        self::REQUIRED_ACTION_CONSENT => 'Provide consent',
    ];

    private const RESPONSE_DECISION_LABELS = [
        GroomingMedicalConcernResponse::DECISION_ACKNOWLEDGED => 'Acknowledged',
        GroomingMedicalConcernResponse::DECISION_APPROVED => 'Approved',
        GroomingMedicalConcernResponse::DECISION_DECLINED => 'Declined',
    ];

    public function index(Request $request, int $petId): JsonResponse
    {
        $pet = $this->findOwnedPet($request, $petId);

        if (! $pet) {
            return $this->petNotFound();
        }

        $concerns = $this->customerSafeConcerns($pet)
            ->orderByDesc('reported_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (GroomingMedicalConcern $concern) => $this->formatConcern(
                $concern,
                $pet,
            ))
            ->values();

        return response()->json([
            'success' => true,
            'concerns' => $concerns,
        ]);
    }

    public function show(Request $request, int $petId, string $publicId): JsonResponse
    {
        $pet = $this->findOwnedPet($request, $petId);

        if (! $pet) {
            return $this->petNotFound();
        }

        $concern = $this->customerSafeConcerns($pet)
            ->where('public_id', $publicId)
            ->first();

        if (! $concern) {
            return response()->json([
                'success' => false,
                'message' => 'Medical concern not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'concern' => $this->formatConcern($concern, $pet, true),
        ]);
    }

    public function acknowledge(
        Request $request,
        int $petId,
        string $publicId,
    ): JsonResponse {
        $pet = $this->findOwnedPet($request, $petId);

        if (! $pet) {
            return $this->petNotFound();
        }

        if (! $this->findCustomerVisibleConcern($pet, $publicId)) {
            return $this->concernNotFound();
        }

        $request->validate([
            'decision' => ['prohibited'],
            'signature_name' => ['prohibited'],
            ...$this->serverControlledResponseRules(),
        ]);

        return $this->submitResponse(
            $request,
            $petId,
            $publicId,
            GroomingMedicalConcernResponse::KIND_ACKNOWLEDGMENT,
            GroomingMedicalConcernResponse::DECISION_ACKNOWLEDGED,
        );
    }

    public function consent(
        Request $request,
        int $petId,
        string $publicId,
    ): JsonResponse {
        $pet = $this->findOwnedPet($request, $petId);

        if (! $pet) {
            return $this->petNotFound();
        }

        if (! $this->findCustomerVisibleConcern($pet, $publicId)) {
            return $this->concernNotFound();
        }

        if ($request->exists('signature_name')) {
            $request->merge([
                'signature_name' => trim((string) $request->input('signature_name')),
            ]);
        }

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
            ...$this->serverControlledResponseRules(),
        ]);

        return $this->submitResponse(
            $request,
            $petId,
            $publicId,
            GroomingMedicalConcernResponse::KIND_CONSENT,
            $validated['decision'],
            $validated['signature_name'],
        );
    }

    private function submitResponse(
        Request $request,
        int $petId,
        string $publicId,
        string $responseKind,
        string $decision,
        ?string $signatureName = null,
    ): JsonResponse {
        try {
            return DB::transaction(function () use (
                $request,
                $petId,
                $publicId,
                $responseKind,
                $decision,
                $signatureName,
            ): JsonResponse {
                $pet = Pet::query()
                    ->select(['pet_id', 'user_id', 'pet_name'])
                    ->where('pet_id', $petId)
                    ->where('user_id', $request->user()->user_id)
                    ->lockForUpdate()
                    ->first();

                if (! $pet || $request->user()->role !== 'customer') {
                    return $this->petNotFound();
                }

                $concern = GroomingMedicalConcern::query()
                    ->where('pet_id', $pet->pet_id)
                    ->where('public_id', $publicId)
                    ->customerVisible()
                    ->whereNotNull('customer_message')
                    ->whereRaw("TRIM(customer_message) <> ''")
                    ->lockForUpdate()
                    ->first();

                if (! $concern) {
                    return $this->concernNotFound();
                }

                $existingResponse = GroomingMedicalConcernResponse::query()
                    ->where('concern_id', $concern->id)
                    ->where('response_kind', $responseKind)
                    ->lockForUpdate()
                    ->first();

                if ($existingResponse) {
                    return $this->conflict(
                        'This customer response has already been submitted and cannot be changed.',
                    );
                }

                $eligibilityError = $this->responseEligibilityError(
                    $concern,
                    $responseKind,
                );

                if ($eligibilityError !== null) {
                    return $this->conflict($eligibilityError);
                }

                $statementText = $responseKind
                    === GroomingMedicalConcernResponse::KIND_CONSENT
                    ? $this->statements->consent($pet, $concern)
                    : $this->statements->acknowledgment($pet, $concern);
                $statementVersion = $responseKind
                    === GroomingMedicalConcernResponse::KIND_CONSENT
                    ? GroomingMedicalConcernResponseStatement::CONSENT_VERSION
                    : GroomingMedicalConcernResponseStatement::ACKNOWLEDGMENT_VERSION;
                $respondedAt = now();

                $response = GroomingMedicalConcernResponse::create([
                    'concern_id' => $concern->id,
                    'responded_by_user_id' => $request->user()->user_id,
                    'responded_by_name' => $this->formatCustomerName($request->user()),
                    'response_kind' => $responseKind,
                    'decision' => $decision,
                    'statement_text' => $statementText,
                    'statement_version' => $statementVersion,
                    'signature_name' => $signatureName,
                    'responded_at' => $respondedAt,
                ]);

                $concern->forceFill([
                    'status' => GroomingMedicalConcern::STATUS_OPEN,
                    'customer_response_status' => match ($decision) {
                        GroomingMedicalConcernResponse::DECISION_ACKNOWLEDGED => GroomingMedicalConcern::CUSTOMER_RESPONSE_ACKNOWLEDGED,
                        GroomingMedicalConcernResponse::DECISION_APPROVED => GroomingMedicalConcern::CUSTOMER_RESPONSE_APPROVED,
                        GroomingMedicalConcernResponse::DECISION_DECLINED => GroomingMedicalConcern::CUSTOMER_RESPONSE_DECLINED,
                    },
                ])->save();

                $concern = $this->findCustomerVisibleConcern($pet, $publicId);

                return response()->json([
                    'success' => true,
                    'message' => $responseKind
                        === GroomingMedicalConcernResponse::KIND_CONSENT
                            ? 'Your consent decision was recorded.'
                            : 'Your acknowledgment was recorded.',
                    'response' => $this->formatCustomerResponse($response),
                    'concern' => $this->formatConcern($concern, $pet, true),
                ], 201);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->conflict(
                'This customer response has already been submitted and cannot be changed.',
            );
        }
    }

    private function responseEligibilityError(
        GroomingMedicalConcern $concern,
        string $responseKind,
    ): ?string {
        if (in_array($concern->status, [
            GroomingMedicalConcern::STATUS_RESOLVED,
            GroomingMedicalConcern::STATUS_CANCELLED,
        ], true)) {
            return 'This medical concern is closed and cannot receive a customer response.';
        }

        if (
            $concern->status !== GroomingMedicalConcern::STATUS_AWAITING_CUSTOMER
            || $concern->customer_response_status
                !== GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING
        ) {
            return 'This medical concern is not awaiting a customer response.';
        }

        if (
            $responseKind === GroomingMedicalConcernResponse::KIND_ACKNOWLEDGMENT
            && (! $concern->acknowledgment_required || $concern->consent_required)
        ) {
            return $concern->consent_required
                ? 'Consent is required for this medical concern; acknowledgment cannot be submitted instead.'
                : 'Acknowledgment is not required for this medical concern.';
        }

        if (
            $responseKind === GroomingMedicalConcernResponse::KIND_CONSENT
            && ! $concern->consent_required
        ) {
            return 'Consent is not required for this medical concern.';
        }

        return null;
    }

    private function serverControlledResponseRules(): array
    {
        return [
            'concern_id' => ['prohibited'],
            'responded_by_user_id' => ['prohibited'],
            'responded_by_name' => ['prohibited'],
            'response_kind' => ['prohibited'],
            'statement_text' => ['prohibited'],
            'statement_version' => ['prohibited'],
            'responded_at' => ['prohibited'],
            'created_at' => ['prohibited'],
        ];
    }

    private function formatCustomerName(User $user): string
    {
        $name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));

        return $name !== '' ? $name : 'Customer';
    }

    private function findOwnedPet(Request $request, int $petId): ?Pet
    {
        if ($request->user()->role !== 'customer') {
            return null;
        }

        return Pet::query()
            ->select(['pet_id', 'user_id', 'pet_name'])
            ->where('pet_id', $petId)
            ->where('user_id', $request->user()->user_id)
            ->first();
    }

    private function findCustomerVisibleConcern(
        Pet $pet,
        string $publicId,
    ): ?GroomingMedicalConcern {
        return $this->customerSafeConcerns($pet)
            ->where('public_id', $publicId)
            ->first();
    }

    private function customerSafeConcerns(Pet $pet): HasMany
    {
        return $pet->groomingMedicalConcerns()
            ->select([
                'id',
                'public_id',
                'pet_id',
                'reported_at',
                'customer_message',
                'severity',
                'recommended_grooming_action',
                'applied_grooming_action',
                'status',
                'acknowledgment_required',
                'consent_required',
                'customer_response_status',
                'customer_notified_at',
                'clinic_appointment_id',
                'customer_resolution_summary',
                'resolved_at',
            ])
            ->customerVisible()
            ->whereNotNull('customer_message')
            ->whereRaw("TRIM(customer_message) <> ''")
            ->with([
                'clinicAppointment:id,appointment_reference',
                'responses' => fn ($query) => $query
                    ->select([
                        'id',
                        'concern_id',
                        'responded_by_name',
                        'response_kind',
                        'decision',
                        'statement_text',
                        'statement_version',
                        'responded_at',
                    ])
                    ->orderByDesc('responded_at'),
            ]);
    }

    private function formatConcern(
        GroomingMedicalConcern $concern,
        Pet $pet,
        bool $includeResponseDetails = false,
    ): array {
        $requiredAction = $this->requiredCustomerAction($concern);
        $formatted = [
            'public_id' => $concern->public_id,
            'pet_name' => $pet->pet_name,
            'concern_date' => $concern->reported_at?->toIso8601String(),
            'customer_message' => $concern->customer_message,
            'severity' => $concern->severity,
            'severity_label' => self::SEVERITY_LABELS[$concern->severity] ?? 'Unknown',
            'recommended_grooming_action' => $concern->recommended_grooming_action,
            'recommended_grooming_action_label' => self::GROOMING_ACTION_LABELS[
                $concern->recommended_grooming_action
            ] ?? 'Unknown',
            'applied_grooming_action' => $concern->applied_grooming_action,
            'applied_grooming_action_label' => $concern->applied_grooming_action
                ? (self::GROOMING_ACTION_LABELS[$concern->applied_grooming_action] ?? 'Unknown')
                : null,
            'status' => $concern->status,
            'status_label' => self::STATUS_LABELS[$concern->status] ?? 'Unknown',
            'acknowledgment_required' => $concern->acknowledgment_required,
            'consent_required' => $concern->consent_required,
            'customer_response_status' => $concern->customer_response_status,
            'customer_response_status_label' => self::CUSTOMER_RESPONSE_LABELS[
                $concern->customer_response_status
            ] ?? 'Unknown',
            'customer_notified_at' => $concern->customer_notified_at?->toIso8601String(),
            'clinic_appointment_reference' => $concern->clinicAppointment?->appointment_reference,
            'customer_resolution_summary' => $concern->customer_resolution_summary,
            'resolved_at' => $concern->resolved_at?->toIso8601String(),
            'customer_action_required' => $requiredAction !== self::REQUIRED_ACTION_NONE,
            'required_customer_action' => $requiredAction,
            'required_customer_action_label' => self::REQUIRED_ACTION_LABELS[$requiredAction],
        ];

        if (! $includeResponseDetails) {
            return $formatted;
        }

        $submittedResponse = $this->customerResponseForDisplay($concern);
        $responseStatement = $submittedResponse?->statement_text;
        $statementVersion = $submittedResponse?->statement_version;

        if (! $submittedResponse) {
            if ($requiredAction === self::REQUIRED_ACTION_CONSENT) {
                $responseStatement = $this->statements->consent($pet, $concern);
                $statementVersion = GroomingMedicalConcernResponseStatement::CONSENT_VERSION;
            } elseif ($requiredAction === self::REQUIRED_ACTION_ACKNOWLEDGMENT) {
                $responseStatement = $this->statements->acknowledgment($pet, $concern);
                $statementVersion = GroomingMedicalConcernResponseStatement::ACKNOWLEDGMENT_VERSION;
            }
        }

        return [
            ...$formatted,
            'response_statement' => $responseStatement,
            'response_statement_version' => $statementVersion,
            'typed_signature_required' => $requiredAction === self::REQUIRED_ACTION_CONSENT,
            'allowed_consent_decisions' => $requiredAction === self::REQUIRED_ACTION_CONSENT
                ? [
                    GroomingMedicalConcernResponse::DECISION_APPROVED,
                    GroomingMedicalConcernResponse::DECISION_DECLINED,
                ]
                : [],
            'submitted_response' => $submittedResponse
                ? $this->formatCustomerResponse($submittedResponse)
                : null,
        ];
    }

    private function customerResponseForDisplay(
        GroomingMedicalConcern $concern,
    ): ?GroomingMedicalConcernResponse {
        $responses = $concern->relationLoaded('responses')
            ? $concern->responses
            : $concern->responses()->orderByDesc('responded_at')->get();

        return $responses->firstWhere(
            'response_kind',
            GroomingMedicalConcernResponse::KIND_CONSENT,
        ) ?? $responses->firstWhere(
            'response_kind',
            GroomingMedicalConcernResponse::KIND_ACKNOWLEDGMENT,
        );
    }

    private function formatCustomerResponse(
        GroomingMedicalConcernResponse $response,
    ): array {
        return [
            'response_kind' => $response->response_kind,
            'decision' => $response->decision,
            'decision_label' => self::RESPONSE_DECISION_LABELS[
                $response->decision
            ] ?? 'Unknown',
            'responded_by_name' => $response->responded_by_name,
            'responded_at' => $response->responded_at?->toIso8601String(),
        ];
    }

    private function requiredCustomerAction(
        GroomingMedicalConcern $concern,
    ): string {
        if (
            $concern->status !== GroomingMedicalConcern::STATUS_AWAITING_CUSTOMER
            ||
            $concern->customer_response_status
            !== GroomingMedicalConcern::CUSTOMER_RESPONSE_PENDING
        ) {
            return self::REQUIRED_ACTION_NONE;
        }

        if ($concern->consent_required) {
            return self::REQUIRED_ACTION_CONSENT;
        }

        if ($concern->acknowledgment_required) {
            return self::REQUIRED_ACTION_ACKNOWLEDGMENT;
        }

        return self::REQUIRED_ACTION_NONE;
    }

    private function petNotFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Pet not found.',
        ], 404);
    }

    private function concernNotFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Medical concern not found.',
        ], 404);
    }

    private function conflict(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 409);
    }
}
