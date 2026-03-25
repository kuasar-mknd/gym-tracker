<?php

declare(strict_types=1);

use App\Actions\Workouts\CreateWorkoutAction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

it('creates a workout and clears related stats', function (): void {
    $user = User::factory()->create();

    // Freeze time to ensure assertions are deterministic
    $now = now();
    $this->travelTo($now);

    // We cannot mock StatsService directly because it is marked as final.
    // Since both StatsService and StatsCacheManager are final, the easiest and most robust
    // way to test this without changing application code is to verify the actual caches are cleared.
    // We populate cache keys that should be cleared by the action.
    Cache::put("stats.duration_history.{$user->id}.20", 'data');
    Cache::put("stats.volume_history.{$user->id}.20", 'data');
    Cache::put("stats.daily_volume.{$user->id}.30", 'data');

    // Ensure cache has data
    expect(Cache::has("stats.duration_history.{$user->id}.20"))->toBeTrue()
        ->and(Cache::has("stats.volume_history.{$user->id}.20"))->toBeTrue()
        ->and(Cache::has("stats.daily_volume.{$user->id}.30"))->toBeTrue();

    $action = app(CreateWorkoutAction::class);
    $workout = $action->execute($user);

    // Verify workout was created correctly
    expect($workout->user_id)->toBe($user->id)
        ->and($workout->started_at->timestamp)->toBe($now->timestamp)
        ->and($workout->name)->toBe('Séance du '.$now->format('d/m/Y'));

    // Verify cache was cleared by StatsCacheManager (called via StatsService)
    expect(Cache::has("stats.duration_history.{$user->id}.20"))->toBeFalse()
        ->and(Cache::has("stats.volume_history.{$user->id}.20"))->toBeFalse()
        ->and(Cache::has("stats.daily_volume.{$user->id}.30"))->toBeFalse();
});
