<?php

declare(strict_types=1);

use App\Actions\Workouts\FetchWorkoutsIndexAction;
use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('workouts index action optimizes json payload', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create([
        'name' => 'Bench Press',
        'category' => 'Chest',
        'type' => 'strength',
        'default_rest_time' => 60
    ]);

    $workout = Workout::factory()->create(['user_id' => $user->id]);

    WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
        'notes' => 'Some heavy notes here that we do not want to load',
        'order' => 1
    ]);

    $action = app(FetchWorkoutsIndexAction::class);
    $result = $action->execute($user);

    $workouts = $result['workouts'];
    $line = $workouts->items()[0]->workoutLines->first();

    // Check WorkoutLine optimization
    expect($line->toArray())
        ->toHaveKey('id')
        ->toHaveKey('workout_id')
        ->toHaveKey('exercise_id')
        ->toHaveKey('order')
        ->toHaveKey('sets_count')
        ->not->toHaveKey('notes')
        ->not->toHaveKey('created_at')
        ->not->toHaveKey('updated_at');

    // Check Exercise optimization
    expect($line->exercise->toArray())
        ->toHaveKey('id')
        ->toHaveKey('name')
        ->not->toHaveKey('category')
        ->not->toHaveKey('type')
        ->not->toHaveKey('default_rest_time')
        ->not->toHaveKey('created_at');
});
