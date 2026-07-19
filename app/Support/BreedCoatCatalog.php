<?php

namespace App\Support;

use RuntimeException;

final class BreedCoatCatalog
{
    private static ?array $catalogue = null;

    public static function all(): array
    {
        if (self::$catalogue !== null) {
            return self::$catalogue;
        }

        $path = base_path('scripts/data/breed-coat-options.json');
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new RuntimeException('Breed and coat options could not be loaded.');
        }

        $catalogue = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($catalogue)) {
            throw new RuntimeException('Breed and coat options are invalid.');
        }

        return self::$catalogue = $catalogue;
    }

    public static function optionsFor(?string $breed, ?string $species): array
    {
        $breed = trim((string) $breed);

        if ($breed === '') {
            return [];
        }

        $catalogue = self::all();
        $normalizedSpecies = strtolower(trim((string) $species));
        $speciesKey = match ($normalizedSpecies) {
            'dog' => 'Dog',
            'cat' => 'Cat',
            default => null,
        };
        $isGenericBreed = strcasecmp($breed, 'Mixed Breed / Aspin') === 0
            || strcasecmp($breed, 'Mixed Breed / Puspin') === 0
            || strcasecmp($breed, 'Unknown Breed') === 0;

        if ($speciesKey === 'Cat' && strcasecmp($breed, 'Mixed Breed / Aspin') === 0) {
            $breed = 'Mixed Breed / Puspin';
        }

        if ($isGenericBreed && $speciesKey === null) {
            return [];
        }

        $typesToSearch = $speciesKey ? [$speciesKey] : array_keys($catalogue);

        foreach ($typesToSearch as $type) {
            foreach ($catalogue[$type] ?? [] as $catalogueBreed => $options) {
                if (strcasecmp($catalogueBreed, $breed) === 0) {
                    return array_values($options);
                }
            }
        }

        return [];
    }
}
