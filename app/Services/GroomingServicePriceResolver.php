<?php

namespace App\Services;

use App\Models\BookingService;
use App\Models\Service;
use Illuminate\Support\Facades\DB;

class GroomingServicePriceResolver
{
    /**
     * Resolve the price that must be snapshotted when a grooming service is
     * selected. Positive database catalogue prices take precedence, while the
     * application catalogue safely covers imported zero rows and extra-large
     * package pricing that the current schema cannot store separately.
     */
    public function servicePrice(Service $service, ?string $petSize): string
    {
        $size = $this->normalizeSize($petSize);
        $catalog = config("grooming_services.services.{$service->slug}");

        if (($catalog['kind'] ?? null) === 'package') {
            if ($size !== 'extra_large') {
                $databasePrice = $this->databasePackagePrice($service, $size);
                if ($this->isPositive($databasePrice)) {
                    return $this->normalizeMoney($databasePrice);
                }
            }

            $catalogPrice = $catalog['prices'][$size] ?? $catalog['default'] ?? null;
            if ($this->isPositive($catalogPrice)) {
                return $this->normalizeMoney($catalogPrice);
            }
        }

        if ($this->isPositive($service->base_price ?? null)) {
            return $this->normalizeMoney($service->base_price);
        }

        $fallback = $catalog['default'] ?? null;

        return $this->isPositive($fallback)
            ? $this->normalizeMoney($fallback)
            : '0.00';
    }

    /**
     * Return the effective historical line price without changing the saved
     * booking-service row. A positive snapshot always wins. Zero legacy rows
     * may be recovered from the server catalogue or the linked add-on price.
     */
    public function bookingServicePrice(
        BookingService $bookingService,
        ?string $petSize,
    ): array {
        if ($this->isPositive($bookingService->price_at_booking)) {
            return [
                'amount' => $this->normalizeMoney($bookingService->price_at_booking),
                'source' => 'booking_snapshot',
            ];
        }

        if ($bookingService->service_id !== null) {
            $service = $bookingService->relationLoaded('service')
                ? $bookingService->service
                : Service::query()->find($bookingService->service_id);

            if ($service) {
                $price = $this->servicePrice($service, $petSize);
                if ($this->isPositive($price)) {
                    return [
                        'amount' => $price,
                        'source' => 'catalog_fallback',
                    ];
                }
            }
        }

        if ($bookingService->addon_id !== null) {
            $addonPrice = DB::table('addons')
                ->where('addon_id', $bookingService->addon_id)
                ->value('price');

            if ($this->isPositive($addonPrice)) {
                return [
                    'amount' => $this->normalizeMoney($addonPrice),
                    'source' => 'addon_catalog_fallback',
                ];
            }
        }

        return ['amount' => '0.00', 'source' => 'unpriced'];
    }

    private function databasePackagePrice(Service $service, string $size): mixed
    {
        return match ($size) {
            'small' => $service->price_small ?? null,
            'medium' => $service->price_medium ?? null,
            'large' => $service->price_large ?? null,
            default => $service->base_price ?? null,
        };
    }

    private function normalizeSize(?string $size): string
    {
        $normalized = strtolower(trim((string) $size));

        return match ($normalized) {
            'extra large', 'extra-large', 'extralarge', 'xl' => 'extra_large',
            'small', 'medium', 'large', 'extra_large' => $normalized,
            default => '',
        };
    }

    private function isPositive(mixed $amount): bool
    {
        return is_numeric($amount) && (float) $amount > 0;
    }

    private function normalizeMoney(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
