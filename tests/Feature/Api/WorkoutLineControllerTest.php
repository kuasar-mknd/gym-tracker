<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;

use function Pest\Laravel\actingAs;

test('store creates workout line', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $exercise = Exercise::factory()->create();

    $data = [
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
        'notes' => 'Test Note',
    ];

    actingAs($user)
        ->postJson(route('api.v1.workout-lines.store'), $data)
        ->assertCreated()
        ->assertJsonFragment(['notes' => 'Test Note'])
        ->assertJsonFragment(['order' => 0]);

    expect($workout->workoutLines()->count())->toBe(1);
});

test('store validates workout ownership', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $exercise = Exercise::factory()->create();

    actingAs($user)
        ->postJson(route('api.v1.workout-lines.store'), [
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['workout_id']);
});

test('destroy deletes workout line', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    actingAs($user)
        ->deleteJson(route('api.v1.workout-lines.destroy', $line))
        ->assertNoContent();

    expect(WorkoutLine::find($line->id))->toBeNull();
});
