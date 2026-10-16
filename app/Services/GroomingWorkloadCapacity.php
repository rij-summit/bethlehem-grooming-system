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
    public const UNAVAILABLE = 'Unavailable — selected services may run past closing.';
    public const REJECTION = 'Insufficient grooming time remaining';
    public const REJECTION_DETAIL = "Based on the current grooming queue, this service is projected to finish after today's grooming hours.";
    public const WINDOW_UNAVAILABLE = 'That arrival time just became unavailable. Please choose another available time.';
    public const DATE_FULL = 'Grooming capacity is full for this date. Please choose another available date.';

    public function __construct(private GroomingTimeEstimate $estimates, private OperationalCapacity $physical) {}

    /** Physical workload only; future reservations are read separately. */
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
        return $pets->where('grooming_state', '!=', BookingPet::GROOMING_STATE_CANCELLED)->sortBy(fn (BookingPet $pet) => [
            match (strtolower((string) $pet->pet?->species)) { 'dog' => 0, 'cat' => 1, default => 2 },
            $pet->booking_pet_id,
        ])->map(fn (BookingPet $pet) => $this->job($pet))->values()->all();
    }

    /** Use the existing estimator and clinic-verified sizes for customer selections. */
    public function selectionJobs(array $selections, int $userId): array
    {
        // Workload forecasts require services for every selected pet.
        if (collect($selections)->contains(fn ($pet) => empty($pet['services']['package']) && empty($pet['services']['ala_carte']))) return [];
        $pets = Pet::where('user_id', $userId)->whereIn('pet_id', array_column($selections, 'pet_id'))->get()->keyBy('pet_id');
        return collect($selections)->sortBy(fn (array $pet) => strtolower($pet['species'] ?? 'dog') === 'dog' ? 0 : 1)
            ->values()->map(function (array $selection, int $index) use ($pets) {
                $pet = $pets->get($selection['pet_id'] ?? null);
                $size = $pet?->hasClinicVerifiedSize() ? $pet->groomingSize()
                    : (\App\Support\PetWeightSize::withComputedSize([
                        ...$selection,
                        'species' => $pet?->species ?? $selection['species'] ?? null,
                        'weight' => $selection['weight'] ?? $pet?->weight,
                    ])['size'] ?? null);
                $estimate = $this->estimates->calculate($selection['services']['package'] ?? null,
                    $selection['grooming_preference'] ?? null, $size, $selection['services']['ala_carte'] ?? [], [], false);
                return ['id' => 'arrival-'.$index, 'minutes' => $estimate['maxMinutes'], 'active' => false];
            })->all();
    }

    public function snapshot(?ClinicSetting $settings = null): array
    {
        return $this->project($this->liveJobs()->all(), [], $settings ?? ClinicSetting::current(), now());
    }

    /** Existing booking estimates reserve work until the selected arrival period ends. */
    public function reservedJobs(string $date, ?int $excludeBookingId = null): array
    {
        if ($date < now()->toDateString()) return [];

        return BookingPet::query()
            ->whereNull('grooming_end_time')
            ->where('grooming_state', BookingPet::GROOMING_STATE_NOT_STARTED)
            ->whereHas('booking', fn ($booking) => $booking
                ->where('status', 'waiting_to_arrive')->whereNull('dropped_off_at')
                ->where('booking_date', $date)
                ->when($excludeBookingId !== null, fn ($query) => $query->where('booking_id', '!=', $excludeBookingId))
                ->whereHas('timeWindow', fn ($window) => $window
                    ->when($date === now()->toDateString(), fn ($query) => $query->where('end_time', '>', now()->format('H:i:s')))))
            ->with(['pet', 'booking.timeWindow', 'booking.bookingServices.service'])
            ->orderBy('booking_pet_id')->get()
            ->sortBy(fn (BookingPet $pet) => [$pet->booking_id,
                match (strtolower((string) $pet->pet?->species)) { 'dog' => 0, 'cat' => 1, default => 2 },
                $pet->booking_pet_id])
            ->map(fn (BookingPet $pet) => [...$this->job($pet), 'active' => false,
                'arrival_at' => Carbon::parse($date.' '.$pet->booking->timeWindow->end_time)->timestamp])
            ->filter(fn ($job) => $job['minutes'] > 0)->values()->all();
    }

    public function forecastWindows(Collection $windows, string $date, array $arrivals, ClinicSetting $settings, ?int $excludeBookingId = null): Collection
    {
        $jobs = $date === now()->toDateString() ? $this->liveJobs()->all() : [];
        $reserved = $this->reservedJobs($date, $excludeBookingId);
        return $windows->map(function (array $window) use ($jobs, $reserved, $date, $arrivals, $settings) {
            $arrival = Carbon::parse($date.' '.$window['end_time']);
            $future = collect([...$reserved, ...array_map(fn ($job) => [...$job, 'arrival_at' => $arrival->timestamp], $arrivals)])
                ->sortBy('arrival_at')->values()->all();
            $forecast = $this->project($jobs, $future, $settings,
                Carbon::parse($date.' '.$settings->serviceAvailability('grooming')['open_time']));
            return [...$window, 'is_workload_unavailable' => $arrivals !== [] && ! $forecast['fits'],
                'workload_message' => $arrivals !== [] && ! $forecast['fits'] ? self::UNAVAILABLE : null];
        });
    }

    /** Called under DailyPetQueue's shared intake lock; throwing rolls back all submission writes. */
    public function assertCanReserve(string $date, int $windowId, array $arrivals, ClinicSetting $settings, ?int $excludeBookingId = null): void
    {
        $windows = $this->forecastWindows(app(AvailabilityTimeWindowService::class)->preferredWindows($settings, 'grooming', $date),
            $date, $arrivals, $settings, $excludeBookingId);
        $selected = $windows->firstWhere('window_id', $windowId);
        if ($selected && ! $selected['is_past'] && ! $selected['is_cutoff'] && ! $selected['is_closed']
            && ! $selected['is_workload_unavailable']) return;

        $available = $windows->contains(fn ($window) => ! $window['is_past'] && ! $window['is_cutoff']
            && ! $window['is_closed'] && ! $window['is_workload_unavailable']);
        $message = $available ? self::WINDOW_UNAVAILABLE : self::DATE_FULL;
        $exception = ValidationException::withMessages(['window_id' => $message]);
        $exception->response = response()->json(['success' => false, 'code' => 'grooming_forecast_unavailable',
            'message' => $message, 'date_full' => ! $available, 'windows' => $windows, 'errors' => $exception->errors()], 422);
        throw $exception;
    }

    public function admissionForecast(array $arrivals, ?Carbon $arrival = null, ?int $excludeBookingId = null): array
    {
        $arrival ??= now();
        $future = [...array_map(fn ($job) => [...$job, 'arrival_at' => $arrival->timestamp], $arrivals),
            ...$this->reservedJobs($arrival->toDateString(), $excludeBookingId)];
        return $this->project($this->liveJobs()->all(),
            collect($future)->sortBy('arrival_at')->values()->all(),
            ClinicSetting::current(), $arrival);
    }

    public function assertCanAdmit(array $arrivals, ?Carbon $arrival = null, ?int $excludeBookingId = null): void
    {
        $forecast = $this->admissionForecast($arrivals, $arrival, $excludeBookingId);
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
            $earliestArrival = $job['arrival_at'] ?? ($index >= count($waiting) ? ($arrival?->timestamp ?? $base) : $base);
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
