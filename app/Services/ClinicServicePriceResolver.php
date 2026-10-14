<?php

namespace App\Services;

use App\Models\ClinicAppointment;
use App\Models\ClinicService;
use App\Support\PetWeightSize;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ClinicServicePriceResolver
{
    public function resolve(ClinicService $service, ClinicAppointment $appointment, mixed $appliedPrice = null): array
    {
        $size = null;
        $price = match ($service->pricing_method) {
            'fixed' => $service->price,
            'range', 'starting_at' => $this->variablePrice($service, $appliedPrice),
            'tiered' => $this->tierPrice($service, $appointment, $size),
            default => null,
        };
        if ($price === null) {
            throw ValidationException::withMessages(['clinic_service_id' => 'This service has no applicable configured price.']);
        }
        return [
            'price' => number_format((float) $price, 2, '.', ''),
            'snapshot' => [
                'name' => $service->name, 'category' => $service->category->name,
                'pricing_method' => $service->pricing_method, 'billing_unit' => $service->billing_unit,
                'configured_price' => $service->price, 'min_price' => $service->min_price,
                'max_price' => $service->max_price, 'tier_basis' => $service->tier_basis,
                'pet_size' => $size,
                'pet_weight_kg' => $service->pricing_method === 'tiered' ? $appointment->vitals->weight_kg : null,
            ],
        ];
    }

    private function variablePrice(ClinicService $service, mixed $price): mixed
    {
        $minimum = $service->pricing_method === 'range' ? $service->min_price : $service->price;
        $maximum = $service->pricing_method === 'range' ? $service->max_price : ClinicService::MAX_PRICE;
        Validator::make(['applied_price' => $price], [
            'applied_price' => ['required', 'numeric', 'decimal:0,2', 'min:'.$minimum, 'max:'.$maximum],
        ], [
            'applied_price.required' => 'Enter the final price before marking this service performed.',
            'applied_price.min' => 'The final price cannot be below the configured minimum of ₱'.number_format((float) $minimum, 2).'.',
            'applied_price.max' => $service->pricing_method === 'range'
                ? 'The final price cannot exceed the configured maximum of ₱'.number_format((float) $maximum, 2).'.'
                : 'The final price exceeds the supported monetary limit.',
        ])->validate();
        return $price;
    }

    private function tierPrice(ClinicService $service, ClinicAppointment $appointment, ?string &$size): mixed
    {
        $pet = $appointment->pet;
        $weight = $appointment->vitals?->weight_kg;
        if (! PetWeightSize::isValidWeight($weight)) {
            throw ValidationException::withMessages(['weight_kg' => "Record the pet's weight in Vitals before performing this service."]);
        }
        $size = PetWeightSize::sizeFor($pet?->species, $weight);
        if ($size === null) {
            throw ValidationException::withMessages(['clinic_service_id' => 'The patient species cannot be used with the configured pet-size pricing rules.']);
        }
        if ($service->tier_basis !== 'pet_size') return null;
        $price = collect($service->tiers)->firstWhere('size', $size)['price'] ?? null;
        if ($price === null) {
            throw ValidationException::withMessages(['clinic_service_id' => 'The catalogue has no configured price for the derived '.str_replace('_', ' ', $size).' size.']);
        }
        return $price;
    }

    public function preview(ClinicService $service, ClinicAppointment $appointment): array
    {
        $variable = in_array($service->pricing_method, ['range', 'starting_at'], true);
        $preview = [
            'requires_final_price' => $variable,
            'min_price' => $variable ? ($service->pricing_method === 'range' ? $service->min_price : $service->price) : null,
            'max_price' => $service->pricing_method === 'range' ? $service->max_price : null,
            'technical_max_price' => ClinicService::MAX_PRICE,
            'resolved_price' => null, 'pet_weight_kg' => null, 'pet_size' => null, 'error' => null,
        ];
        if ($variable) return $preview;
        try {
            $resolved = $this->resolve($service, $appointment);
            $preview['resolved_price'] = $resolved['price'];
            $preview['pet_weight_kg'] = $resolved['snapshot']['pet_weight_kg'];
            $preview['pet_size'] = $resolved['snapshot']['pet_size'];
        } catch (ValidationException $error) {
            $preview['error'] = collect($error->errors())->flatten()->first();
        }
        return $preview;
    }
}
