<?php

namespace App\Services;

use App\Models\ClinicSetting;
use App\Models\TimeWindow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AvailabilityTimeWindowService
{
    public const DEFAULT_MAX_SLOTS = 4;

    public function sync(ClinicSetting $settings): void
    {
        DB::transaction(function () use ($settings): void {
            $desiredWindows = collect(['clinic', 'grooming'])
                ->flatMap(fn (string $service) => $settings->desiredTimeWindows($service))
                ->unique(fn (array $window) => $this->windowKey(
                    $window['start_time'],
                    $window['end_time'],
                ))
                ->values();

            $existingWindows = TimeWindow::query()
                ->orderBy('window_id')
                ->lockForUpdate()
                ->get();
            $activeWindowIds = [];

            foreach ($desiredWindows as $desiredWindow) {
                $window = $existingWindows->first(
                    fn (TimeWindow $candidate) => $this->windowKey(
                        $candidate->start_time,
                        $candidate->end_time,
                    ) === $this->windowKey(
                        $desiredWindow['start_time'],
                        $desiredWindow['end_time'],
                    ),
                );

                if (! $window) {
                    $window = TimeWindow::create([
                        ...$desiredWindow,
                        'max_slots' => self::DEFAULT_MAX_SLOTS,
                        'is_active' => true,
                    ]);
                    $existingWindows->push($window);
                } else {
                    $window->update([
                        'window_label' => $desiredWindow['window_label'],
                        'is_active' => true,
                    ]);
                }

                $activeWindowIds[] = $window->window_id;
            }

            $windowsToDeactivate = TimeWindow::query();

            if ($activeWindowIds !== []) {
                $windowsToDeactivate->whereNotIn('window_id', $activeWindowIds);
            }

            $windowsToDeactivate->update(['is_active' => false]);
        });
    }

    /**
     * @return Collection<int, TimeWindow>
     */
    public function availableWindows(ClinicSetting $settings, string $service): Collection
    {
        $allowedKeys = collect($settings->desiredTimeWindows($service))
            ->mapWithKeys(fn (array $window) => [
                $this->windowKey($window['start_time'], $window['end_time']) => true,
            ]);

        return TimeWindow::query()
            ->where('is_active', true)
            ->orderBy('start_time')
            ->get()
            ->filter(fn (TimeWindow $window) => $allowedKeys->has(
                $this->windowKey($window->start_time, $window->end_time),
            ))
            ->values();
    }

    private function windowKey(string $startTime, string $endTime): string
    {
        return substr($startTime, 0, 8).'-'.substr($endTime, 0, 8);
    }
}
