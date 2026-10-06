<?php

namespace App\Services;

use App\Models\BookingService;
use App\Models\Service;
use Illuminate\Support\Facades\DB;

class GroomingServicePriceResolver
{
    /** Snapshot variable-price limits at booking creation. A null maximum means "from". */
    public function bookingPriceBounds(Service $service, ?string $petSize): array
    {
        $catalog = config("grooming_services.services.{$service->slug}", []);
        if ($service->starting_price_sizes !== null) {
            $price = $this->servicePrice($service, $petSize);
            if (($catalog['kind'] ?? null) === 'package') {
                $maximum = ($service->range_price_maximums ?? [])[$this->normalizeSize($petSize)] ?? null;
                if ($this->isPositive($maximum)) {
                    return ['min' => $price, 'max' => $this->normalizeMoney($maximum)];
                }
                return in_array($this->normalizeSize($petSize), $service->starting_price_sizes, true)
                    ? ['min' => $price, 'max' => null]
                    : ['min' => $price, 'max' => $price];
            }
            if ($this->isPositive($service->price_min) && $this->isPositive($service->price_max)) {
                return ['min' => $this->normalizeMoney($service->price_min), 'max' => $this->normalizeMoney($service->price_max)];
            }
            return $service->is_starting_price
                ? ['min' => $price, 'max' => null]
                : ['min' => $price, 'max' => $price];
        }
        $hasDatabaseBounds = $this->isPositive($service->price_min ?? null)
            || $this->isPositive($service->price_max ?? null);
        $minimum = $hasDatabaseBounds ? $service->price_min : ($catalog['minimum'] ?? null);
        $maximum = $hasDatabaseBounds ? $service->price_max : ($catalog['maximum'] ?? null);

        if ($this->isPositive($minimum) && $this->isPositive($maximum)) {
            return [
                'min' => $this->normalizeMoney($minimum),
                'max' => $this->normalizeMoney($maximum),
            ];
        }

        $size = $this->normalizeSize($petSize);
        if ($this->isPositive($minimum)
            || $service->is_starting_price
            || ($catalog['starting_price'] ?? false)
            || in_array($size, $catalog['starting_sizes'] ?? [], true)) {
            $price = $this->servicePrice($service, $petSize);
            return ['min' => $price, 'max' => null];
        }

        return ['min' => null, 'max' => null];
    }

    /** Payment-only limits; do not save safety caps as advertised booking ranges. */
    public function paymentPriceRules(BookingService $line, ?string $petSize): array
    {
        $service = $line->service;
        $catalog = $service ? config("grooming_services.services.{$service->slug}", []) : [];
        $size = $this->normalizeSize($petSize);
        $bookedSize = $this->normalizeSize($line->bookingPet?->confirmed_size ?? $line->bookingPet?->registered_size);
        $sizeChanged = ($catalog['kind'] ?? null) === 'package' && $size !== $bookedSize;
        $minimum = $line->price_min_at_booking;
        $maximum = $line->price_max_at_booking;
        // Older lines predate bounds snapshots. Recover their original rule from
        // legacy metadata, using the saved amount rather than today's catalogue.
        if (! $sizeChanged && $minimum === null && $this->isPositive($line->price_at_booking)) {
            if (($catalog['starting_price'] ?? false)
                || in_array($size, $catalog['starting_sizes'] ?? [], true)) {
                $minimum = $this->normalizeMoney($line->price_at_booking);
            } elseif (isset($catalog['minimum'], $catalog['maximum'])) {
                $minimum = $catalog['minimum'];
                $maximum = $catalog['maximum'];
            }
        }
        if (($sizeChanged || ($minimum === null && ! $this->isPositive($line->price_at_booking))) && $service) {
            $bounds = $this->bookingPriceBounds($service, $size);
            $minimum = $bounds['min'];
            $maximum = $bounds['max'];
        }

        $pricingType = $minimum !== null ? ($maximum !== null ? 'range' : 'plus') : 'custom';
        if ($minimum !== null && $maximum !== null && (float) $minimum === (float) $maximum) {
            $pricingType = 'fixed';
        } elseif ($minimum === null && $service) {
            $minimum = $sizeChanged ? $this->servicePrice($service, $size)
                : $this->bookingServicePrice($line, $size)['amount'];
            $maximum = $minimum;
            $pricingType = 'fixed';
        }

        $safetyMaximum = null;
        $reviewThreshold = null;
        if ($pricingType === 'plus') {
            if (($catalog['kind'] ?? null) === 'package') {
                $safetyMaximum = $this->normalizeMoney((float) $minimum + 500);
                $startingSizes = $service->starting_price_sizes ?? $catalog['starting_sizes'] ?? [];
                if ($size === 'large' && in_array('extra_large', $startingSizes, true)) {
                    $reviewThreshold = $this->servicePrice($service, 'extra_large');
                }
            } elseif (($catalog['kind'] ?? null) === 'ala_carte') {
                $safetyMaximum = $this->normalizeMoney((float) $minimum + 200);
            }
        }

        return [
            'min' => $minimum,
            'max' => $maximum,
            'pricing_type' => $pricingType,
            'safety_max' => $safetyMaximum,
            'review_threshold' => $reviewThreshold,
        ];
    }

    /**
     * Resolve a fixed price or variable-price minimum. Positive database
     * catalogue prices take precedence. Legacy defaults only cover rows
     * without persisted pricing metadata.
     */
    public function servicePrice(Service $service, ?string $petSize): string
    {
        $size = $this->normalizeSize($petSize);
        $catalog = config("grooming_services.services.{$service->slug}");

        if ($service->starting_price_sizes !== null) {
            if (($catalog['kind'] ?? null) === 'package') {
                return $this->normalizeMoney($this->databasePackagePrice($service, $size) ?? $service->base_price);
            }
            return $this->normalizeMoney($service->price_min ?? $service->base_price);
        }

        if ($this->isPositive($service->price_min ?? null)
            || $this->isPositive($catalog['minimum'] ?? null)) {
            return $this->normalizeMoney($this->isPositive($service->price_min ?? null)
                ? $service->price_min : $catalog['minimum']);
        }

        if (($catalog['kind'] ?? null) === 'package') {
            $databasePrice = $this->databasePackagePrice($service, $size);
            if ($this->isPositive($databasePrice)) {
                return $this->normalizeMoney($databasePrice);
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

        if ($this->isPositive($bookingService->price_min_at_booking)
            && $this->isPositive($bookingService->price_max_at_booking)) {
            return [
                'amount' => $this->normalizeMoney($bookingService->price_min_at_booking),
                'source' => 'unresolved_range_minimum',
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
            'extra_large' => $service->price_extra_large ?? null,
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
