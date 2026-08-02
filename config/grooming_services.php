<?php

return [
    /*
     * Server-side grooming price catalogue.
     *
     * These values mirror the customer and staff grooming catalogues. They are
     * used whenever an imported/legacy services row has no usable database
     * price, and they provide the booking snapshot for extra-large packages
     * because the current services table has no price_extra_large column.
     */
    'services' => [
        'partial_grooming' => [
            'kind' => 'package',
            'prices' => [
                'small' => '400.00',
                'medium' => '500.00',
                'large' => '600.00',
                'extra_large' => '700.00',
            ],
            'default' => '500.00',
        ],
        'regular_dog_grooming' => [
            'kind' => 'package',
            'prices' => [
                'small' => '550.00',
                'medium' => '650.00',
                'large' => '850.00',
                'extra_large' => '1050.00',
            ],
            'default' => '650.00',
        ],
        'deluxe_dog_grooming' => [
            'kind' => 'package',
            'prices' => [
                'small' => '650.00',
                'medium' => '750.00',
                'large' => '1000.00',
                'extra_large' => '1200.00',
            ],
            'default' => '750.00',
        ],
        'bath_and_go' => [
            'kind' => 'package',
            'prices' => [
                'small' => '450.00',
                'medium' => '550.00',
                'large' => '650.00',
                'extra_large' => '750.00',
            ],
            'default' => '550.00',
        ],
        'cat_full_grooming' => [
            'kind' => 'package',
            'prices' => [
                'small' => '500.00',
                'medium' => '600.00',
            ],
            'default' => '550.00',
        ],
        'nail_clipping' => [
            'kind' => 'ala_carte',
            // Standard snapshot inside the displayed PHP 50-100 range.
            'default' => '75.00',
        ],
        'ear_cleaning' => [
            'kind' => 'ala_carte',
            'default' => '150.00',
        ],
        'facial_trimming' => [
            'kind' => 'ala_carte',
            'default' => '150.00',
        ],
        'anal_sac_draining' => [
            'kind' => 'ala_carte',
            'default' => '150.00',
        ],
        'tooth_brushing' => [
            'kind' => 'ala_carte',
            'default' => '100.00',
        ],
    ],
];
