<?php

namespace Tests\Unit;

use App\Rules\ValidBreedCoat;
use App\Support\BreedCoatCatalog;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class BreedCoatCatalogTest extends TestCase
{
    public function test_every_catalogue_breed_has_direct_coat_options(): void
    {
        $catalogue = BreedCoatCatalog::all();

        $this->assertSame(['Dog', 'Cat'], array_keys($catalogue));

        foreach ($catalogue as $breeds) {
            foreach ($breeds as $breed => $options) {
                $this->assertNotEmpty($options, "{$breed} must have coat options.");
                $this->assertSame(array_values($options), array_values(array_unique($options)));

                foreach ($options as $option) {
                    $this->assertIsString($option);
                    $this->assertSame($option, trim($option));
                }
            }
        }
    }

    public function test_siberian_husky_exposes_only_direct_requested_coats(): void
    {
        $this->assertSame(
            ['Standard Coat', 'Wooly Coat'],
            BreedCoatCatalog::optionsFor('Siberian Husky', 'Dog'),
        );
        $this->assertNotContains(
            'Hairless',
            BreedCoatCatalog::optionsFor('Siberian Husky', 'Dog'),
        );
    }

    public function test_impossible_breed_and_coat_combination_is_rejected(): void
    {
        $validator = Validator::make([
            'pets' => [[
                'species' => 'Dog',
                'breed' => 'Siberian Husky',
                'fur_type' => 'Hairless',
            ]],
        ], [
            'pets.*.fur_type' => [new ValidBreedCoat],
        ]);

        $this->assertTrue($validator->fails());
        $this->assertSame(
            'The selected fur type is not available for this breed.',
            $validator->errors()->first('pets.0.fur_type'),
        );
    }

    public function test_valid_direct_coat_option_is_accepted_for_nested_pet(): void
    {
        $validator = Validator::make([
            'pets' => [[
                'species' => 'Dog',
                'breed' => 'Siberian Husky',
                'fur_type' => 'Wooly Coat',
            ]],
        ], [
            'pets.*.fur_type' => [new ValidBreedCoat],
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_valid_direct_coat_option_is_accepted_for_clinic_pet(): void
    {
        $validator = Validator::make([
            'species' => 'Cat',
            'breed' => 'Sphynx',
            'fur_type' => 'Fine Down Coat',
        ], [
            'fur_type' => [new ValidBreedCoat],
        ]);

        $this->assertFalse($validator->fails());
    }
}
