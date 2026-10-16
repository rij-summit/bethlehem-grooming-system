<?php

namespace App\Services;

use App\Models\PetGroomer;
use Illuminate\Validation\ValidationException;

class PetGroomerSelection
{
    /** Called inside the shared settings/intake transaction, before any writes. */
    public function validate(array $pets, bool $walkIn): array
    {
        $available = PetGroomer::where('on_duty', true)->get()->keyBy('id');
        foreach ($pets as $index => &$pet) {
            $id = $pet['groomer_id'] ?? null;
            if ($available->isEmpty()) {
                throw ValidationException::withMessages(["pets.$index.groomer_id" => 'No Pet Groomer is available.']);
            }
            if ($id !== null && ! $available->has($id)) {
                throw ValidationException::withMessages(["pets.$index.groomer_id" => 'The selected Pet Groomer is unavailable. Choose again.']);
            }
            if ($id === null && $available->count() === 1) $id = $available->keys()->first();
            if ($walkIn && $id === null) {
                throw ValidationException::withMessages(["pets.$index.groomer_id" => 'Select a Pet Groomer for each pet.']);
            }
            $pet['groomer_id'] = $id === null ? null : (int) $id;
        }
        unset($pet);
        return $pets;
    }
}
