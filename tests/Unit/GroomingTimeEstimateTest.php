<?php

namespace Tests\Unit;

use App\Services\GroomingTimeEstimate;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GroomingTimeEstimateTest extends TestCase
{
    public function test_all_configured_sizes_and_preferences_use_their_ranges(): void
    {
        $engine = app(GroomingTimeEstimate::class);
        foreach (config('grooming_estimates.packages') as $package => $metadata) {
            foreach ($metadata['cuts'] ?? ['' => $metadata['sizes']] as $preference => $sizes) {
                foreach ($sizes as $size => $range) {
                    $estimate = $engine->calculate($package, $preference ?: null, $size);
                    $this->assertSame($range, [$estimate['minMinutes'], $estimate['maxMinutes']]);
                    $this->assertSame($preference ?: null, $estimate['preference']);
                }
            }
        }
        $this->assertSame('1 hr 15 min', $engine->format(75, 75));
        $this->assertSame('1 hr 12 min', $engine->format(72, 72));
        $this->assertSame('1 hr–1 hr 30 min', $engine->format(60, 90));
        $this->assertSame('2–4 hrs', $engine->format(120, 240));
    }

    public function test_included_tasks_are_not_counted_twice_and_factors_extend_the_workflow(): void
    {
        $engine = app(GroomingTimeEstimate::class);
        $ordinary = $engine->calculate('regular_dog_grooming', 'summer_cut', 'small', ['nail_clipping', 'ear_cleaning', 'facial_trimming']);
        $this->assertSame([30, 30], [$ordinary['minMinutes'], $ordinary['maxMinutes']]);
        $additional = $engine->calculate('regular_dog_grooming', 'summer_cut', 'small', ['anal_sac_draining']);
        $this->assertSame([40, 45], [$additional['minMinutes'], $additional['maxMinutes']]);
        $alaCarte = $engine->calculate(null, null, 'small', ['nail_clipping', 'ear_cleaning', 'nail_clipping']);
        $this->assertSame([20, 30], [$alaCarte['minMinutes'], $alaCarte['maxMinutes']]);
        foreach (array_keys(config('grooming_estimates.factors')) as $factor) {
            $extended = $engine->calculate('regular_dog_grooming', 'summer_cut', 'small', [], [$factor]);
            $this->assertSame([120, 240], [$extended['minMinutes'], $extended['maxMinutes']]);
        }
        $custom = $engine->calculate('deluxe_dog_grooming', 'custom_hairstyle', 'medium');
        $this->assertSame([120, 180], [$custom['minMinutes'], $custom['maxMinutes']]);
    }

    public function test_new_haircut_visits_require_a_valid_preference(): void
    {
        foreach ([['regular_dog_grooming', null], ['regular_dog_grooming', 'puppy_cut'],
            ['regular_dog_grooming', 'custom_hairstyle'], ['deluxe_dog_grooming', 'summer_cut'], ['cat_full_grooming', 'kalbo']] as [$package, $preference]) {
            try {
                app(GroomingTimeEstimate::class)->calculate($package, $preference, 'small');
                $this->fail('Invalid grooming preference was accepted.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('grooming_preference', $error->errors());
            }
        }
    }

    public function test_cat_sizes_and_staff_factors_are_validated(): void
    {
        $engine = app(GroomingTimeEstimate::class);
        foreach ([['cat_full_grooming', 'large', []], ['partial_grooming', 'small', ['unknown']]] as [$package, $size, $factors]) {
            try {
                $engine->calculate($package, null, $size, [], $factors);
                $this->fail('Invalid estimate input was accepted.');
            } catch (ValidationException $error) {
                $this->assertNotEmpty($error->errors());
            }
        }
    }
}
