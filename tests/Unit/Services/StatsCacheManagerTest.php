<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\Stats\StatsCacheManager;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class StatsCacheManagerTest extends TestCase
{
    public function test_clear_volume_stats_clears_correct_keys(): void
    {
        $user = User::factory()->make(['id' => 123]);

        // Expectation: Volume related keys are cleared
        Cache::shouldReceive('forget')->once()->with("stats.weekly_volume.{$user->id}");
        Cache::shouldReceive('forget')->once()->with("stats.dashboard_analytical.{$user->id}");
        Cache::shouldReceive('forget')->once()->with(Mockery::on(fn ($key): bool => str_starts_with((string) $key, "stats.weekly_volume_comparison.{$user->id}")));
        /*
         * L'assertion sur `stats.monthly_volume_comparison` est partie : elle
         * verifiait qu'on oublie une entree que personne n'ecrit jamais (#1502).
         */
        Cache::shouldReceive('forget')->once()->with("stats.monthly_volume_history.{$user->id}.6");

        foreach ([7, 30, 90, 365] as $days) {
            Cache::shouldReceive('forget')->once()->with("stats.volume_trend.{$user->id}.{$days}");
            Cache::shouldReceive('forget')->once()->with("stats.performance_overview.{$user->id}.{$days}");
            Cache::shouldReceive('forget')->once()->with("stats.muscle_dist.{$user->id}.{$days}");
        }

        Cache::shouldReceive('put')->once()->with("stats.1rm_version.{$user->id}", Mockery::any(), Mockery::any());

        Cache::shouldReceive('forget')->once()->with("stats.volume_history.{$user->id}.20");
        Cache::shouldReceive('forget')->once()->with("stats.volume_history.{$user->id}.30");

        app(StatsCacheManager::class)->clearVolumeStats($user);
    }

    public function test_clear_duration_stats_clears_correct_keys(): void
    {
        $user = User::factory()->make(['id' => 123]);

        // Expectation: Duration related keys are cleared
        // Les deux bornes, pas seulement 20 : `getPerformanceOverview()` ecrit
        // aussi l'entree a 30, qui n'etait jamais invalidee (#1502).
        Cache::shouldReceive('forget')->once()->with("stats.duration_history.{$user->id}.20");
        Cache::shouldReceive('forget')->once()->with("stats.duration_history.{$user->id}.30");
        Cache::shouldReceive('forget')->once()->with("stats.workout_distributions.{$user->id}.90");
        Cache::shouldReceive('forget')->once()->with("stats.dashboard_analytical.{$user->id}");

        foreach ([7, 30, 90, 365] as $days) {
            Cache::shouldReceive('forget')->once()->with("stats.performance_overview.{$user->id}.{$days}");
        }

        app(StatsCacheManager::class)->clearDurationStats($user);
    }

    public function test_clear_workout_related_stats_clears_everything(): void
    {
        $user = User::factory()->make(['id' => 123]);

        // Expectation: Everything related to workouts is cleared
        Cache::shouldReceive('forget')->atLeast()->once();
        Cache::shouldReceive('put')->atLeast()->once();

        app(StatsCacheManager::class)->clearWorkoutRelatedStats($user);
    }

    public function test_clear_body_measurement_stats_clears_correct_keys(): void
    {
        $user = User::factory()->make(['id' => 123]);

        // Expectation: Body measurement keys are cleared
        Cache::shouldReceive('forget')->once()->with("stats.latest_metrics.{$user->id}");

        foreach ([7, 30, 90, 365] as $days) {
            Cache::shouldReceive('forget')->once()->with("stats.body_progress.{$user->id}.{$days}");
        }

        app(StatsCacheManager::class)->clearBodyMeasurementStats($user);
    }

    public function test_clear_workout_metadata_stats_clears_correct_keys(): void
    {
        $user = User::factory()->make(['id' => 123]);

        Cache::shouldReceive('forget')->once()->with("stats.volume_history.{$user->id}.20");
        Cache::shouldReceive('forget')->once()->with("stats.volume_history.{$user->id}.30");
        Cache::shouldReceive('forget')->once()->with("stats.duration_history.{$user->id}.20");

        foreach ([7, 30, 90, 365] as $days) {
            Cache::shouldReceive('forget')->once()->with("stats.volume_trend.{$user->id}.{$days}");
        }

        app(StatsCacheManager::class)->clearWorkoutMetadataStats($user);
    }
}
