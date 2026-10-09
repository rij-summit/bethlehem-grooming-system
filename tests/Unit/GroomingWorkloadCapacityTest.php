<?php

namespace Tests\Unit;

use App\Models\ClinicSetting;
use App\Services\GroomingWorkloadCapacity;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GroomingWorkloadCapacityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-09 15:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function settings(int $count = 1, string $close = '17:00'): ClinicSetting
    {
        return new ClinicSetting(['groomers_on_duty' => $count, 'grooming_open_time' => '08:00', 'grooming_close_time' => $close]);
    }

    private function job(int $id, int $minutes, ?string $start = null): array
    {
        return ['id' => $id, 'minutes' => $minutes, 'active' => $start !== null, 'started_at' => $start];
    }

    #[DataProvider('laneCounts')]
    public function test_parallel_lanes_preserve_durations_and_queue_order(int $lanes): void
    {
        $jobs = array_map(fn ($id) => $this->job($id, 60), range(1, 6));
        $result = app(GroomingWorkloadCapacity::class)->project($jobs, [], $this->settings($lanes), now());
        foreach ($jobs as $index => $job) {
            $pet = $result['pets'][$job['id']];
            $expected = now()->addMinutes((int) floor($index / $lanes) * 60);
            $this->assertSame($expected->toIso8601String(), $pet['projected_start']);
            $this->assertSame($expected->addHour()->toIso8601String(), $pet['projected_completion']);
        }
    }

    public static function laneCounts(): array { return [[1], [2], [3], [4], [5]]; }

    public function test_active_remaining_work_occupies_lanes_before_waiting_and_new_arrivals(): void
    {
        $jobs = [$this->job(1, 90, '2026-10-09 14:30'), $this->job(2, 60, '2026-10-09 14:30'), $this->job(3, 45)];
        $result = app(GroomingWorkloadCapacity::class)->project($jobs, [$this->job(4, 60)], $this->settings(2), now());
        $this->assertSame('15:30', Carbon::parse($result['pets'][3]['projected_start'])->format('H:i'));
        $this->assertSame('16:00', Carbon::parse($result['pets'][4]['projected_start'])->format('H:i'));
        $this->assertTrue($result['fits']); // equality at closing is accepted
        $this->assertSame('At risk', $result['pets'][4]['state']);
    }

    public function test_reduced_count_preserves_excess_active_sessions_and_delays_future_starts(): void
    {
        $jobs = [$this->job(1, 90, '2026-10-09 14:30'), $this->job(2, 60, '2026-10-09 14:30'), $this->job(3, 60)];
        $result = app(GroomingWorkloadCapacity::class)->project($jobs, [], $this->settings(1), now());
        $this->assertSame(2, $result['in_progress']);
        $this->assertSame('15:30', Carbon::parse($result['pets'][2]['projected_completion'])->format('H:i'));
        $this->assertSame('16:00', Carbon::parse($result['pets'][3]['projected_start'])->format('H:i'));
        $this->assertSame('17:00', Carbon::parse($result['projected_last_completion'])->format('H:i'));
    }

    public function test_late_arrival_uses_window_end_and_configured_closing_not_window_start(): void
    {
        $engine = app(GroomingWorkloadCapacity::class);
        $result = $engine->project([], [$this->job(1, 60)], $this->settings(3), now(), now()->setTime(16, 30));
        $this->assertFalse($result['fits']);
        $this->assertSame('Needs staff action', $result['pets'][1]['state']);
        $this->assertTrue($engine->project([], [$this->job(1, 60)], $this->settings(3, '18:00'), now(), now()->setTime(16, 30))['fits']);
    }

    public function test_current_time_and_overdue_sessions_are_not_free_capacity(): void
    {
        $result = app(GroomingWorkloadCapacity::class)->project([$this->job(1, 90, '2026-10-09 12:00')],
            [$this->job(2, 60)], $this->settings(), now()->startOfDay());
        $this->assertSame('16:30', Carbon::parse($result['pets'][2]['projected_start'])->format('H:i'));
        $this->assertFalse($result['fits']);
    }

    public function test_more_groomers_change_capacity_without_changing_individual_durations(): void
    {
        $engine = app(GroomingWorkloadCapacity::class);
        $jobs = [$this->job(1, 90), $this->job(2, 90)];
        $this->assertFalse($engine->project($jobs, [], $this->settings(1), now())['fits']);
        $result = $engine->project($jobs, [], $this->settings(2), now());
        $this->assertTrue($result['fits']);
        $this->assertEquals(60, $result['remaining_lane_minutes']);
        $this->assertSame('16:30', Carbon::parse($result['pets'][1]['projected_completion'])->format('H:i'));
    }

    public function test_missing_estimates_do_not_silently_reserve_zero_minutes(): void
    {
        $result = app(GroomingWorkloadCapacity::class)->project([['id' => 1, 'minutes' => null, 'active' => false]],
            [$this->job(2, 60)], $this->settings(), now());
        $this->assertFalse($result['fits']);
        $this->assertNull($result['projected_last_completion']);
        $this->assertSame('Needs staff action', $result['pets'][1]['state']);
    }

    public function test_empty_and_future_workloads_start_at_configured_opening_time(): void
    {
        $result = app(GroomingWorkloadCapacity::class)->project([], [$this->job(1, 60)], $this->settings(), now()->addDay()->startOfDay());
        $this->assertSame('08:00', Carbon::parse($result['pets'][1]['projected_start'])->format('H:i'));
        $this->assertSame('On track', $result['state']);
        $this->assertTrue($result['fits']);
    }
}
