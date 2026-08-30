<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        $services = [
            // Dog packages
            [
                'service_name' => 'Partial Grooming',
                'slug' => 'partial_grooming',
                'description' => 'Trimming, Nail Clipping, Ear Cleaning, Cologne Spritz and Dry Shampoo',
                'base_price' => 500,
                'price_small' => 400,
                'price_medium' => 500,
                'price_large' => 600,
                'price_extra_large' => 700,
            ],
            [
                'service_name' => 'Regular Dog Grooming',
                'slug' => 'regular_dog_grooming',
                'description' => 'Bathing with Shampoo and Blow Drying, Haircut, Nail Clipping, Ear Cleaning and Tooth-brushing',
                'base_price' => 650,
                'price_small' => 550,
                'price_medium' => 650,
                'price_large' => 850,
                'price_extra_large' => 1050,
            ],
            [
                'service_name' => 'Deluxe Dog Grooming',
                'slug' => 'deluxe_dog_grooming',
                'description' => 'Bathing with Shampoo and Blow Drying, Special Haircut, Nail Clipping, Ear Cleaning, Tooth-brushing and Cologne',
                'base_price' => 750,
                'price_small' => 650,
                'price_medium' => 750,
                'price_large' => 1000,
                'price_extra_large' => 1200,
            ],
            [
                'service_name' => 'Bath and Go!',
                'slug' => 'bath_and_go',
                'description' => 'Bathing with Shampoo and Blow Drying, Nail Clipping, Ear Cleaning and Tooth-brushing',
                'base_price' => 550,
                'price_small' => 450,
                'price_medium' => 550,
                'price_large' => 650,
                'price_extra_large' => 750,
            ],
            // Cat package
            [
                'service_name' => 'Full Grooming',
                'slug' => 'cat_full_grooming',
                'description' => 'Bathing with Shampoo and Blow Drying, Haircut (if requested), Nail Clipping and Ear Cleaning',
                'base_price' => 550,
                'price_small' => 500,
                'price_medium' => 600,
                'price_large' => null,
                'price_extra_large' => null,
            ],
            // Cat a la carte
            [
                'service_name' => 'Nail Clipping',
                'slug' => 'nail_clipping',
                'description' => null,
                'base_price' => 75,
                'price_min' => 50,
                'price_max' => 100,
                'price_small' => null,
                'price_medium' => null,
                'price_large' => null,
            ],
            [
                'service_name' => 'Ear Cleaning',
                'slug' => 'ear_cleaning',
                'description' => null,
                'base_price' => 150,
                'is_starting_price' => true,
                'price_small' => null,
                'price_medium' => null,
                'price_large' => null,
            ],
            [
                'service_name' => 'Facial Trimming',
                'slug' => 'facial_trimming',
                'description' => null,
                'base_price' => 150,
                'price_small' => null,
                'price_medium' => null,
                'price_large' => null,
            ],
            [
                'service_name' => 'Anal Sac Draining',
                'slug' => 'anal_sac_draining',
                'description' => null,
                'base_price' => 150,
                'price_small' => null,
                'price_medium' => null,
                'price_large' => null,
            ],
            [
                'service_name' => 'Tooth Brushing',
                'slug' => 'tooth_brushing',
                'description' => null,
                'base_price' => 100,
                'is_starting_price' => true,
                'price_small' => null,
                'price_medium' => null,
                'price_large' => null,
            ],
        ];

        foreach ($services as $service) {
            DB::table('services')->updateOrInsert(
                ['slug' => $service['slug']],
                array_merge($service, ['is_active' => true]),
            );
        }
    }
}
