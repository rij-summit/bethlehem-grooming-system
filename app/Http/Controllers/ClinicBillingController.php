<?php

namespace App\Http\Controllers;

use App\Models\ClinicAppointment;
use App\Services\ClinicBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClinicBillingController extends Controller
{
    public function index(int $id): JsonResponse
    {
        $appointment = ClinicAppointment::findOrFail($id);
        return response()->json([
            'total_amount' => $appointment->total_amount, 'paid' => $appointment->paid,
            'charges' => $appointment->charges()->with('caseService')->orderBy('id')->paginate(20),
        ]);
    }

    public function update(Request $request, int $id, int $chargeId, ClinicBillingService $billing): JsonResponse
    {
        return response()->json(['data' => $billing->updateCharge($id, $chargeId, $request->all(), $request->user())]);
    }
}
