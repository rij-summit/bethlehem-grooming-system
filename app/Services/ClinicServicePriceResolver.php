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
                'name' => $service->name, 'category' => $service->category->name, 'type' => $service->type,
                'pricing_method' => $service->pricing_method, 'billing_unit' => $service->billing_unit,
                'configured_price' => $service->price, 'min_price' => $service->min_price,
                'max_price' => $service->max_price, 'tier_basis' => $service->tier_basis,
                'pet_size' => $size,
            ],
        ];
    }

    private function variablePrice(ClinicService $service, mixed $price): mixed
    {
        $minimum = $service->pricing_method === 'range' ? $service->min_price : $service->price;
        $maximum = $service->pricing_method === 'range' ? $service->max_price : ClinicService::MAX_PRICE;
        Validator::make(['applied_price' => $price], [
            'applied_price' => ['required', 'numeric', 'decimal:0,2', 'min:'.$minimum, 'max:'.$maximum],
        ])->validate();
        return $price;
    }

    private function tierPrice(ClinicService $service, ClinicAppointment $appointment, ?string &$size): mixed
    {
        $pet = $appointment->pet;
        // Use the established verified-size / weight-derived-size rules, with current case vitals.
        $size = $pet?->hasClinicVerifiedSize() && $pet->size ? $pet->size
            : (PetWeightSize::sizeFor($pet?->species, $appointment->vitals?->weight_kg ?? $pet?->weight) ?? $pet?->size);
        if ($service->tier_basis !== 'pet_size') return null;
        return collect($service->tiers)->firstWhere('size', $size)['price'] ?? null;
    }
}
