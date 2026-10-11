<?php

namespace App\Support;

final class ProductWeightPricing
{
    /** Numeric and money validation is performed by the request validator. */
    public static function configurationError(array $ranges): ?string
    {
        if (! $ranges) return 'Add at least one weight-price tier.';
        if ((float) $ranges[0]['min_weight_kg'] !== 0.0) return 'The first tier must cover weights below the next starting weight.';
        foreach ($ranges as $index => $range) {
            $min = (float) $range['min_weight_kg'];
            $max = $range['max_weight_kg'] ?? null;
            if ($index && ($min <= 0 || $min <= (float) $ranges[$index - 1]['min_weight_kg'])) {
                return 'Starting weights must be positive and increase with each tier.';
            }
            if ($max !== null && (float) $max <= $min) return 'Maximum weight must be greater than the tier starting weight.';
            if (isset($ranges[$index + 1]) && ($max === null || (float) $max !== (float) $ranges[$index + 1]['min_weight_kg'])) {
                return 'Each tier must end at the next tier starting weight.';
            }
        }
        return null;
    }

    public static function rangeFor(array $ranges, mixed $weight, string $mode = 'threshold'): ?array
    {
        if (! is_numeric($weight) || ! is_finite((float) $weight) || (float) $weight <= 0) return null;
        $matches = [];
        foreach ($ranges as $index => $range) {
            $max = $range['max_weight_kg'];
            $inclusive = $mode === 'legacy' || $index === array_key_last($ranges);
            if ((float) $weight >= (float) $range['min_weight_kg']
                && ($max === null || ($inclusive ? (float) $weight <= (float) $max : (float) $weight < (float) $max))) {
                $matches[] = $range;
            }
        }
        return count($matches) === 1 ? $matches[0] : null;
    }
}
