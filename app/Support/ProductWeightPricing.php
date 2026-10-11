<?php

namespace App\Support;

final class ProductWeightPricing
{
    /** Numeric and money validation is performed by the request validator. */
    public static function configurationError(array $ranges, string $mode = 'threshold'): ?string
    {
        if (! $ranges) return 'Add at least one weight range.';
        if ($mode === 'catalog') {
            foreach ($ranges as $index => $range) {
                $min = (float) $range['min_weight_kg'];
                $max = $range['max_weight_kg'];
                $band = $range['band'] ?? ($min === 0.0 ? 'below' : ($max === null ? 'above' : 'normal'));
                $next = $ranges[$index + 1] ?? null;
                if ($band === 'below') {
                    if ($index !== 0 || $min !== 0.0 || $max === null) return 'Keep one Below range first.';
                    if ($next && (float) $max > (float) $next['min_weight_kg']) return 'Below threshold cannot pass the next range.';
                } elseif ($band === 'above') {
                    if ($index !== array_key_last($ranges) || $min <= 0 || $max !== null) return 'Keep one Above range last.';
                    $previous = $ranges[$index - 1] ?? null;
                    if ($previous && $min < (float) $previous['max_weight_kg']) return 'Above threshold cannot precede the last maximum.';
                } else {
                    if ($min <= 0 || $max === null) return 'Enter a minimum and maximum weight.';
                    if ((float) $max <= $min) return 'Maximum must be greater than minimum.';
                    $previous = $ranges[$index - 1] ?? null;
                    if ($previous && (float) $previous['min_weight_kg'] > 0 && $previous['max_weight_kg'] !== null
                        && round($min * 100) < round((float) $previous['max_weight_kg'] * 100) + 10) {
                        $previousMax = (float) $previous['max_weight_kg'];
                        $minimum = (round($previousMax * 100) + 10) / 100;
                        return 'Must be greater than '.$previousMax.' kg. Minimum allowed: '.$minimum.' kg.';
                    }
                    if ($index && $min <= (float) $ranges[$index - 1]['min_weight_kg']) return 'Minimum values must increase.';
                    if ($next && (float) $max > (float) $next['min_weight_kg']) return 'Maximum cannot pass the next range.';
                }
            }
            return null;
        }
        foreach ($ranges as $index => $range) {
            $min = (float) $range['min_weight_kg'];
            $max = $range['max_weight_kg'] ?? null;
            if ($mode !== 'threshold') {
                if ($min <= 0 || $max === null) return 'Enter a positive From and To weight for every range.';
                if ((float) $max <= $min) return 'To weight must be greater than From weight.';
                if ($index && $min <= (float) $ranges[$index - 1]['max_weight_kg']) {
                    return 'Weight ranges must be in ascending order and must not overlap.';
                }
                continue;
            }
            if ($min < 0 || ($index && $min <= (float) $ranges[$index - 1]['min_weight_kg'])) {
                return 'Starting weights must be positive and increase with each band.';
            }
            if ($min === 0.0 && $max === null) return 'Enter a positive weight for the Below band.';
            if ($max !== null && (float) $max <= $min) return 'Maximum weight must be greater than the starting weight.';
            if (isset($ranges[$index + 1]) && ($max === null || (float) $max !== (float) $ranges[$index + 1]['min_weight_kg'])) {
                return 'Each band must end at the next starting weight.';
            }
        }
        return null;
    }

    public static function rangeFor(array $ranges, mixed $weight, string $mode = 'threshold'): ?array
    {
        if (! is_numeric($weight) || ! is_finite((float) $weight) || (float) $weight <= 0) return null;
        $matches = [];
        foreach ($ranges as $index => $range) {
            if ($mode === 'catalog') {
                $min = (float) $range['min_weight_kg'];
                $max = $range['max_weight_kg'];
                $next = $ranges[$index + 1] ?? null;
                $inclusive = true;
                if ($min === 0.0) {
                    $match = $max !== null && (float) $weight < (float) $max;
                } elseif ($max === null) {
                    $match = (float) $weight > $min;
                } else {
                    if ($next) {
                        $max = $next['min_weight_kg'];
                        $inclusive = $next['max_weight_kg'] === null;
                    }
                    $match = (float) $weight >= $min && ($inclusive ? (float) $weight <= (float) $max : (float) $weight < (float) $max);
                }
                if ($match) $matches[] = [...$range, 'max_weight_kg' => $max];
                continue;
            }
            $final = $index === array_key_last($ranges);
            $max = $mode === 'threshold' && ! $final ? $ranges[$index + 1]['min_weight_kg'] : $range['max_weight_kg'];
            $inclusive = $mode !== 'threshold' || ($final && (float) $range['min_weight_kg'] > 0);
            $open = $mode === 'threshold' && $final && (float) $range['min_weight_kg'] > 0 && $max === null;
            if ((float) $weight >= (float) $range['min_weight_kg']
                && ($open || ($max !== null && ($inclusive ? (float) $weight <= (float) $max : (float) $weight < (float) $max)))) {
                $matches[] = [...$range, 'max_weight_kg' => $max];
            }
        }
        return count($matches) === 1 ? $matches[0] : null;
    }
}
