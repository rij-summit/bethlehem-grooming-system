<?php

namespace App\Http\Controllers;

use App\Exceptions\ClinicReferralAcceptanceException;
use App\Models\GroomingClinicReferral;
use App\Services\GroomingClinicReferralAcceptanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminGroomingClinicReferralController extends Controller
{
    public function __construct(
        private readonly GroomingClinicReferralAcceptanceService $acceptance,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => [
                'nullable',
                'string',
                Rule::in([
                    GroomingClinicReferral::STATUS_PENDING_CLINIC_ACCEPTANCE,
                    GroomingClinicReferral::STATUS_ACCEPTED,
                    GroomingClinicReferral::STATUS_UNDER_CLINIC_REVIEW,
                    GroomingClinicReferral::STATUS_COMPLETED,
                ]),
            ],
            'urgency' => [
                'nullable',
                'string',
                Rule::in(GroomingClinicReferral::URGENCIES),
            ],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json([
            'success' => true,
            ...$this->acceptance->queue($filters),
        ]);
    }

    public function show(string $publicId): JsonResponse
    {
        $referral = $this->acceptance->findForStaff($publicId);

        if (! $referral) {
            return $this->notFound();
        }

        return response()->json([
            'success' => true,
            'referral' => $this->acceptance->present($referral),
        ]);
    }

    public function accept(Request $request, string $publicId): JsonResponse
    {
        try {
            $result = $this->acceptance->accept($publicId, $request->user());

            return response()->json([
                'success' => true,
                'message' => $result['already_accepted']
                    ? 'This clinic referral was already accepted.'
                    : 'Clinic referral accepted and added to today\'s clinic queue.',
                ...$result,
            ], $result['already_accepted'] ? 200 : 201);
        } catch (ClinicReferralAcceptanceException $exception) {
            if ($exception->status === 404) {
                return $this->notFound();
            }

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $exception->status);
        }
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Clinic referral not found.',
        ], 404);
    }
}
