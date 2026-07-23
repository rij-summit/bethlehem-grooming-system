<?php

namespace App\Rules;

use App\Support\PetWeightSize;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidPetSize implements DataAwareRule, ValidationRule
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

        if (! PetWeightSize::isValidSize($this->relatedSpecies($attribute), $value)) {
            $fail('The selected size is not available for this pet type.');
        }
    }

    private function relatedSpecies(string $attribute): mixed
    {
        if (preg_match('/^pets\.(\d+)\.size$/', $attribute, $matches)) {
            return $this->data['pets'][(int) $matches[1]]['species'] ?? null;
        }

        return $this->data['species'] ?? null;
    }
}
