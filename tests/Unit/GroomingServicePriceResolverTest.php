<?php

namespace Tests\Unit;

use App\Models\BookingService;
use App\Models\Service;
use App\Services\GroomingServicePriceResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GroomingServicePriceResolverTest extends TestCase
{
    #[DataProvider('catalogPrices')]
    public function test_zero_catalog_rows_resolve_the_size_and_ala_carte_snapshot(
        string $slug,
        ?string $size,
        string $expected,
    ): void {
        $service = new Service([
            'slug' => $slug,
            'base_price' => 0,
            'price_small' => null,
            'price_medium' => null,
            'price_large' => null,
        ]);

        $this->assertSame(
            $expected,
            app(GroomingServicePriceResolver::class)->servicePrice($service, $size),
        );
    }

    public static function catalogPrices(): array
    {
        return [
            'partial small' => ['partial_grooming', 'small', '400.00'],
            'partial extra large' => ['partial_grooming', 'extra_large', '700.00'],
            'regular medium' => ['regular_dog_grooming', 'medium', '650.00'],
            'regular extra large' => ['regular_dog_grooming', 'extra_large', '1050.00'],
            'deluxe small' => ['deluxe_dog_grooming', 'small', '650.00'],
            'deluxe medium' => ['deluxe_dog_grooming', 'medium', '750.00'],
            'deluxe large' => ['deluxe_dog_grooming', 'large', '1000.00'],
            'deluxe extra large' => ['deluxe_dog_grooming', 'extra_large', '1200.00'],
            'bath and go large' => ['bath_and_go', 'large', '650.00'],
            'cat full grooming small' => ['cat_full_grooming', 'small', '500.00'],
            'cat full grooming medium' => ['cat_full_grooming', 'medium', '600.00'],
            'nail clipping standard snapshot' => ['nail_clipping', null, '75.00'],
            'ear cleaning' => ['ear_cleaning', null, '150.00'],
            'facial trimming' => ['facial_trimming', null, '150.00'],
            'anal sac draining' => ['anal_sac_draining', null, '150.00'],
            'tooth brushing' => ['tooth_brushing', null, '100.00'],
        ];
    }

    public function test_positive_saved_snapshot_wins_over_the_current_catalog(): void
    {
        $line = new BookingService([
            'service_id' => 3,
            'price_at_booking' => '825.50',
        ]);

        $this->assertSame(
            ['amount' => '825.50', 'source' => 'booking_snapshot'],
            app(GroomingServicePriceResolver::class)
                ->bookingServicePrice($line, 'medium'),
        );
    }
}
