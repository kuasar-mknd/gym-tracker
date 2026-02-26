<?php

declare(strict_types=1);

use App\Actions\Workouts\UpdateWorkoutAction;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Facades\Cache;

it('clears only metadata caches when name is updated', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => now(),
        'name' => 'Old Name',
    ]);

    // Seed caches
    Cache::put("stats.weekly_volume.{$user->id}", ['some_data'], 600);
    Cache::put("stats.volume_trend.{$user->id}.30", ['some_data'], 600);

    // Assert seeded
    expect(Cache::has("stats.weekly_volume.{$user->id}"))->toBeTrue();
    expect(Cache::has("stats.volume_trend.{$user->id}.30"))->toBeTrue();

    // Execute Action
    $action = app(UpdateWorkoutAction::class);
    $action->execute($workout, ['name' => 'New Name']);

    // Assert Metadata Cache is NOT cleared in the surgical approach
    // We intentionally keep volume trend cached (even if it has old name) to prefer performance.
    expect(Cache::has("stats.volume_trend.{$user->id}.30"))->toBeTrue();

    // Assert Aggregation Cache is PRESERVED
    expect(Cache::has("stats.weekly_volume.{$user->id}"))->toBeTrue();
});

it('clears all caches when date is updated', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => now(),
    ]);

    // Seed caches
    Cache::put("stats.weekly_volume.{$user->id}", ['some_data'], 600);
    Cache::put("stats.volume_trend.{$user->id}.30", ['some_data'], 600);

    // Execute Action
    $action = app(UpdateWorkoutAction::class);
    $action->execute($workout, ['started_at' => now()->subDay()->toDateTimeString()]);

    // Assert ALL CLEARED
    expect(Cache::has("stats.volume_trend.{$user->id}.30"))->toBeFalse();
    expect(Cache::has("stats.weekly_volume.{$user->id}"))->toBeFalse();
});
