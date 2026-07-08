<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreClinicWalkinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Owner
            'fname'  => ['required', 'string', 'max:100'],
            'lname'  => ['required', 'string', 'max:100'],
            'mname'  => ['nullable', 'string', 'max:100'],
            'email'  => ['nullable', 'email', 'max:255'],
            'phone'  => ['required', 'string', 'max:20', 'regex:/^[0-9+\-\s()]+$/'],

            // Pet — single pet for clinic walk-in
            'pet_name'           => ['required', 'string', 'max:100'],
            'species'            => ['required', 'string', 'max:100'],
            'breed'              => ['nullable', 'string', 'max:100'],
            'weight'             => ['nullable', 'numeric', 'min:0', 'max:999'],
            'medical_conditions' => ['nullable', 'string', 'max:1000'],

            // Clinic
            'chief_complaint' => ['required', 'string', 'max:1000'],

            // Consent
            'terms_agreed' => ['required', 'boolean', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'terms_agreed.accepted'  => 'The customer must agree to the terms before registration.',
            'chief_complaint.required' => 'A chief complaint or reason for visit is required.',
            'phone.regex'            => 'Phone number may only contain digits, spaces, +, -, and parentheses.',
        ];
    }
}
