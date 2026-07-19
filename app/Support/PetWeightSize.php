<?php

namespace App\Support;

final class PetWeightSize
{
    private const MAX_WEIGHT_KG = 155.6;

    private const PROFILES = [
        'dog' => [
            ['size' => 'small', 'min' => 4.0, 'max' => 10.0],
            ['size' => 'medium', 'min' => 11.0, 'max' => 25.0],
            ['size' => 'large', 'min' => 26.0, 'max' => 50.0],
            ['size' => 'extra_large', 'min' => 51.0, 'max' => 70.0],
        ],
        'cat' => [
            ['size' => 'small', 'min' => 2.0, 'max' => 4.0],
            ['size' => 'medium', 'min' => 5.0, 'max' => 8.0],
        ],
    ];

    public static function sizeFor(mixed $species, mixed $weight): ?string
    {
        if ($weight === null || $weight === '' || ! is_numeric($weight)) {
            return null;
        }

        $profile = self::profileFor($species);
        $numericWeight = (float) $weight;

        foreach ($profile as $range) {
            if ($numericWeight >= $range['min'] && $numericWeight <= $range['max']) {
                return $range['size'];
            }
        }

        return null;
    }

    public static function validationMessage(mixed $species, mixed $weight = null): string
    {
        if (is_numeric($weight) && ((float) $weight <= 0 || (float) $weight > self::MAX_WEIGHT_KG)) {
            return 'Weight must be greater than 0 kg and no more than 155.6 kg.';
        }

        return match (self::normalizeSpecies($species)) {
            'dog' => 'Dog weight must be within an accepted range: Small 4–10 kg, Medium 11–25 kg, Large 26–50 kg, or Extra Large 51–70 kg.',
            'cat' => 'Cat weight must be within an accepted range: Small 2–4 kg or Medium 5–8 kg.',
            default => 'Select Dog or Cat before entering a weight.',
        };
    }

    public static function withComputedSize(array $pet): array
    {
        if (! empty($pet['size'])
            || ! array_key_exists('weight', $pet)
            || $pet['weight'] === null
            || $pet['weight'] === '') {
            return $pet;
        }

        $size = self::sizeFor($pet['species'] ?? null, $pet['weight']);

        if ($size !== null) {
            $pet['size'] = $size;
        }

        return $pet;
    }

    public static function isValidSize(mixed $species, mixed $size): bool
    {
        $normalizedSize = strtolower(trim((string) $size));

        foreach (self::profileFor($species) as $range) {
            if ($range['size'] === $normalizedSize) {
                return true;
            }
        }

        return false;
    }

    private static function profileFor(mixed $species): array
    {
        return self::PROFILES[self::normalizeSpecies($species)] ?? [];
    }

    private static function normalizeSpecies(mixed $species): string
    {
        return strtolower(trim((string) $species));
    }
}
