<?php

declare(strict_types=1);

use App\Actions\Workouts\CreateWorkoutAction;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

it('creates a workout with current date and clears caches', function (): void {
    Carbon::setTestNow('2024-05-15 14:00:00');

    $user = User::factory()->create();

    // The StatsService class is final, and Mockery can't intercept its methods directly
    // We test the cache invalidation by asserting on the cache state which StatsCacheManager alters.

    // Seed caches that should be cleared by clearWorkoutRelatedStats & clearWorkoutMetadataStats
    Cache::put("stats.weekly_volume.{$user->id}", ['some_data'], 600);
    Cache::put("stats.volume_trend.{$user->id}.30", ['some_data'], 600);
    Cache::put("stats.volume_history.{$user->id}.20", ['some_data'], 600);
    Cache::put("stats.duration_history.{$user->id}.20", ['some_data'], 600);

    $action = app(CreateWorkoutAction::class);
    $workout = $action->execute($user);

    expect($workout)->toBeInstanceOf(Workout::class)
        ->and($workout->user_id)->toBe($user->id)
        ->and($workout->name)->toBe('Séance du 15/05/2024')
        ->and($workout->started_at->toDateTimeString())->toBe('2024-05-15 14:00:00');

    $this->assertDatabaseHas('workouts', [
        'id' => $workout->id,
        'user_id' => $user->id,
        'name' => 'Séance du 15/05/2024',
        'started_at' => '2024-05-15 14:00:00',
    ]);

    // Verify caches are cleared
    expect(Cache::has("stats.weekly_volume.{$user->id}"))->toBeFalse()
        ->and(Cache::has("stats.volume_trend.{$user->id}.30"))->toBeFalse()
        ->and(Cache::has("stats.volume_history.{$user->id}.20"))->toBeFalse()
        ->and(Cache::has("stats.duration_history.{$user->id}.20"))->toBeFalse();
});
