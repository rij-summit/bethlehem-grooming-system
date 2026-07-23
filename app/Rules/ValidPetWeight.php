<?php

namespace App\Rules;

use App\Support\PetWeightSize;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidPetWeight implements DataAwareRule, ValidationRule
{
    private array $data = [];

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return;
        }

        $species = $this->relatedSpecies($attribute);

        if (PetWeightSize::sizeFor($species, $value) === null) {
            $fail(PetWeightSize::validationMessage($species, $value));
        }
    }

    private function relatedSpecies(string $attribute): mixed
    {
        if (preg_match('/^pets\.(\d+)\.weight$/', $attribute, $matches)) {
            return $this->data['pets'][(int) $matches[1]]['species'] ?? null;
        }

        return $this->data['species'] ?? null;
    }
}
