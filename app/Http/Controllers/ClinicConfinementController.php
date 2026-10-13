<?php

namespace App\Http\Controllers;

use App\Models\ClinicAppointment;
use App\Models\ClinicService;
use App\Services\ClinicConfinementService;
use Illuminate\Http\Request;

class ClinicConfinementController extends Controller
{
    public function show(int $id)
    {
        $case = ClinicAppointment::findOrFail($id);
        return response()->json(['data' => $case->confinement()->with(['caseService.clinicService.category', 'caseService.charge'])->first()]);
    }

    public function options(Request $request)
    {
        $data = $request->validate(['q' => 'nullable|string|max:200', 'page' => 'nullable|integer|min:1']);
        return response()->json(ClinicService::with('category')->where('is_active', true)
            ->whereHas('category', fn ($q) => $q->where('name', ClinicConfinementService::CATEGORY))
            ->where(fn ($q) => $q->whereNull('billing_unit')->orWhereIn('billing_unit', ClinicConfinementService::BILLING_UNITS))
            ->when(! empty($data['q']), fn ($q) => $q->where('name', 'like', '%'.$data['q'].'%'))
            ->orderBy('name')->orderBy('id')->paginate(10));
    }

    public function admit(Request $request, int $id, ClinicConfinementService $service)
    {
        $episode = $service->admit($id, $request->all(), $request->user());
        return response()->json(['data' => $episode], $episode->wasRecentlyCreated ? 201 : 200);
    }

    public function observations(int $id)
    {
        $episode = ClinicAppointment::findOrFail($id)->confinement()->firstOrFail();
        return response()->json($episode->observations()->orderBy('recorded_at')->orderBy('id')->paginate(20));
    }

    public function recordObservation(Request $request, int $id, ClinicConfinementService $service)
    {
        $entry = $service->recordObservation($id, $request->all(), $request->user());
        return response()->json(['data' => $entry], $entry->wasRecentlyCreated ? 201 : 200);
    }

    public function preview(int $id, ClinicConfinementService $service)
    {
        return response()->json($service->dischargePreview(ClinicAppointment::with(['vitals', 'pet'])->findOrFail($id)));
    }

    public function discharge(Request $request, int $id, ClinicConfinementService $service)
    {
        return response()->json(['data' => $service->discharge($id, $request->all(), $request->user())]);
    }
}
