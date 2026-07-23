<?php

namespace App\Http\Requests;

use App\Rules\ValidBreedCoat;
use App\Rules\ValidPetSize;
use App\Rules\ValidPetWeight;
use Illuminate\Foundation\Http\FormRequest;

class StoreWalkinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Access is gated by auth:sanctum middleware on the route
    }

    public function rules(): array
    {
        return [
            // Owner
            'fname' => ['required', 'string', 'max:100'],
            'lname' => ['required', 'string', 'max:100'],
            'mname' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+\-\s()]+$/'],

            // Pets — at least one required, max 10 (matches frontend limit)
            'pets' => ['required', 'array', 'min:1', 'max:10'],
            'pets.*.pet_name' => ['required', 'string', 'max:100'],
            'pets.*.species' => ['required', 'string', 'max:100'],
            'pets.*.breed' => ['nullable', 'string', 'max:100'],
            'pets.*.fur_type' => ['nullable', 'string', 'max:100', new ValidBreedCoat],
            'pets.*.weight' => ['nullable', 'numeric', new ValidPetWeight],
            'pets.*.size' => ['nullable', 'in:small,medium,large,extra_large', new ValidPetSize],
            'pets.*.medical_conditions' => ['nullable', 'string', 'max:1000'],
            'pets.*.special_instructions' => ['nullable', 'string', 'max:1000'],

            // Services per pet — at least one per pet, each validated against the DB
            'pets.*.services' => ['required', 'array', 'min:1', 'max:20'],
            'pets.*.services.*.service_slug' => ['required', 'string', 'exists:services,slug'],

            // Consent
            'sedation_consent' => ['required', 'boolean'],
            'terms_agreed' => ['required', 'boolean', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'terms_agreed.accepted' => 'The customer must agree to the terms before the walk-in can be registered.',
            'pets.required' => 'At least one pet must be included in the walk-in.',
            'pets.min' => 'At least one pet must be included in the walk-in.',
            'pets.*.services.min' => 'Each pet must have at least one grooming service selected.',
            'pets.*.services.*.service_slug.exists' => 'One or more selected services were not found. Please refresh and try again.',
            'phone.regex' => 'Phone number may only contain digits, spaces, +, -, and parentheses.',
        ];
    }
}
