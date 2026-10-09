<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$engine = app(App\Services\GroomingTimeEstimate::class);
$cases = [];
foreach (config('grooming_estimates.packages') as $package => $metadata) {
    foreach ($metadata['cuts'] ?? ['' => $metadata['sizes']] as $preference => $sizes) {
        foreach (array_keys($sizes) as $size) {
            foreach ([[], ['extra_handling'], ['thick_coat', 'detailed_styling']] as $factors) {
                foreach ([[], ['nail_clipping'], ['ear_cleaning'], ['facial_trimming'], ['anal_sac_draining'], ['tooth_brushing'],
                    ['nail_clipping', 'facial_trimming', 'anal_sac_draining'], ['facial_trimming', 'facial_trimming']] as $alaCarte) {
                    $cases[] = ['selection' => ['servicePackage' => $package, 'groomingPreference' => $preference,
                        'alaCarteServices' => $alaCarte, 'estimateFactors' => $factors], 'size' => $size,
                        'estimate' => $engine->calculate($package, $preference ?: null, $size, $alaCarte, $factors)];
                }
            }
        }
    }
}
echo json_encode(['rules' => config('grooming_estimates'), 'cases' => $cases], JSON_THROW_ON_ERROR);
