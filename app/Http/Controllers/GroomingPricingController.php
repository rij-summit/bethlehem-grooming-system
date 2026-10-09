<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Services\GroomingServicePriceResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class GroomingPricingController extends Controller
{
    public function index(GroomingServicePriceResolver $resolver)
    {
        $services = Service::whereIn('slug', array_keys(config('grooming_services.services')))
            ->where('is_active', true)->get()->keyBy('slug');
        $catalogue = collect(array_keys(config('grooming_services.services')))
            ->filter(fn ($slug) => $services->has($slug))
            ->map(fn ($slug) => $this->normalize($services[$slug], $resolver))->values();

        return response()->json(['data' => $catalogue, 'priceLimits' => config('grooming_services.price_limits'), 'estimateRules' => config('grooming_estimates')])
            ->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, Service $service, GroomingServicePriceResolver $resolver)
    {
        abort_unless($request->user()?->role === 'admin', 403);
        $metadata = config("grooming_services.services.{$service->slug}");
        abort_unless($metadata, 404);
        $package = $metadata['kind'] === 'package';
        $sizes = array_keys($metadata['prices'] ?? []);
        $limits = config('grooming_services.price_limits.'.$metadata['kind']);
        $money = ['bail', 'required', 'numeric', 'regex:/^\d+(?:\.\d{1,2})?$/D',
            'min:'.$limits['minimum'], 'max:'.$limits['maximum']];
        $rules = $package ? ['sizes' => ['required', 'array:'.implode(',', $sizes)]] : [
            'pricing_type' => 'required|in:fixed,range,starting_at',
            'amount' => $money,
        ];
        if ($package) {
            foreach ($sizes as $size) {
                $rules["sizes.$size"] = 'required|array:pricing_type,amount,maximum';
                $rules["sizes.$size.pricing_type"] = 'required|in:fixed,range,starting_at';
                $rules["sizes.$size.amount"] = $money;
                if ($request->input("sizes.$size.pricing_type") === 'range') {
                    $rules["sizes.$size.maximum"] = $money;
                }
            }
        } elseif ($request->input('pricing_type') === 'range') {
            $rules['maximum'] = $money;
        }
        $amountMessage = 'Enter a reasonable price amount.';
        $messages = [
            'sizes.required' => 'Enter a price.',
            'pricing_type.required' => 'Enter a price.',
            'sizes.array' => 'Only the supported package sizes may be updated.',
        ];
        foreach ($rules as $field => $fieldRules) {
            if (is_array($fieldRules) && in_array('numeric', $fieldRules, true)) {
                foreach (['required', 'numeric', 'regex', 'min', 'max'] as $rule) {
                    $messages[$field.'.'.$rule] = $amountMessage;
                }
            }
        }
        $validator = Validator::make($request->all(), $rules, $messages);
        $validator->after(function ($validator) use ($request, $package, $sizes) {
            foreach ($package ? $sizes : [''] as $size) {
                $prefix = $package ? "sizes.$size." : '';
                if ($request->input($prefix.'pricing_type') === 'range'
                    && ! $validator->errors()->has($prefix.'amount')
                    && ! $validator->errors()->has($prefix.'maximum')
                    && (float) $request->input($prefix.'maximum') <= (float) $request->input($prefix.'amount')) {
                    $validator->errors()->add($prefix.'maximum', 'Maximum price must be greater than minimum price.');
                }
            }
            if (! $package || $validator->errors()->isNotEmpty()) {
                return;
            }
            $previous = 0;
            foreach ($sizes as $size) {
                $amount = (float) $request->input("sizes.$size.amount");
                if ($amount < $previous) {
                    $validator->errors()->add("sizes.$size.amount", 'Price must not be lower than the preceding size.');
                }
                $previous = $amount;
            }
        });
        $data = $validator->validate();
        DB::transaction(function () use ($service, $data, $package, $sizes) {
            $values = ['base_price' => null, 'price_min' => null, 'price_max' => null,
                'is_starting_price' => false, 'starting_price_sizes' => [], 'range_price_maximums' => []];
            foreach (['small', 'medium', 'large', 'extra_large'] as $size) {
                $values['price_'.$size] = null;
            }
            if ($package) {
                foreach ($sizes as $size) {
                    $values['price_'.$size] = $data['sizes'][$size]['amount'];
                    if ($data['sizes'][$size]['pricing_type'] === 'starting_at') {
                        $values['starting_price_sizes'][] = $size;
                    } elseif ($data['sizes'][$size]['pricing_type'] === 'range') {
                        $values['range_price_maximums'][$size] = $data['sizes'][$size]['maximum'];
                    }
                }
                $values['base_price'] = $data['sizes']['medium']['amount'] ?? $data['sizes'][$sizes[0]]['amount'];
            } else {
                $values['base_price'] = $data['amount'];
                if ($data['pricing_type'] !== 'fixed') {
                    $values['price_min'] = $data['amount'];
                }
                if ($data['pricing_type'] === 'range') {
                    $values['price_max'] = $data['maximum'];
                }
                $values['is_starting_price'] = $data['pricing_type'] === 'starting_at';
            }
            $service->update($values);
        });

        return response()->json(['data' => $this->normalize($service->fresh(), $resolver)]);
    }

    private function normalize(Service $service, GroomingServicePriceResolver $resolver): array
    {
        return ['serviceId' => $service->service_id, 'id' => $service->slug,
            'name' => $service->service_name, 'kind' => config("grooming_services.services.{$service->slug}.kind"),
            'priceOptions' => $this->priceOptions($service, $resolver),
            // An unpersisted service resolves the official config defaults only.
            'defaultPriceOptions' => $this->priceOptions(new Service(['slug' => $service->slug]), $resolver)];
    }

    private function priceOptions(Service $service, GroomingServicePriceResolver $resolver): array
    {
        $metadata = config("grooming_services.services.{$service->slug}");
        $sizes = $metadata['kind'] === 'package' ? array_keys($metadata['prices']) : [''];
        $labels = ['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large', 'extra_large' => 'Extra Large', '' => 'Standard'];
        return array_map(function ($size) use ($service, $resolver, $labels) {
            $bounds = $resolver->bookingPriceBounds($service, $size);
            $min = (float) ($bounds['min'] ?? $resolver->servicePrice($service, $size));
            $max = $bounds['min'] === null ? $min : ($bounds['max'] === null ? null : (float) $bounds['max']);

            return ['label' => $labels[$size], 'sizeKey' => $size,
                'pricingType' => $max === null ? 'starting_at' : ($min === $max ? 'fixed' : 'range'),
                'minAmount' => $min, 'maxAmount' => $max];
        }, $sizes);
    }
}
