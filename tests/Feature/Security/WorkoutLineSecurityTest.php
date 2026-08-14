<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\postJson;

test('security: user cannot add another users private exercise to their workout', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $workout = Workout::factory()->create(['user_id' => $user->id]);

    // Create a private exercise for otherUser
    $privateExercise = Exercise::factory()->create([
        'user_id' => $otherUser->id,
        'name' => 'Secret Exercise',
    ]);

    Sanctum::actingAs($user);

    postJson(route('api.v1.workout-lines.store'), [
        'workout_id' => $workout->id,
        'exercise_id' => $privateExercise->id,
    ])->assertJsonValidationErrors(['exercise_id']);

    assertDatabaseCount('workout_lines', 0);
});

test('security: user can add their own private exercise', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);

    // Create a private exercise for user
    $privateExercise = Exercise::factory()->create([
        'user_id' => $user->id,
        'name' => 'My Special Exercise',
    ]);

    Sanctum::actingAs($user);

    postJson(route('api.v1.workout-lines.store'), [
        'workout_id' => $workout->id,
        'exercise_id' => $privateExercise->id,
    ])->assertCreated();

    assertDatabaseHas('workout_lines', [
        'workout_id' => $workout->id,
        'exercise_id' => $privateExercise->id,
    ]);
});

test('security: user can add system exercise', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);

    // Create a system exercise (null user_id)
    $systemExercise = Exercise::factory()->create([
        'user_id' => null,
        'name' => 'Push Ups',
    ]);

    Sanctum::actingAs($user);

    postJson(route('api.v1.workout-lines.store'), [
        'workout_id' => $workout->id,
        'exercise_id' => $systemExercise->id,
    ])->assertCreated();

    assertDatabaseHas('workout_lines', [
        'workout_id' => $workout->id,
        'exercise_id' => $systemExercise->id,
    ]);
});
