<?php

namespace App\Http\Controllers;

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

        return response()->json([
            'success' => true,
            'grooming_records' => $history->groomingRecords($pet),
            'medical_records' => $history->medicalRecords($pet),
            'vaccinations' => $history->vaccinationRecords($pet),
        ]);
    }
}
