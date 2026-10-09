<?php

return [
    'preferences' => [
        'regular_dog_grooming' => ['summer_cut' => 'Summer Cut', 'semi_kalbo' => 'Semi-Kalbo', 'kalbo' => 'Kalbo', 'regular_trim' => 'Regular Trim'],
        'deluxe_dog_grooming' => ['puppy_cut' => 'Puppy Cut', 'custom_hairstyle' => 'Custom Hairstyle'],
    ],
    'factors' => ['thick_coat' => 'Thick coat', 'matted_tangled' => 'Matted / tangled', 'extra_handling' => 'Extra handling', 'detailed_styling' => 'Detailed styling'],
    'extended' => [120, 240],
    'packages' => [
        'partial_grooming' => ['sizes' => ['small' => [30, 45], 'medium' => [45, 60], 'large' => [60, 75], 'extra_large' => [60, 90]], 'included' => ['nail_clipping', 'ear_cleaning', 'facial_trimming']],
        'regular_dog_grooming' => ['cuts' => [
            'summer_cut' => ['small' => [30, 30], 'medium' => [30, 45], 'large' => [45, 60], 'extra_large' => [60, 90]],
            'kalbo' => ['small' => [30, 30], 'medium' => [30, 45], 'large' => [45, 60], 'extra_large' => [60, 90]],
            'semi_kalbo' => ['small' => [45, 60], 'medium' => [45, 60], 'large' => [60, 90], 'extra_large' => [90, 120]],
            'regular_trim' => ['small' => [60, 90], 'medium' => [60, 90], 'large' => [120, 120], 'extra_large' => [120, 120]],
        ], 'included' => ['nail_clipping', 'ear_cleaning', 'tooth_brushing', 'facial_trimming']],
        'deluxe_dog_grooming' => ['cuts' => [
            'puppy_cut' => ['small' => [60, 90], 'medium' => [60, 90], 'large' => [120, 120], 'extra_large' => [120, 120]],
            'custom_hairstyle' => ['small' => [120, 180], 'medium' => [120, 180], 'large' => [120, 240], 'extra_large' => [120, 240]],
        ], 'included' => ['nail_clipping', 'ear_cleaning', 'tooth_brushing', 'facial_trimming']],
        'bath_and_go' => ['sizes' => ['small' => [45, 45], 'medium' => [60, 60], 'large' => [75, 75], 'extra_large' => [90, 90]], 'included' => ['nail_clipping', 'ear_cleaning', 'tooth_brushing']],
        'cat_full_grooming' => ['sizes' => ['small' => [90, 120], 'medium' => [120, 150]], 'included' => ['nail_clipping', 'ear_cleaning', 'facial_trimming']],
    ],
    'ala_carte' => ['nail_clipping' => [10, 15], 'ear_cleaning' => [10, 15], 'facial_trimming' => [15, 30], 'anal_sac_draining' => [10, 15], 'tooth_brushing' => [5, 10]],
];
