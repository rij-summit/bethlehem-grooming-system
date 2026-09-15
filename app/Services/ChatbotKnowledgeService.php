<?php

namespace App\Services;

use App\Models\Service;
use Illuminate\Support\Facades\Schema;

class ChatbotKnowledgeService
{
    /**
     * @return array<int, string>
     */
    public function groomingCatalogPromptLines(): array
    {
        if (! Schema::hasTable('services')) {
            return $this->fallbackCatalogLines();
        }

        $services = Service::query()
            ->where('is_active', true)
            ->orderBy('service_id')
            ->get();

        if ($services->isEmpty()) {
            return $this->fallbackCatalogLines();
        }

        $packages = $services
            ->filter(fn (Service $service) => $this->kind($service) === 'package')
            ->map(fn (Service $service) => $this->packageLine($service))
            ->filter()
            ->values();
        $alaCarte = $services
            ->filter(fn (Service $service) => $this->kind($service) === 'ala_carte')
            ->map(fn (Service $service) => $this->alaCarteItem($service))
            ->filter()
            ->values();

        $lines = $packages->map(fn (string $line) => '- '.$line)->all();

        if ($alaCarte->isNotEmpty()) {
            $lines[] = '- A la carte: '.$alaCarte->implode('; ').'.';
        }

        $lines[] = '- A plus sign means the listed amount is a starting price. The final price is determined at the clinic.';
        $lines[] = '- This catalogue is loaded from the current active services records. Do not quote inactive services or invent other prices.';

        return $lines;
    }

    public function conciseCatalog(): string
    {
        return collect($this->groomingCatalogPromptLines())
            ->slice(0, -2)
            ->implode("\n");
    }

    private function packageLine(Service $service): ?string
    {
        $prices = collect([
            'Small' => $this->packagePrice($service, 'small'),
            'Medium' => $this->packagePrice($service, 'medium'),
            'Large' => $this->packagePrice($service, 'large'),
            'Extra Large' => $this->packagePrice($service, 'extra_large'),
        ])->filter(fn ($price) => $price !== null);

        if ($prices->isEmpty()) {
            return null;
        }

        $isDogPackage = $service->slug !== 'cat_full_grooming';
        $species = $isDogPackage ? 'dog' : 'cat';
        $description = trim((string) $service->description);
        $details = $description !== '' ? '; '.$description : '';
        $priceText = $prices->map(function (float $price, string $size) use (
            $isDogPackage
        ) {
            $starting = $isDogPackage
                && in_array($size, ['Large', 'Extra Large'], true);

            return $size.' PHP '.$this->formatAmount($price).($starting ? '+' : '');
        })->implode('; ');

        return "{$service->service_name} ({$species}{$details}): {$priceText}.";
    }

    private function alaCarteItem(Service $service): ?string
    {
        $minimum = $this->columnAmount($service, 'price_min');
        $maximum = $this->columnAmount($service, 'price_max');
        $base = $this->positiveAmount($service->base_price);

        if ($minimum !== null && $maximum !== null) {
            return $service->service_name.' PHP '
                .$this->formatAmount($minimum).'-'.$this->formatAmount($maximum);
        }

        if ($base === null) {
            return null;
        }

        $starting = Schema::hasColumn('services', 'is_starting_price')
            && (bool) $service->is_starting_price;

        return $service->service_name.' PHP '
            .$this->formatAmount($base).($starting ? '+' : '');
    }

    private function packagePrice(Service $service, string $size): ?float
    {
        $column = match ($size) {
            'small' => 'price_small',
            'medium' => 'price_medium',
            'large' => 'price_large',
            'extra_large' => 'price_extra_large',
        };
        $databasePrice = $this->columnAmount($service, $column);

        if ($databasePrice !== null) {
            return $databasePrice;
        }

        return $this->positiveAmount(
            config("grooming_services.services.{$service->slug}.prices.{$size}")
        );
    }

    private function columnAmount(Service $service, string $column): ?float
    {
        if (! Schema::hasColumn('services', $column)) {
            return null;
        }

        return $this->positiveAmount($service->{$column});
    }

    private function positiveAmount(mixed $amount): ?float
    {
        return is_numeric($amount) && (float) $amount > 0
            ? (float) $amount
            : null;
    }

    private function kind(Service $service): ?string
    {
        return config("grooming_services.services.{$service->slug}.kind");
    }

    private function formatAmount(float $amount): string
    {
        return fmod($amount, 1.0) === 0.0
            ? number_format($amount, 0)
            : number_format($amount, 2);
    }

    /**
     * @return array<int, string>
     */
    private function fallbackCatalogLines(): array
    {
        return [
            '- Partial Grooming (dog; trimming, nail clipping, ear cleaning, dry shampoo): Small PHP 400; Medium PHP 500; Large PHP 600+; Extra Large PHP 700+.',
            '- Regular Dog Grooming (bath, blow dry, haircut, nail clipping, ear cleaning, tooth brushing): Small PHP 550; Medium PHP 650; Large PHP 850+; Extra Large PHP 1,050+.',
            '- Deluxe Dog Grooming (bath, blow dry, special haircut, nail clipping, ear cleaning, tooth brushing): Small PHP 650; Medium PHP 750; Large PHP 1,000+; Extra Large PHP 1,200+.',
            '- Bath and Go (dog; bath, blow dry, nail clipping, ear cleaning, tooth brushing): Small PHP 450; Medium PHP 550; Large PHP 650+; Extra Large PHP 750+.',
            '- Full Grooming (cat; bath, blow dry, optional haircut, nail clipping, ear cleaning): Small PHP 500; Medium PHP 600.',
            '- A la carte: Nail Clipping PHP 50-100; Ear Cleaning PHP 150+; Facial Trimming PHP 150; Anal Sac Draining PHP 150; Tooth Brushing PHP 100+.',
            '- A plus sign means the listed amount is a starting price. The final price is determined at the clinic.',
            '- Do not invent discounts, other packages, or other prices.',
        ];
    }
}
