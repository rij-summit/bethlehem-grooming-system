<?php

namespace App\Http\Controllers;

use App\Models\ClinicAppointment;
use App\Services\ClinicCaseServiceRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClinicCaseServiceController extends Controller
{
    public function index(int $id): JsonResponse
    {
        $appointment = ClinicAppointment::findOrFail($id);
        return response()->json($appointment->caseServices()->with(['clinicService.category', 'charge'])->orderBy('id')->paginate(20));
    }

    public function store(Request $request, int $id, ClinicCaseServiceRecorder $recorder): JsonResponse
    {
        $entry = $recorder->save($id, $request->all(), $request->user());
        return response()->json(['data' => $entry], $entry->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, int $id, int $caseServiceId, ClinicCaseServiceRecorder $recorder): JsonResponse
    {
        return response()->json(['data' => $recorder->save($id, $request->all(), $request->user(), $caseServiceId)]);
    }
}
