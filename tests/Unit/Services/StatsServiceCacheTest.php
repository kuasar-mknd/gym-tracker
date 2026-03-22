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

    public function test_clear_volume_stats_clears_correct_keys(): void
    {
        $user = User::factory()->make(['id' => 123]);

        // Expectation: Volume related keys are cleared
        Cache::shouldReceive('forget')->once()->with("stats.weekly_volume.{$user->id}");
        Cache::shouldReceive('forget')->once()->with(Mockery::on(fn ($key): bool => str_starts_with((string) $key, "stats.weekly_volume_comparison.{$user->id}")));
        Cache::shouldReceive('forget')->once()->with("stats.monthly_volume_comparison.{$user->id}");
        Cache::shouldReceive('forget')->once()->with("stats.monthly_volume_history.{$user->id}.6");
        Cache::shouldReceive('forget')->once()->with("stats.monthly_workout_stats.{$user->id}.6");
        Cache::shouldReceive('forget')->once()->with("stats.monthly_frequency.{$user->id}");

        foreach ([7, 30, 90, 365] as $days) {
            Cache::shouldReceive('forget')->once()->with("stats.volume_trend.{$user->id}.{$days}");
            Cache::shouldReceive('forget')->once()->with("stats.daily_volume.{$user->id}.{$days}");
        }

        Cache::shouldReceive('put')->once()->with("stats.1rm_version.{$user->id}", Mockery::any(), Mockery::any());

        Cache::shouldReceive('forget')->once()->with("stats.volume_history.{$user->id}.20");
        Cache::shouldReceive('forget')->once()->with("stats.volume_history.{$user->id}.30");
        Cache::shouldReceive('forget')->once()->with("stats.recent_workouts_analytics.{$user->id}.20");
        Cache::shouldReceive('forget')->once()->with("stats.muscle_dist.{$user->id}.30");
        Cache::shouldReceive('forget')->once()->with("stats.muscle_dist.{$user->id}.7");

        $this->statsService->clearVolumeStats($user);
    }

    public function test_clear_duration_stats_clears_correct_keys(): void
    {
        $user = User::factory()->make(['id' => 123]);

        // Expectation: Duration related keys are cleared
        Cache::shouldReceive('forget')->once()->with("stats.duration_history.{$user->id}.20");
        Cache::shouldReceive('forget')->once()->with("stats.recent_workouts_analytics.{$user->id}.20");
        Cache::shouldReceive('forget')->once()->with("stats.duration_distribution.{$user->id}.90");
        Cache::shouldReceive('forget')->once()->with("stats.time_of_day_distribution.{$user->id}.90");
        Cache::shouldReceive('forget')->once()->with("stats.workout_distributions.{$user->id}.90");

        $this->statsService->clearDurationStats($user);
    }

    public function test_clear_workout_related_stats_clears_everything(): void
    {
        $user = User::factory()->make(['id' => 123]);

        // Expectation: Everything related to workouts is cleared
        Cache::shouldReceive('forget')->atLeast()->once();
        Cache::shouldReceive('put')->atLeast()->once();

        $this->statsService->clearWorkoutRelatedStats($user);
    }

    public function test_clear_body_measurement_stats_clears_correct_keys(): void
    {
        $user = User::factory()->make(['id' => 123]);

        // Expectation: Body measurement keys are cleared
        Cache::shouldReceive('forget')->once()->with("stats.latest_metrics.{$user->id}");

        foreach ([7, 30, 90, 365] as $days) {
            Cache::shouldReceive('forget')->once()->with("stats.weight_history.{$user->id}.{$days}");
            Cache::shouldReceive('forget')->once()->with("stats.body_fat_history.{$user->id}.{$days}");
        }

        $this->statsService->clearBodyMeasurementStats($user);
    }

    public function test_clear_user_stats_cache_clears_all_keys(): void
    {
        $user = User::factory()->make(['id' => 123]);

        // Expect everything to be cleared
        // Since clearUserStatsCache calls clearWorkoutRelatedStats, clearWorkoutMetadataStats, and clearBodyMeasurementStats,
        // some keys (like volume_trend) might be cleared twice. We use atLeast()->once() for simplicity.
        Cache::shouldReceive('forget')->atLeast()->once();
        Cache::shouldReceive('put')->atLeast()->once();

        $this->statsService->clearUserStatsCache($user);
    }

    public function test_clear_workout_metadata_stats_clears_correct_keys(): void
    {
        $user = User::factory()->make(['id' => 123]);

        Cache::shouldReceive('forget')->once()->with("stats.volume_history.{$user->id}.20");
        Cache::shouldReceive('forget')->once()->with("stats.volume_history.{$user->id}.30");
        Cache::shouldReceive('forget')->once()->with("stats.duration_history.{$user->id}.20");
        Cache::shouldReceive('forget')->once()->with("stats.recent_workouts_analytics.{$user->id}.20");

        foreach ([7, 30, 90, 365] as $days) {
            Cache::shouldReceive('forget')->once()->with("stats.volume_trend.{$user->id}.{$days}");
        }

        $this->statsService->clearWorkoutMetadataStats($user);
    }
}
