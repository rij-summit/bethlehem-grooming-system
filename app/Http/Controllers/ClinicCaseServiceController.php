<?php

namespace App\Http\Controllers;

use App\Models\ClinicAppointment;
use App\Models\ClinicService;
use App\Services\ClinicCaseServiceRecorder;
use App\Services\ClinicServicePriceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ClinicCaseServiceController extends Controller
{
    public function pricing(int $id, int $clinicServiceId, ClinicServicePriceResolver $resolver): JsonResponse
    {
        $appointment = ClinicAppointment::with(['pet', 'vitals'])->findOrFail($id);
        $service = ClinicService::with('category')->where('is_active', true)->findOrFail($clinicServiceId);
        return response()->json(['data' => $service, 'pricing' => $resolver->preview($service, $appointment)]);
    }

    public function index(int $id): JsonResponse
    {
        $appointment = ClinicAppointment::findOrFail($id);
        $services = $appointment->caseServices()->where('status', 'performed')->with(['clinicService', 'charge']);
        if (Schema::hasTable('clinic_confinements')) $services->withExists('confinement');
        return response()->json($services->orderBy('id')->paginate(20));
    }

    public function store(Request $request, int $id, ClinicCaseServiceRecorder $recorder): JsonResponse
    {
        $request->validate(['status' => 'prohibited', 'quantity' => 'prohibited']);
        $entry = $recorder->save($id, [
            ...$request->only(['clinic_service_id', 'request_key', 'notes', 'applied_price']),
            'status' => 'performed', 'quantity' => 1,
        ], $request->user());
        return response()->json(['data' => $entry], $entry->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, int $id, int $caseServiceId, ClinicCaseServiceRecorder $recorder): JsonResponse
    {
        $recorder->remove($id, $caseServiceId, $request->user());
        return response()->json(['message' => 'Service removed.']);
    }
}
