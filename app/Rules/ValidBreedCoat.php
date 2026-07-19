<?php

namespace App\Rules;

use App\Support\BreedCoatCatalog;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidBreedCoat implements DataAwareRule, ValidationRule
{
    private array $data = [];

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || ! is_string($value)) {
            return;
        }

        [$breed, $species] = $this->relatedBreedAndSpecies($attribute);
        $options = BreedCoatCatalog::optionsFor($breed, $species);

        if (! in_array($value, $options, true)) {
            $fail('The selected fur type is not available for this breed.');
        }
    }

    private function relatedBreedAndSpecies(string $attribute): array
    {
        if (preg_match('/^pets\.(\d+)\.fur_type$/', $attribute, $matches)) {
            $pet = $this->data['pets'][(int) $matches[1]] ?? [];

            return [$pet['breed'] ?? null, $pet['species'] ?? null];
        }

        return [$this->data['breed'] ?? null, $this->data['species'] ?? null];
    }
}
