<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\StatsService;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class StatsServiceCacheTest extends TestCase
{
    protected StatsService $statsService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->statsService = new StatsService();
    }

    public function test_clear_workout_related_stats_clears_correct_keys(): void
    {
        $user = User::factory()->make(['id' => 123]);

        // Expectation: Workout related keys are cleared
        Cache::shouldReceive('forget')->once()->with("stats.weekly_volume.{$user->id}");
        Cache::shouldReceive('forget')->once()->with(Mockery::on(fn ($key) => str_starts_with($key, "stats.weekly_volume_comparison.{$user->id}")));
        Cache::shouldReceive('forget')->once()->with("stats.monthly_volume_comparison.{$user->id}");
        Cache::shouldReceive('forget')->once()->with("dashboard_data_{$user->id}");

        $periods = [7, 30, 90, 365];
        foreach ($periods as $days) {
            Cache::shouldReceive('forget')->once()->with("stats.volume_trend.{$user->id}.{$days}");
            Cache::shouldReceive('forget')->once()->with("stats.daily_volume.{$user->id}.{$days}");
            Cache::shouldReceive('forget')->once()->with("stats.muscle_dist.{$user->id}.{$days}");
        }

        Cache::shouldReceive('forget')->once()->with("stats.duration_history.{$user->id}.20");
        Cache::shouldReceive('forget')->once()->with("stats.duration_history.{$user->id}.30");
        Cache::shouldReceive('forget')->once()->with("stats.volume_history.{$user->id}.20");
        Cache::shouldReceive('forget')->once()->with("stats.volume_history.{$user->id}.30");
        Cache::shouldReceive('forget')->once()->with("stats.duration_distribution.{$user->id}.90");
        Cache::shouldReceive('forget')->once()->with("stats.monthly_volume_history.{$user->id}.6");

        // Expectation: Body measurement keys are NOT cleared
        // We can't easily assert "never" for specific keys while allowing others with a partial mock on Facade easily
        // without defining all expected calls.
        // But since we defined all 'once' expectations above, any extra call would fail if we were using 'with' strictly?
        // Actually, partial mocking 'forget' means we strictly define what calls are expected.
        // Any call to 'forget' that doesn't match an expectation will fail the test.
        // So checking for absence is implicit if we don't expect it.

        // However, to be safe and explicit about NOT clearing body stats:
        // We cannot add negative assertions easily if we mocked the method to expect specific calls.
        // If the code calls `forget('stats.weight_history...')`, it will fail because it wasn't expected.

        $this->statsService->clearWorkoutRelatedStats($user);
    }

    public function test_clear_body_measurement_stats_clears_correct_keys(): void
    {
        $user = User::factory()->make(['id' => 123]);

        // Expectation: Body measurement keys are cleared
        Cache::shouldReceive('forget')->once()->with("dashboard_data_{$user->id}");

        $periods = [7, 30, 90, 365];
        foreach ($periods as $days) {
            Cache::shouldReceive('forget')->once()->with("stats.weight_history.{$user->id}.{$days}");
            Cache::shouldReceive('forget')->once()->with("stats.body_fat_history.{$user->id}.{$days}");
        }

        // Implicitly asserts workout keys are NOT cleared because they are not in expectations.

        $this->statsService->clearBodyMeasurementStats($user);
    }

    public function test_clear_user_stats_cache_clears_all_keys(): void
    {
        $user = User::factory()->make(['id' => 123]);

        // Expect everything to be cleared
        Cache::shouldReceive('forget')->once()->with("stats.weekly_volume.{$user->id}");
        Cache::shouldReceive('forget')->once()->with(Mockery::on(fn ($key) => str_starts_with($key, "stats.weekly_volume_comparison.{$user->id}")));
        Cache::shouldReceive('forget')->once()->with("stats.monthly_volume_comparison.{$user->id}");
        Cache::shouldReceive('forget')->times(2)->with("dashboard_data_{$user->id}"); // Called by both sub-methods

        $periods = [7, 30, 90, 365];
        foreach ($periods as $days) {
            Cache::shouldReceive('forget')->once()->with("stats.volume_trend.{$user->id}.{$days}");
            Cache::shouldReceive('forget')->once()->with("stats.daily_volume.{$user->id}.{$days}");
            Cache::shouldReceive('forget')->once()->with("stats.muscle_dist.{$user->id}.{$days}");
            Cache::shouldReceive('forget')->once()->with("stats.weight_history.{$user->id}.{$days}");
            Cache::shouldReceive('forget')->once()->with("stats.body_fat_history.{$user->id}.{$days}");
        }

        Cache::shouldReceive('forget')->once()->with("stats.duration_history.{$user->id}.20");
        Cache::shouldReceive('forget')->once()->with("stats.duration_history.{$user->id}.30");
        Cache::shouldReceive('forget')->once()->with("stats.volume_history.{$user->id}.20");
        Cache::shouldReceive('forget')->once()->with("stats.volume_history.{$user->id}.30");
        Cache::shouldReceive('forget')->once()->with("stats.duration_distribution.{$user->id}.90");
        Cache::shouldReceive('forget')->once()->with("stats.monthly_volume_history.{$user->id}.6");

        $this->statsService->clearUserStatsCache($user);
    }
}
