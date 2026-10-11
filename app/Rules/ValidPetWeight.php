<?php

namespace App\Rules;

use App\Support\PetWeightSize;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidPetWeight implements DataAwareRule, ValidationRule
{
    private array $data = [];

    public function __construct(private bool $requireSpecies = true) {}

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $species = $this->relatedSpecies($attribute);

        $message = PetWeightSize::weightValidationMessage($value);
        if ($message !== null) {
            $fail($message);
        } elseif ($this->requireSpecies && PetWeightSize::sizeFor($species, $value) === null) {
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
