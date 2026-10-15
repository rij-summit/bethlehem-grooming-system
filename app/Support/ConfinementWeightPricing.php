<?php

namespace App\Support;

use App\Models\ClinicService;
use Illuminate\Validation\ValidationException;

final class ConfinementWeightPricing
{
    public const SIZES = ['small', 'medium', 'large', 'extra_large'];

    public static function normalize(array $tiers): array
    {
        if (count($tiers) !== 4 || collect($tiers)->pluck('size')->sort()->values()->all() !== ['extra_large', 'large', 'medium', 'small']) {
            throw ValidationException::withMessages(['tiers' => 'Configure all four confinement sizes.']);
        }
        $ordered = [];
        $previous = 0;
        foreach (self::SIZES as $size) {
            $index = array_search($size, array_column($tiers, 'size'), true);
            $tier = $tiers[$index];
            $cutoff = $tier['max_weight_kg'] ?? null;
            if ($size === 'extra_large') {
                if ($cutoff !== null && $cutoff !== '') {
                    throw ValidationException::withMessages(["tiers.$index.max_weight_kg" => 'Extra Large has no upper weight limit.']);
                }
            } else {
                if (! is_numeric($cutoff) || ! is_finite((float) $cutoff) || (float) $cutoff <= 0) {
                    throw ValidationException::withMessages(["tiers.$index.max_weight_kg" => 'Enter a weight greater than 0 kg.']);
                }
                if ((float) $cutoff <= $previous) {
                    throw ValidationException::withMessages(["tiers.$index.max_weight_kg" => 'Each weight cutoff must be greater than the previous cutoff.']);
                }
                $previous = (float) $cutoff;
            }
            $price = $tier['price'] ?? null;
            if (! is_numeric($price) || ! is_finite((float) $price) || $price < 0 || $price > ClinicService::MAX_PRICE
                || ! preg_match('/^(?:\d+\.?\d{0,2}|\.\d{1,2})$/D', (string) $price)) {
                throw ValidationException::withMessages(["tiers.$index.price" => 'Enter a valid price with no more than 2 decimal places.']);
            }
            $ordered[] = ['size' => $size, 'max_weight_kg' => $size === 'extra_large' ? null : (float) $cutoff,
                'price' => number_format((float) $price, 2, '.', '')];
        }
        return $ordered;
    }

    public static function tierFor(array $tiers, mixed $weight): array
    {
        if (! is_numeric($weight) || ! is_finite((float) $weight) || (float) $weight <= 0) {
            throw ValidationException::withMessages(['weight_kg' => "Record the patient's weight in Medical Record first."]);
        }
        foreach (self::normalize($tiers) as $tier) {
            if ($tier['max_weight_kg'] === null || (float) $weight <= $tier['max_weight_kg']) return $tier;
        }
    }
}
