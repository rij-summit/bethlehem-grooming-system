<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClinicWalkinRequest;
use App\Models\ClinicAppointment;
use App\Models\Pet;
use App\Models\User;
use App\Models\Walkin;
use App\Support\PetWeightSize;
use Illuminate\Support\Facades\DB;

class ClinicWalkinController extends Controller
{
    public function store(StoreClinicWalkinRequest $request)
    {
        return DB::transaction(function () use ($request) {
            $data = $request->validated();
            $data = PetWeightSize::withComputedSize($data);

            $user = ! empty($data['email'])
                ? User::where('email', $data['email'])->first()
                : null;

            $walkin = Walkin::create([
                'fname' => $data['fname'],
                'lname' => $data['lname'],
                'mname' => $data['mname'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'],
                'sedation_consent' => false,
                'terms_agreed' => $data['terms_agreed'],
                'user_id' => $user?->user_id,
                'appointment_type' => 'clinic',
                'chief_complaint' => $data['chief_complaint'],
            ]);

            $pet = $this->findOrCreatePet($user, $data);

            // Atomically assign today's clinic queue number
            $queueNumber = ClinicAppointment::where('appointment_date', now()->toDateString())
                ->lockForUpdate()
                ->count() + 1;

            $reference = 'CL-'.now()->format('Ymd').'-'.str_pad($queueNumber, 3, '0', STR_PAD_LEFT);

            $appointment = ClinicAppointment::create([
                'appointment_reference' => $reference,
                'appointment_type' => 'walk_in',
                'status' => 'checked_in',
                'queue_number' => $queueNumber,
                'appointment_date' => now()->toDateString(),
                'user_id' => $user?->user_id,
                'walkin_id' => $walkin->id,
                'pet_id' => $pet->pet_id,
                'chief_complaint' => $data['chief_complaint'],
                'total_amount' => 0,
                'paid' => false,
                'checked_in_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'appointment_reference' => $reference,
                'queue_number' => $queueNumber,
                'appointment_id' => $appointment->id,
                'appointment_date' => $appointment->appointment_date->toDateString(),
                'status' => $appointment->status,
                'owner' => [
                    'name' => trim("{$walkin->fname} {$walkin->lname}"),
                    'email' => $walkin->email,
                    'phone' => $walkin->phone,
                ],
                'pet' => [
                    'name' => $pet->pet_name,
                    'species' => $pet->species,
                    'breed' => $pet->breed,
                ],
                'chief_complaint' => $walkin->chief_complaint,
                'returning_customer' => $user !== null,
            ], 201);
        });
    }

    private function findOrCreatePet(?User $user, array $data): Pet
    {
        if ($user) {
            $existing = Pet::where('user_id', $user->user_id)
                ->whereRaw('LOWER(pet_name) = ?', [strtolower($data['pet_name'])])
                ->first();

            if ($existing) {
                $existing->update([
                    'breed' => $data['breed'] ?? $existing->breed,
                    'fur_type' => $data['fur_type'] ?? $existing->fur_type,
                    'weight' => $data['weight'] ?? $existing->weight,
                    'size' => $data['size'] ?? $existing->size,
                ]);

                return $existing;
            }
        }

        return Pet::create([
            'user_id' => $user?->user_id,
            'pet_name' => $data['pet_name'],
            'species' => $data['species'],
            'breed' => $data['breed'] ?? null,
            'fur_type' => $data['fur_type'] ?? null,
            'weight' => $data['weight'] ?? null,
            'size' => $data['size'] ?? null,
            'medical_conditions' => $data['medical_conditions'] ?? null,
            'is_archived' => false,
        ]);
    }
}
