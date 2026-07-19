<?php

namespace Tests\Unit;

use App\Rules\ValidPetSize;
use App\Rules\ValidPetWeight;
use App\Support\PetWeightSize;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PetWeightSizeTest extends TestCase
{
    #[DataProvider('acceptedWeights')]
    public function test_it_resolves_accepted_weights_to_the_expected_size(
        string $species,
        float $weight,
        string $expectedSize,
    ): void {
        $this->assertSame($expectedSize, PetWeightSize::sizeFor($species, $weight));
    }

    public static function acceptedWeights(): array
    {
        return [
            'small dog lower bound' => ['Dog', 4, 'small'],
            'small dog upper bound' => ['Dog', 10, 'small'],
            'medium dog example' => ['Dog', 20, 'medium'],
            'large dog upper bound' => ['Dog', 50, 'large'],
            'extra-large dog upper bound' => ['Dog', 70, 'extra_large'],
            'small cat example' => ['Cat', 3, 'small'],
            'medium cat lower bound' => ['Cat', 5, 'medium'],
            'medium cat upper bound' => ['Cat', 8, 'medium'],
        ];
    }

    #[DataProvider('rejectedWeights')]
    public function test_it_rejects_weights_outside_the_accepted_ranges(
        string $species,
        float $weight,
    ): void {
        $this->assertNull(PetWeightSize::sizeFor($species, $weight));
    }

    public static function rejectedWeights(): array
    {
        return [
            'dog below minimum' => ['Dog', 3.99],
            'zero weight' => ['Dog', 0],
            'dog between small and medium' => ['Dog', 10.5],
            'dog above maximum' => ['Dog', 70.01],
            'above absolute maximum' => ['Dog', 155.61],
            'cat below minimum' => ['Cat', 1.99],
            'cat between small and medium' => ['Cat', 4.5],
            'cat above maximum' => ['Cat', 8.01],
        ];
    }

    public function test_it_preserves_a_manually_selected_size(): void
    {
        $pet = PetWeightSize::withComputedSize([
            'species' => 'Dog',
            'weight' => 20,
            'size' => 'small',
        ]);

        $this->assertSame('small', $pet['size']);
    }

    public function test_it_computes_size_when_the_submission_omits_it(): void
    {
        $pet = PetWeightSize::withComputedSize([
            'species' => 'Dog',
            'weight' => 20,
        ]);

        $this->assertSame('medium', $pet['size']);
    }

    public function test_absolute_maximum_weight_uses_the_specific_validation_message(): void
    {
        $validator = Validator::make([
            'species' => 'Dog',
            'weight' => 155.61,
        ], [
            'weight' => ['numeric', new ValidPetWeight],
        ]);

        $this->assertTrue($validator->fails());
        $this->assertSame(
            'Weight must be greater than 0 kg and no more than 155.6 kg.',
            $validator->errors()->first('weight'),
        );
    }

    public function test_nested_pet_weight_validation_uses_its_species(): void
    {
        $validator = Validator::make([
            'pets' => [[
                'species' => 'Cat',
                'weight' => 20,
            ]],
        ], [
            'pets.*.weight' => ['numeric', new ValidPetWeight],
        ]);

        $this->assertTrue($validator->fails());
        $this->assertSame(
            PetWeightSize::validationMessage('Cat'),
            $validator->errors()->first('pets.0.weight'),
        );
    }

    public function test_single_pet_weight_validation_accepts_case_insensitive_species(): void
    {
        $validator = Validator::make([
            'species' => 'dog',
            'weight' => 51,
        ], [
            'weight' => ['numeric', new ValidPetWeight],
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_cat_size_validation_rejects_dog_only_sizes(): void
    {
        $validator = Validator::make([
            'pets' => [[
                'species' => 'Cat',
                'size' => 'large',
            ]],
        ], [
            'pets.*.size' => [new ValidPetSize],
        ]);

        $this->assertTrue($validator->fails());
    }

    public function test_dog_size_validation_accepts_manual_extra_large_selection(): void
    {
        $validator = Validator::make([
            'species' => 'Dog',
            'size' => 'extra_large',
        ], [
            'size' => [new ValidPetSize],
        ]);

        $this->assertFalse($validator->fails());
    }
}
