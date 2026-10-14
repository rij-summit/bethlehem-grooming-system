<?php

namespace App\Http\Controllers;

use App\Models\ClinicCaseService;
use App\Models\ClinicConfinement;
use App\Models\Pet;
use App\Services\PetProfileHistoryService;
use Illuminate\Http\Request;

class AdminPetProfileController extends Controller
{
    public function show(
        Request $request,
        int $petId,
        PetProfileHistoryService $history,
    ) {
        if (! in_array($request->user()?->role, ['admin', 'staff'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Admin or staff access required.',
            ], 403);
        }

        $pet = Pet::query()->find($petId);

        if (! $pet) {
            return response()->json([
                'success' => false,
                'message' => 'Pet not found.',
            ], 404);
        }

        $data = $request->validate([
            'history' => 'sometimes|in:services,confinement',
            'page' => 'sometimes|integer|min:1',
        ]);
        if (($data['history'] ?? null) === 'services') {
            return response()->json(ClinicCaseService::query()
                ->whereHas('appointment', fn ($query) => $query->where('pet_id', $petId))
                ->where('status', 'performed')
                ->with(['appointment:id,appointment_reference', 'clinicService', 'charge'])
                ->orderByDesc('performed_at')->orderByDesc('id')->paginate(20));
        }
        if (($data['history'] ?? null) === 'confinement') {
            return response()->json(ClinicConfinement::query()
                ->whereHas('appointment', fn ($query) => $query->where('pet_id', $petId))
                ->with(['appointment:id,appointment_reference', 'caseService.clinicService', 'caseService.charge'])
                ->orderByDesc('admitted_at')->orderByDesc('id')->paginate(20));
        }

        return response()->json([
            'success' => true,
            'grooming_records' => $history->groomingRecords($pet),
            'medical_records' => $history->medicalRecords($pet),
            'vaccinations' => $history->vaccinationRecords($pet),
        ]);
    }
}
