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
        Cache::shouldReceive('forget')->once()->with(Mockery::on(fn ($key): bool => str_starts_with((string) $key, "stats.weekly_volume_comparison.{$user->id}")));
        Cache::shouldReceive('forget')->once()->with("stats.monthly_volume_comparison.{$user->id}");

        foreach ([7, 30, 90, 365] as $days) {
            Cache::shouldReceive('forget')->once()->with("stats.volume_trend.{$user->id}.{$days}");
            Cache::shouldReceive('forget')->once()->with("stats.daily_volume.{$user->id}.{$days}");
            Cache::shouldReceive('forget')->once()->with("stats.weight_history.{$user->id}.{$days}");
            Cache::shouldReceive('forget')->once()->with("stats.body_fat_history.{$user->id}.{$days}");
        }

        Cache::shouldReceive('forget')->once()->with("stats.duration_history.{$user->id}.20");
        Cache::shouldReceive('forget')->once()->with("stats.volume_history.{$user->id}.20");
        Cache::shouldReceive('forget')->once()->with("stats.volume_history.{$user->id}.30");
        Cache::shouldReceive('forget')->once()->with("stats.duration_distribution.{$user->id}.90");
        Cache::shouldReceive('forget')->once()->with("stats.monthly_volume_history.{$user->id}.6");
        Cache::shouldReceive('forget')->once()->with("stats.muscle_dist.{$user->id}.30");
        Cache::shouldReceive('forget')->once()->with("stats.muscle_dist.{$user->id}.7");

        $this->statsService->clearWorkoutRelatedStats($user);
    }

    public function test_clear_body_measurement_stats_clears_correct_keys(): void
    {
        $user = User::factory()->make(['id' => 123]);

        // Expectation: Body measurement keys are cleared
        foreach ([7, 30, 90, 365] as $days) {
            Cache::shouldReceive('forget')->once()->with("stats.weight_history.{$user->id}.{$days}");
            Cache::shouldReceive('forget')->once()->with("stats.body_fat_history.{$user->id}.{$days}");
        }

        $this->statsService->clearBodyMeasurementStats($user);
    }

    public function test_clear_user_stats_cache_clears_all_keys(): void
    {
        $user = User::factory()->make(['id' => 123]);

        // Expect everything to be cleared (called from clearUserStatsCache which calls both)
        Cache::shouldReceive('forget')->once()->with("stats.weekly_volume.{$user->id}");
        Cache::shouldReceive('forget')->once()->with(Mockery::on(fn ($key): bool => str_starts_with((string) $key, "stats.weekly_volume_comparison.{$user->id}")));
        Cache::shouldReceive('forget')->once()->with("stats.monthly_volume_comparison.{$user->id}");

        foreach ([7, 30, 90, 365] as $days) {
            Cache::shouldReceive('forget')->once()->with("stats.volume_trend.{$user->id}.{$days}");
            Cache::shouldReceive('forget')->once()->with("stats.daily_volume.{$user->id}.{$days}");
            Cache::shouldReceive('forget')->twice()->with("stats.weight_history.{$user->id}.{$days}");
            Cache::shouldReceive('forget')->twice()->with("stats.body_fat_history.{$user->id}.{$days}");
        }

        Cache::shouldReceive('forget')->once()->with("stats.duration_history.{$user->id}.20");
        Cache::shouldReceive('forget')->once()->with("stats.volume_history.{$user->id}.20");
        Cache::shouldReceive('forget')->once()->with("stats.volume_history.{$user->id}.30");
        Cache::shouldReceive('forget')->once()->with("stats.duration_distribution.{$user->id}.90");
        Cache::shouldReceive('forget')->once()->with("stats.monthly_volume_history.{$user->id}.6");
        Cache::shouldReceive('forget')->once()->with("stats.muscle_dist.{$user->id}.30");
        Cache::shouldReceive('forget')->once()->with("stats.muscle_dist.{$user->id}.7");

        $this->statsService->clearUserStatsCache($user);
    }
}
