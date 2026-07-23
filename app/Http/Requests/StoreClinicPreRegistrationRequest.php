<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClinicPreRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'customer';
    }

    public function rules(): array
    {
        $today = now()->toDateString();
        $lastAvailableDate = now()->addDays(2)->toDateString();

        return [
            'appointment_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:'.$today,
                'before_or_equal:'.$lastAvailableDate,
            ],
            'window_id' => [
                'required',
                'integer',
                Rule::exists('time_windows', 'window_id')->where('is_active', true),
            ],
            'pet_id' => [
                'required',
                'integer',
                Rule::exists('pets', 'pet_id')->where(
                    fn ($query) => $query
                        ->where('user_id', $this->user()?->user_id)
                        ->where('is_archived', false),
                ),
            ],
            'chief_complaint' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'appointment_date.before_or_equal' => 'Clinic visits can be pre-registered up to three days in advance.',
            'window_id.required' => 'Select an available clinic visit time before continuing.',
            'window_id.exists' => 'Select an available clinic visit time before continuing.',
            'pet_id.exists' => 'Select one of your active pets before continuing.',
            'chief_complaint.required' => 'A reason for the clinic visit is required.',
            'chief_complaint.min' => 'Please provide more detail about the reason for the visit.',
        ];
    }
}
