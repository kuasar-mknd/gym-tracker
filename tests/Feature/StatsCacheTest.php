<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workout;
use App\Services\StatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class StatsCacheTest extends TestCase
{
    use RefreshDatabase;

    protected StatsService $statsService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->statsService = app(StatsService::class);
    }

    public function test_weekly_volume_comparison_is_cached(): void
    {
        $user = User::factory()->create();
        $weekKey = now()->startOfWeek()->format('Y-W');
        $key = "stats.weekly_volume_comparison.{$user->id}.{$weekKey}";

        $this->assertFalse(Cache::has($key));

        $this->statsService->getWeeklyVolumeComparison($user);

        $this->assertTrue(Cache::has($key));
    }

    public function test_updating_workout_notes_does_not_clear_trend_cache(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'name' => 'Original Name',
            'notes' => 'Original Notes',
            'started_at' => now()->subDay(),
        ]);

        // Fill caches
        $trendKey = "stats.volume_trend.{$user->id}.30";

        Cache::put($trendKey, ['data'], 600);

        $this->assertTrue(Cache::has($trendKey));

        // Update ONLY notes
        $this->actingAs($user)->patch(route('workouts.update', $workout), [
            'notes' => 'Updated Notes',
        ]);

        $this->assertTrue(Cache::has($trendKey), 'Volume trend cache should NOT be cleared when only notes change');
    }

    public function test_updating_workout_name_clears_metadata_stats(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'name' => 'Original Name',
        ]);

        // Fill caches
        $trendKey = "stats.volume_trend.{$user->id}.30";
        $muscleKey = "stats.muscle_dist.{$user->id}.30";

        Cache::put($trendKey, ['data'], 600);
        Cache::put($muscleKey, ['data'], 600);

        // Update Name
        $this->actingAs($user)->patch(route('workouts.update', $workout), [
            'name' => 'New Name',
        ]);

        $this->assertFalse(Cache::has($trendKey), 'Volume trend cache should be cleared when name changes');
        $this->assertTrue(Cache::has($muscleKey), 'Muscle distribution cache should NOT be cleared when only name changes');
    }

    public function test_updating_workout_date_clears_all_stats(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subDay(),
        ]);

        // Fill caches
        $trendKey = "stats.volume_trend.{$user->id}.30";
        $muscleKey = "stats.muscle_dist.{$user->id}.30";

        Cache::put($trendKey, ['data'], 600);
        Cache::put($muscleKey, ['data'], 600);

        // Update Date
        $this->actingAs($user)->patch(route('workouts.update', $workout), [
            'started_at' => now()->subDays(2)->toDateTimeString(),
        ]);

        $this->assertFalse(Cache::has($trendKey), 'Volume trend cache should be cleared');
        $this->assertFalse(Cache::has($muscleKey), 'Muscle distribution cache should be cleared when date changes');
    }

    public function test_updating_workout_does_not_clear_body_measurement_cache(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subDay(),
        ]);

        $weightKey = "stats.weight_history.{$user->id}.90";
        Cache::put($weightKey, ['data'], 600);

        $this->assertTrue(Cache::has($weightKey));

        // Update workout DATE (causes full clear)
        $this->actingAs($user)->patch(route('workouts.update', $workout), [
            'started_at' => now()->subDays(2)->toDateTimeString(),
        ]);

        $this->assertTrue(Cache::has($weightKey), 'Body measurement cache should NOT be cleared even with a full workout cache clear');
    }

    public function test_clear_workout_related_stats_invalidates_1rm_cache(): void
    {
        $user = User::factory()->create();
        $exerciseId = 1;

        // Get initial 1RM progress (fills cache)
        $this->statsService->getExercise1RMProgress($user, $exerciseId);
        $version = Cache::get("stats.1rm_version.{$user->id}", '1');
        $key = "stats.1rm.{$user->id}.{$exerciseId}.90.v{$version}";

        $this->assertTrue(Cache::has($key));

        // Clear workout related stats
        $this->statsService->clearWorkoutRelatedStats($user);

        // Check if version changed
        $newVersion = Cache::get("stats.1rm_version.{$user->id}");
        $this->assertNotEquals($version, $newVersion);

        $newKey = "stats.1rm.{$user->id}.{$exerciseId}.90.v{$newVersion}";
        $this->assertFalse(Cache::has($newKey));
    }
}
