<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingPet;
use Illuminate\Validation\ValidationException;

class GroomingTimeEstimate
{
    public function calculate(?string $package, ?string $preference, ?string $size, array $alaCarte = [], array $factors = [], bool $requirePreference = true): array
    {
        $rules = config('grooming_estimates');
        $choices = $rules['preferences'][$package] ?? [];
        if (($preference && ! isset($choices[$preference])) || ($requirePreference && $choices && ! $preference)) {
            throw ValidationException::withMessages(['grooming_preference' => 'Select a grooming preference for the selected package.']);
        }
        if (array_diff($factors, array_keys($rules['factors']))) {
            throw ValidationException::withMessages(['estimate_factors' => 'Select valid estimate factors.']);
        }
        if ($package && ! isset($rules['packages'][$package])) {
            throw ValidationException::withMessages(['services' => 'Select a supported grooming package.']);
        }
        $metadata = $rules['packages'][$package] ?? [];
        $tables = isset($metadata['cuts'])
            ? ($preference ? [$metadata['cuts'][$preference]] : array_values($metadata['cuts']))
            : (isset($metadata['sizes']) ? [$metadata['sizes']] : []);
        $ranges = [];
        foreach ($tables as $table) {
            if ($size && ! isset($table[$size])) {
                throw ValidationException::withMessages(['size' => 'Select a supported grooming size.']);
            }
            array_push($ranges, ...($size ? [$table[$size]] : array_values($table)));
        }
        $min = $ranges ? min(array_column($ranges, 0)) : 0;
        $max = $ranges ? max(array_column($ranges, 1)) : 0;
        foreach (array_diff(array_unique($alaCarte), $metadata['included'] ?? []) as $slug) {
            if (! isset($rules['ala_carte'][$slug])) {
                throw ValidationException::withMessages(['services' => 'Select supported grooming services.']);
            }
            $min += $rules['ala_carte'][$slug][0];
            $max += $rules['ala_carte'][$slug][1];
        }
        if ($factors) {
            $min = max($min, $rules['extended'][0]);
            $max = max($max, $rules['extended'][1]);
        }
        return ['minMinutes' => $min, 'maxMinutes' => $max, 'formatted' => $min ? $this->format($min, $max) : null,
            'preference' => $choices ? $preference : null, 'preferenceLabel' => $choices[$preference] ?? null, 'factors' => array_values(array_unique($factors))];
    }

    public function format(int $min, int $max): string
    {
        $duration = function (int $minutes): string {
            $hours = intdiv($minutes, 60);
            $rest = $minutes % 60;
            return $hours ? $hours.($hours === 1 ? ' hr' : ' hrs').($rest ? ' '.$rest.' min' : '') : $minutes.' min';
        };
        if ($min === $max) return $duration($min);
        if ($min % 60 === 0 && $max % 60 === 0) return intdiv($min, 60).'–'.intdiv($max, 60).' hrs';
        return $duration($min).'–'.$duration($max);
    }

    public function snapshot(BookingPet $pet, array $estimate): void
    {
        $pet->fill(['grooming_preference' => $estimate['preference'], 'grooming_estimate_min' => $estimate['minMinutes'],
            'grooming_estimate_max' => $estimate['maxMinutes'], 'grooming_estimate_factors' => $estimate['factors']])->save();
    }

    public function recalculate(Booking $booking, BookingPet $pet, ?array $factors = null, ?string $preference = null): void
    {
        if ($pet->grooming_end_time || $pet->grooming_state === BookingPet::GROOMING_STATE_FINISHED) return;
        $estimate = $this->estimateForPet($booking, $pet, $factors, $preference);
        if ($estimate) $this->snapshot($pet, $estimate);
    }

    public function estimateForPet(Booking $booking, BookingPet $pet, ?array $factors = null, ?string $preference = null): ?array
    {
        $booking->loadMissing('bookingServices.service');
        $slugs = $booking->bookingServices->where('booking_pet_id', $pet->booking_pet_id)->pluck('service.slug')->filter()->all();
        $slugs = array_values(array_intersect($slugs, array_keys(config('grooming_services.services'))));
        if (! $slugs) return null;
        $packages = array_values(array_intersect($slugs, array_keys(config('grooming_estimates.packages'))));
        return $this->calculate($packages[0] ?? null, $preference ?? $pet->grooming_preference,
            $pet->confirmed_size ?? $pet->registered_size ?? $pet->pet?->groomingSize(),
            array_values(array_diff($slugs, $packages)), $factors ?? $pet->grooming_estimate_factors ?? [], false);
    }

    public function forVisit(BookingPet $pet): ?array
    {
        if (! $pet->grooming_estimate_min || ! $pet->grooming_estimate_max) return null;
        return ['minMinutes' => $pet->grooming_estimate_min, 'maxMinutes' => $pet->grooming_estimate_max,
            'formatted' => $this->format($pet->grooming_estimate_min, $pet->grooming_estimate_max),
            'preference' => $pet->grooming_preference,
            'preferenceLabel' => collect(config('grooming_estimates.preferences'))->flatMap(fn ($choices) => $choices)->get($pet->grooming_preference),
            'factors' => $pet->grooming_estimate_factors ?? []];
    }
}
