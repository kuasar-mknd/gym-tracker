<?php

declare(strict_types=1);

use App\Actions\Workouts\CreateWorkoutLineAction;
use App\Models\Exercise;
use App\Models\Workout;
use App\Models\WorkoutLine;

it('sets order to 0 when no workout lines exist and order is not provided', function (): void {
    $workout = Workout::factory()->create();
    $exercise = Exercise::factory()->create();

    $action = app(CreateWorkoutLineAction::class);
    $workoutLine = $action->execute($workout, [
        'exercise_id' => $exercise->id,
    ]);

    expect($workoutLine->order)->toBe(0)
        ->and($workoutLine->workout_id)->toBe($workout->id)
        ->and($workoutLine->exercise_id)->toBe($exercise->id);
});

it('auto-increments order based on max order when order is not provided', function (): void {
    $workout = Workout::factory()->create();
    $exercise = Exercise::factory()->create();

    WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
        'order' => 2,
    ]);

    WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
        'order' => 4,
    ]);

    $action = app(CreateWorkoutLineAction::class);
    $workoutLine = $action->execute($workout, [
        'exercise_id' => $exercise->id,
    ]);

    expect($workoutLine->order)->toBe(5);
});

it('respects explicitly provided order', function (): void {
    $workout = Workout::factory()->create();
    $exercise = Exercise::factory()->create();

    WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
        'order' => 2,
    ]);

    $action = app(CreateWorkoutLineAction::class);
    $workoutLine = $action->execute($workout, [
        'exercise_id' => $exercise->id,
        'order' => 10,
    ]);

    expect($workoutLine->order)->toBe(10);
});
