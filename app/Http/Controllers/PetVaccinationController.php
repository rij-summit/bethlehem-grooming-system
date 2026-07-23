<?php

namespace App\Http\Controllers;

use App\Models\Pet;
use App\Models\User;
use App\Models\VaccinationRecord;
use Illuminate\Http\Request;

class PetVaccinationController extends Controller
{
    public function index(Request $request, int $petId)
    {
        $pet = Pet::query()
            ->where('pet_id', $petId)
            ->where('user_id', $request->user()->user_id)
            ->first();

        if (! $pet) {
            return response()->json([
                'success' => false,
                'message' => 'Pet not found.',
            ], 404);
        }

        $vaccinations = $pet->vaccinationRecords()
            ->visibleToClients()
            ->with([
                'clinicAppointment:id,appointment_reference',
                'administeredBy:user_id,first_name,last_name',
            ])
            ->orderByDesc('administered_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (VaccinationRecord $record) => $this->formatVaccination($record))
            ->values();

        return response()->json([
            'success' => true,
            'vaccinations' => $vaccinations,
        ]);
    }

    private function formatVaccination(VaccinationRecord $record): array
    {
        return [
            'vaccine_name' => $record->vaccine_name,
            'product_name' => $record->product_name,
            'manufacturer' => $record->manufacturer,
            'batch_number' => $record->batch_number,
            'administered_date' => $record->administered_date?->toDateString(),
            'next_due_date' => $record->next_due_date?->toDateString(),
            'product_expiry_date' => $record->product_expiry_date?->toDateString(),
            'dose_amount' => $record->dose_amount,
            'dose_unit' => $record->dose_unit,
            'route' => $record->route,
            'administration_site' => $record->administration_site,
            'administering_provider' => $this->providerDisplay($record),
            'appointment_reference' => $record->clinicAppointment?->appointment_reference,
            'due_status' => $record->dueStatus(),
        ];
    }

    private function providerDisplay(VaccinationRecord $record): string
    {
        if (filled($record->administered_by_name)) {
            return $record->administered_by_name;
        }

        $linkedProviderName = $this->formatUserName($record->administeredBy);

        return $linkedProviderName ?? 'Not provided.';
    }

    private function formatUserName(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        $name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));

        return $name !== '' ? $name : null;
    }
}
