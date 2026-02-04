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

        // These are called twice (once by clearWorkoutNameDependentStats, once by clearWorkoutDurationDependentStats)
        Cache::shouldReceive('forget')->times(2)->with("stats.duration_history.{$user->id}.20");
        Cache::shouldReceive('forget')->times(2)->with("stats.duration_history.{$user->id}.30");
        Cache::shouldReceive('forget')->times(2)->with("stats.volume_history.{$user->id}.20");
        Cache::shouldReceive('forget')->times(2)->with("stats.volume_history.{$user->id}.30");

        Cache::shouldReceive('forget')->once()->with("stats.duration_distribution.{$user->id}.90");
        Cache::shouldReceive('forget')->once()->with("stats.monthly_volume_history.{$user->id}.6");

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

        // Overlapping keys
        Cache::shouldReceive('forget')->times(2)->with("stats.duration_history.{$user->id}.20");
        Cache::shouldReceive('forget')->times(2)->with("stats.duration_history.{$user->id}.30");
        Cache::shouldReceive('forget')->times(2)->with("stats.volume_history.{$user->id}.20");
        Cache::shouldReceive('forget')->times(2)->with("stats.volume_history.{$user->id}.30");

        Cache::shouldReceive('forget')->once()->with("stats.duration_distribution.{$user->id}.90");
        Cache::shouldReceive('forget')->once()->with("stats.monthly_volume_history.{$user->id}.6");

        $this->statsService->clearUserStatsCache($user);
    }

    public function test_clear_dashboard_cache_clears_correct_key(): void
    {
        $user = User::factory()->make(['id' => 123]);

        Cache::shouldReceive('forget')->once()->with("dashboard_data_{$user->id}");

        $this->statsService->clearDashboardCache($user);
    }

    public function test_clear_workout_name_dependent_stats_clears_correct_keys(): void
    {
        $user = User::factory()->make(['id' => 123]);

        $periods = [7, 30, 90, 365];
        foreach ($periods as $days) {
            Cache::shouldReceive('forget')->once()->with("stats.volume_trend.{$user->id}.{$days}");
        }

        Cache::shouldReceive('forget')->once()->with("stats.volume_history.{$user->id}.20");
        Cache::shouldReceive('forget')->once()->with("stats.volume_history.{$user->id}.30");
        Cache::shouldReceive('forget')->once()->with("stats.duration_history.{$user->id}.20");
        Cache::shouldReceive('forget')->once()->with("stats.duration_history.{$user->id}.30");

        $this->statsService->clearWorkoutNameDependentStats($user);
    }

    public function test_clear_workout_duration_dependent_stats_clears_correct_keys(): void
    {
        $user = User::factory()->make(['id' => 123]);

        Cache::shouldReceive('forget')->once()->with("stats.duration_history.{$user->id}.20");
        Cache::shouldReceive('forget')->once()->with("stats.duration_history.{$user->id}.30");
        Cache::shouldReceive('forget')->once()->with("stats.volume_history.{$user->id}.20");
        Cache::shouldReceive('forget')->once()->with("stats.volume_history.{$user->id}.30");
        Cache::shouldReceive('forget')->once()->with("stats.duration_distribution.{$user->id}.90");

        $this->statsService->clearWorkoutDurationDependentStats($user);
    }
}
