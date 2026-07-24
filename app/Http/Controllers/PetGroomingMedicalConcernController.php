<?php

namespace App\Http\Controllers;

use App\Models\GroomingMedicalConcern;
use App\Models\Pet;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PetGroomingMedicalConcernController extends Controller
{
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
            'concern' => $this->formatConcern($concern, $pet),
        ]);
    }

    private function findOwnedPet(Request $request, int $petId): ?Pet
    {
        return Pet::query()
            ->select(['pet_id', 'user_id', 'pet_name'])
            ->where('pet_id', $petId)
            ->where('user_id', $request->user()->user_id)
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
            ->with('clinicAppointment:id,appointment_reference');
    }

    private function formatConcern(
        GroomingMedicalConcern $concern,
        Pet $pet,
    ): array {
        $requiredAction = $this->requiredCustomerAction($concern);

        return [
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
    }

    private function requiredCustomerAction(
        GroomingMedicalConcern $concern,
    ): string {
        if (
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
}
