<?php

namespace App\Services;

use App\Models\BookingPet;
use App\Models\ClinicSetting;
use App\Models\Pet;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class GroomingWorkloadCapacity
{
    public const AT_RISK_MINUTES = 30;
    public const UNAVAILABLE = 'Unavailable — not enough grooming time remaining';
    public const REJECTION = 'Insufficient grooming time remaining';
    public const REJECTION_DETAIL = "Based on the current grooming queue, this service is projected to finish after today's grooming hours.";

    public function __construct(private GroomingTimeEstimate $estimates, private OperationalCapacity $physical) {}

    /** Read once per forecast; pending arrivals and finished pets reserve no lane. */
    public function liveJobs(): Collection
    {
        return $this->physical->groomingPets()
            ->with(['pet', 'booking.bookingServices.service'])->get()
            ->sortBy(fn (BookingPet $pet) => [
                $pet->pet_queue_date?->toDateString() ?? $pet->booking->booking_date,
                $pet->pet_queue_number ?? $pet->booking->queue_number ?? PHP_INT_MAX,
                $pet->booking_pet_id,
            ])->map(fn (BookingPet $pet) => $this->job($pet))->values();
    }

    public function job(BookingPet $pet): array
    {
        $minutes = $pet->grooming_estimate_max;
        if (! $minutes) $minutes = $this->estimates->estimateForPet($pet->booking, $pet)['maxMinutes'] ?? null;

        return ['id' => $pet->booking_pet_id, 'minutes' => $minutes,
            'active' => $pet->grooming_start_time !== null || $pet->grooming_state === BookingPet::GROOMING_STATE_IN_PROGRESS,
            'started_at' => $pet->grooming_start_time];
    }

    /** Match DailyPetQueue's dog/cat order before assigning any queue numbers. */
    public function admissionJobs(Collection $pets): array
    {
        return $pets->sortBy(fn (BookingPet $pet) => [
            match (strtolower((string) $pet->pet?->species)) { 'dog' => 0, 'cat' => 1, default => 2 },
            $pet->booking_pet_id,
        ])->map(fn (BookingPet $pet) => $this->job($pet))->values()->all();
    }

    /** Use the existing estimator and clinic-verified sizes for customer selections. */
    public function selectionJobs(array $selections, int $userId): array
    {
        // The schedule step precedes service selection; capacity cannot be forecast yet.
        if (collect($selections)->contains(fn ($pet) => empty($pet['services']['package']) && empty($pet['services']['ala_carte']))) return [];
        $pets = Pet::where('user_id', $userId)->whereIn('pet_id', array_column($selections, 'pet_id'))->get()->keyBy('pet_id');
        return collect($selections)->sortBy(fn (array $pet) => strtolower($pet['species'] ?? 'dog') === 'dog' ? 0 : 1)
            ->values()->map(function (array $selection, int $index) use ($pets) {
                $pet = $pets->get($selection['pet_id'] ?? null);
                $size = $pet?->hasClinicVerifiedSize() ? $pet->groomingSize()
                    : (\App\Support\PetWeightSize::withComputedSize($selection)['size'] ?? null);
                $estimate = $this->estimates->calculate($selection['services']['package'] ?? null,
                    $selection['grooming_preference'] ?? null, $size, $selection['services']['ala_carte'] ?? [], [], false);
                return ['id' => 'arrival-'.$index, 'minutes' => $estimate['maxMinutes'], 'active' => false];
            })->all();
    }

    public function snapshot(?ClinicSetting $settings = null): array
    {
        return $this->project($this->liveJobs()->all(), [], $settings ?? ClinicSetting::current(), now());
    }

    public function forecastWindows(Collection $windows, string $date, array $arrivals, ClinicSetting $settings): Collection
    {
        $jobs = $date === now()->toDateString() ? $this->liveJobs()->all() : [];
        return $windows->map(function (array $window) use ($jobs, $date, $arrivals, $settings) {
            $forecast = $this->project($jobs, $arrivals, $settings, Carbon::parse($date.' '.$settings->serviceAvailability('grooming')['open_time']),
                Carbon::parse($date.' '.$window['end_time']));
            return [...$window, 'is_workload_unavailable' => $arrivals !== [] && ! $forecast['fits'],
                'workload_message' => $arrivals !== [] && ! $forecast['fits'] ? self::UNAVAILABLE : null];
        });
    }

    public function assertCanAdmit(array $arrivals, ?Carbon $arrival = null): void
    {
        $forecast = $this->project($this->liveJobs()->all(), $arrivals, ClinicSetting::current(), now(), $arrival);
        if ($forecast['fits']) return;
        $exception = ValidationException::withMessages(['check_in' => self::REJECTION]);
        $exception->response = response()->json(['success' => false, 'code' => 'insufficient_grooming_time',
            'message' => self::REJECTION, 'detail' => self::REJECTION_DETAIL, 'errors' => $exception->errors()], 422);
        throw $exception;
    }

    /** Parallel lanes, using only maximum durations; no booking or queue writes. */
    public function project(array $jobs, array $arrivals, ClinicSetting $settings, Carbon $day, ?Carbon $arrival = null): array
    {
        $hours = $settings->serviceAvailability('grooming');
        $base = max($day->timestamp, Carbon::parse($day->toDateString().' '.$hours['open_time'])->timestamp);
        if ($day->isSameDay(now())) $base = max($base, now()->timestamp);
        $close = Carbon::parse($day->toDateString().' '.$hours['close_time'])->timestamp;
        $count = max(ClinicSetting::MIN_GROOMERS_ON_DUTY, min(ClinicSetting::MAX_GROOMERS_ON_DUTY, $settings->groomers_on_duty));
        $projections = [];
        $activeEnds = [];
        foreach ($jobs as $job) {
            if (! ($job['active'] ?? false)) continue;
            $start = isset($job['started_at']) ? Carbon::parse($job['started_at'])->timestamp : $base;
            $finish = ! empty($job['minutes']) ? $start + $job['minutes'] * 60 : null;
            // An unfinished, overdue session still occupies a groomer.
            if ($finish !== null && $finish <= $base) $finish = $base + $job['minutes'] * 60;
            $activeEnds[] = $finish;
            $projections[$job['id']] = $this->projection($start, $finish, $base, $close);
        }
        sort($activeEnds);
        // Excess active sessions continue, but future starts wait for concurrency to fall below count.
        $knownEnds = array_values(array_filter($activeEnds, fn ($end) => $end !== null));
        $unknownCount = count($activeEnds) - count($knownEnds);
        $availableCount = max(0, $count - $unknownCount);
        $knownEnds = $availableCount ? array_slice($knownEnds, -$availableCount) : [];
        $lanes = [...array_fill(0, min($count, $unknownCount), null), ...$knownEnds];
        while (count($lanes) < $count) $lanes[] = $base;

        $waiting = array_values(array_filter($jobs, fn ($job) => ! ($job['active'] ?? false)));
        foreach ([...$waiting, ...$arrivals] as $index => $job) {
            $knownLanes = array_filter($lanes, fn ($end) => $end !== null);
            $lane = $knownLanes ? array_search(min($knownLanes), $lanes, true) : null;
            $earliestArrival = $index >= count($waiting) ? ($arrival?->timestamp ?? $base) : $base;
            $start = $lane !== null ? max($base, $earliestArrival, $lanes[$lane]) : null;
            $finish = $start !== null && ! empty($job['minutes']) ? $start + $job['minutes'] * 60 : null;
            if ($lane !== null) $lanes[$lane] = $finish;
            $projections[$job['id']] = $this->projection($start, $finish, $base, $close);
        }
        $finishes = array_column($projections, 'completion_timestamp');
        $unknown = in_array(null, $finishes, true);
        $last = $finishes && ! $unknown ? max($finishes) : null;
        $fits = ! $unknown && ($last ?? $base) <= $close;
        return ['groomers_on_duty' => $count, 'waiting' => count($waiting), 'in_progress' => count($activeEnds),
            'closing_time' => $this->time($close),
            'projected_last_completion' => $last !== null ? $this->time($last) : null,
            'remaining_lane_minutes' => array_sum(array_map(fn ($end) => $end !== null ? max(0, ($close - $end) / 60) : 0, $lanes)),
            'fits' => $fits, 'state' => $this->state($unknown ? null : ($last ?? $base), $close), 'pets' => $projections];
    }

    private function projection(?int $start, ?int $finish, int $base, int $close): array
    {
        return ['projected_start' => $start !== null ? $this->time($start) : null,
            'projected_completion' => $finish !== null ? $this->time($finish) : null,
            'completion_timestamp' => $finish, 'queue_wait_minutes' => $start !== null ? max(0, (int) ceil(($start - $base) / 60)) : null,
            'state' => $this->state($finish, $close)];
    }

    private function state(?int $finish, int $close): string
    {
        if ($finish === null || $finish > $close) return 'Needs staff action';
        return $close - $finish <= self::AT_RISK_MINUTES * 60 ? 'At risk' : 'On track';
    }

    private function time(int $timestamp): string
    {
        return Carbon::createFromTimestamp($timestamp, config('app.timezone'))->toIso8601String();
    }
}
