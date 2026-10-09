<?php

namespace Tests\Unit;

use App\Services\GroomingTimeEstimate;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GroomingTimeEstimateTest extends TestCase
{
    #[DataProvider('fullPackageEstimates')]
    public function test_complete_package_ranges_scale_by_size(string $package, ?string $preference, string $size, array $range): void
    {
        $engine = app(GroomingTimeEstimate::class);
        $estimate = $engine->calculate($package, $preference, $size);
        $this->assertSame($range, [$estimate['minMinutes'], $estimate['maxMinutes']]);
        $this->assertSame($preference, $estimate['preference']);
        foreach (array_keys(config('grooming_estimates.factors')) as $factor) {
            $extended = $engine->calculate($package, $preference, $size, [], [$factor]);
            $this->assertGreaterThanOrEqual($range[0], $extended['minMinutes']);
            $this->assertGreaterThanOrEqual($range[1], $extended['maxMinutes']);
        }
    }

    public static function fullPackageEstimates(): array
    {
        $packages = [
            ['partial_grooming', null, [[45, 60], [60, 75], [75, 90], [90, 105]]],
            ['regular_dog_grooming', 'summer_cut', [[75, 75], [75, 90], [90, 105], [105, 120]]],
            ['regular_dog_grooming', 'kalbo', [[75, 75], [75, 90], [90, 105], [105, 120]]],
            ['regular_dog_grooming', 'semi_kalbo', [[75, 90], [90, 90], [105, 120], [120, 120]]],
            ['regular_dog_grooming', 'regular_trim', [[90, 90], [90, 105], [120, 120], [120, 150]]],
            ['deluxe_dog_grooming', 'puppy_cut', [[90, 105], [105, 120], [120, 150], [150, 180]]],
            ['deluxe_dog_grooming', 'custom_hairstyle', [[120, 150], [135, 180], [150, 210], [180, 240]]],
            ['bath_and_go', null, [[45, 45], [60, 60], [75, 75], [90, 90]]],
            ['cat_full_grooming', null, [[90, 90], [105, 105]]],
        ];
        $cases = [];
        foreach ($packages as [$package, $preference, $ranges]) {
            foreach ($ranges as $index => $range) {
                $size = ['small', 'medium', 'large', 'extra_large'][$index];
                $cases[$package.'/'.($preference ?? '').'/'.$size] = [$package, $preference, $size, $range];
            }
        }
        return $cases;
    }

    public function test_duration_formatting_uses_hours_and_minutes(): void
    {
        $engine = app(GroomingTimeEstimate::class);
        $this->assertSame('1 hr 15 min', $engine->format(75, 75));
        $this->assertSame('1 hr 12 min', $engine->format(72, 72));
        $this->assertSame('1 hr–1 hr 30 min', $engine->format(60, 90));
        $this->assertSame('2–4 hrs', $engine->format(120, 240));
    }

    public function test_included_tasks_are_not_counted_twice_and_factors_extend_the_workflow(): void
    {
        $engine = app(GroomingTimeEstimate::class);
        $ordinary = $engine->calculate('regular_dog_grooming', 'summer_cut', 'small', ['nail_clipping', 'ear_cleaning', 'tooth_brushing']);
        $this->assertSame([75, 75], [$ordinary['minMinutes'], $ordinary['maxMinutes']]);
        $facial = $engine->calculate('regular_dog_grooming', 'summer_cut', 'small', ['facial_trimming']);
        $this->assertSame([90, 105], [$facial['minMinutes'], $facial['maxMinutes']]);
        $this->assertSame('1 hr 30 min–1 hr 45 min', $facial['formatted']);
        $additional = $engine->calculate('regular_dog_grooming', 'summer_cut', 'small', ['anal_sac_draining']);
        $this->assertSame([90, 95], [$additional['minMinutes'], $additional['maxMinutes']]);
        $alaCarte = $engine->calculate(null, null, 'small', ['nail_clipping', 'ear_cleaning', 'nail_clipping']);
        $this->assertSame([20, 30], [$alaCarte['minMinutes'], $alaCarte['maxMinutes']]);
        foreach (array_keys(config('grooming_estimates.factors')) as $factor) {
            $extended = $engine->calculate('regular_dog_grooming', 'summer_cut', 'small', [], [$factor]);
            $this->assertSame([120, 240], [$extended['minMinutes'], $extended['maxMinutes']]);
        }
        $custom = $engine->calculate('deluxe_dog_grooming', 'custom_hairstyle', 'medium');
        $this->assertSame([135, 180], [$custom['minMinutes'], $custom['maxMinutes']]);
        $long = $engine->calculate('deluxe_dog_grooming', 'custom_hairstyle', 'extra_large', ['facial_trimming'], ['detailed_styling']);
        $this->assertSame([195, 270], [$long['minMinutes'], $long['maxMinutes']]);
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

    public function test_cat_base_estimates_and_staff_extensions(): void
    {
        $engine = app(GroomingTimeEstimate::class);
        foreach (['small' => '1 hr 30 min', 'medium' => '1 hr 45 min'] as $size => $formatted) {
            $estimate = $engine->calculate('cat_full_grooming', null, $size);
            $this->assertSame($formatted, $estimate['formatted']);
            $this->assertNull($estimate['preference']);
            foreach (array_keys(config('grooming_estimates.factors')) as $factor) {
                $extended = $engine->calculate('cat_full_grooming', null, $size, [], [$factor]);
                $this->assertSame([120, 240], [$extended['minMinutes'], $extended['maxMinutes']]);
            }
        }
    }
}
