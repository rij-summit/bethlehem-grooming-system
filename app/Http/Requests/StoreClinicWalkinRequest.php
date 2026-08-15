<?php

namespace App\Http\Requests;

use App\Rules\ValidBreedCoat;
use App\Rules\ValidPetSize;
use App\Rules\ValidPetWeight;
use Illuminate\Foundation\Http\FormRequest;

class StoreClinicWalkinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (($this->input('owner_record_type') ?? 'new') !== 'new') {
            return;
        }

        $email = strtolower(trim((string) $this->input('email', '')));
        $phone = preg_replace('/\D+/', '', trim((string) $this->input('phone', ''))) ?? '';

        if (preg_match('/^63\d{10}$/', $phone)) {
            $phone = '0'.substr($phone, 2);
        } elseif (preg_match('/^9\d{9}$/', $phone)) {
            $phone = '0'.$phone;
        }

        $this->merge([
            'fname' => $this->normalizeText($this->input('fname')),
            'lname' => $this->normalizeText($this->input('lname')),
            'mname' => $this->normalizeText($this->input('mname')) ?: null,
            'email' => $email !== '' ? $email : null,
            'phone' => $phone,
        ]);
    }

    public function rules(): array
    {
        return [
            // Owner
            'fname' => ['required', 'string', 'max:100'],
            'lname' => ['required', 'string', 'max:100'],
            'mname' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ($this->input('owner_record_type') ?? 'new') === 'new'
                ? ['required', 'string', 'max:20', 'regex:/^09\d{9}$/']
                : ['required', 'string', 'max:20', 'regex:/^[0-9+\-\s()]+$/'],
            'owner_record_type' => ['nullable', 'in:new,registered,unregistered'],
            'customer_user_id' => ['nullable', 'integer', 'exists:users,user_id'],
            'unregistered_customer_id' => ['nullable', 'integer', 'exists:unregistered_customers,id'],
            'confirm_similar_name' => ['sometimes', 'boolean'],

            // Pet — single pet for clinic walk-in
            'pet_id' => ['nullable', 'integer', 'exists:pets,pet_id'],
            'pet_name' => ['required', 'string', 'max:100'],
            'species' => ['required', 'string', 'max:100'],
            'breed' => ['nullable', 'string', 'max:100'],
            'fur_type' => ['nullable', 'string', 'max:100', new ValidBreedCoat],
            'weight' => ['nullable', 'numeric', new ValidPetWeight],
            'size' => ['nullable', 'in:small,medium,large,extra_large', new ValidPetSize],
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
            'terms_agreed.accepted' => 'The customer must agree to the terms before registration.',
            'chief_complaint.required' => 'A chief complaint or reason for visit is required.',
            'phone.regex' => ($this->input('owner_record_type') ?? 'new') === 'new'
                ? 'Phone number must be 11 digits and start with 09.'
                : 'Phone number may only contain digits, spaces, +, -, and parentheses.',
        ];
    }

    private function normalizeText(mixed $value): string
    {
        return preg_replace('/\s+/', ' ', trim((string) $value)) ?? '';
    }
}
