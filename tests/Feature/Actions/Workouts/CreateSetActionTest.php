<?php

declare(strict_types=1);

use App\Actions\Workouts\CreateSetAction;
use App\Models\Set;
use App\Models\User;
use App\Models\WorkoutLine;
use Illuminate\Support\Facades\Cache;

it('creates a set and clears volume stats', function (): void {
    $user = User::factory()->create();
    $workoutLine = WorkoutLine::factory()->create();

    Cache::spy();

    $action = app(CreateSetAction::class);
    $set = $action->execute($user, $workoutLine, [
        'weight' => 100,
        'reps' => 10,
        'workout_line_id' => 999, // Should be ignored
    ]);

    expect($set)->toBeInstanceOf(Set::class)
        ->and($set->weight)->toBe(100.0)
        ->and($set->reps)->toBe(10)
        ->and($set->workout_line_id)->toBe($workoutLine->id)
        ->and($set->workout_line_id)->not->toBe(999);

    Cache::shouldHaveReceived('forget')->with("stats.weekly_volume.{$user->id}");
});

it('creates a set with only required fields', function (): void {
    $user = User::factory()->create();
    $workoutLine = WorkoutLine::factory()->create();

    Cache::spy();

    $action = app(CreateSetAction::class);
    $set = $action->execute($user, $workoutLine, [
        'reps' => 5,
    ]);

    expect($set)->toBeInstanceOf(Set::class)
        ->and($set->reps)->toBe(5)
        ->and($set->weight)->toBeNull()
        ->and($set->workout_line_id)->toBe($workoutLine->id);

    Cache::shouldHaveReceived('forget')->with("stats.weekly_volume.{$user->id}");
});
