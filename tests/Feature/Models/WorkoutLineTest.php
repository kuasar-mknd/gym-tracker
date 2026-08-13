<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\postJson;

test('store adds a line to workout', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $exercise = Exercise::factory()->create();

    Sanctum::actingAs($user);

    postJson(route('api.v1.workout-lines.store'), [
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ])->assertCreated();

    assertDatabaseHas('workout_lines', [
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
        'order' => 0,
    ]);
});

test('store adds a line with correct order', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $exercise1 = Exercise::factory()->create();
    $exercise2 = Exercise::factory()->create();

    Sanctum::actingAs($user);

    postJson(route('api.v1.workout-lines.store'), [
        'workout_id' => $workout->id,
        'exercise_id' => $exercise1->id,
    ])->assertCreated();

    postJson(route('api.v1.workout-lines.store'), [
        'workout_id' => $workout->id,
        'exercise_id' => $exercise2->id,
    ])->assertCreated();

    assertDatabaseHas('workout_lines', [
        'workout_id' => $workout->id,
        'exercise_id' => $exercise1->id,
        'order' => 0,
    ]);

    assertDatabaseHas('workout_lines', [
        'workout_id' => $workout->id,
        'exercise_id' => $exercise2->id,
        'order' => 1,
    ]);
});

/**
 * The web route answered 403 here, from the policy. The API rejects the same
 * attempt one layer earlier: `workout_id` only accepts the caller's own
 * workouts, so an outsider's workout is simply not a valid value.
 */
test('store refuses adding line to another users workout', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $exercise = Exercise::factory()->create();

    Sanctum::actingAs($user);

    postJson(route('api.v1.workout-lines.store'), [
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ])->assertJsonValidationErrors('workout_id');

    assertDatabaseCount('workout_lines', 0);
});

test('store requires valid exercise_id', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);

    Sanctum::actingAs($user);

    postJson(route('api.v1.workout-lines.store'), [
        'workout_id' => $workout->id,
        'exercise_id' => 99999, // Non-existent
    ])->assertJsonValidationErrors('exercise_id');

    assertDatabaseCount('workout_lines', 0);
});

test('store forbids adding another users private exercise', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $privateExercise = Exercise::factory()->create(['user_id' => $otherUser->id]);

    Sanctum::actingAs($user);

    postJson(route('api.v1.workout-lines.store'), [
        'workout_id' => $workout->id,
        'exercise_id' => $privateExercise->id,
    ])->assertJsonValidationErrors('exercise_id');

    assertDatabaseCount('workout_lines', 0);
});

test('destroy removes a workout line', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    Sanctum::actingAs($user);

    deleteJson(route('api.v1.workout-lines.destroy', $line))->assertNoContent();

    assertDatabaseMissing('workout_lines', ['id' => $line->id]);
});

test('destroy forbids removing another users workout line', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    Sanctum::actingAs($user);

    deleteJson(route('api.v1.workout-lines.destroy', $line))->assertForbidden();

    assertDatabaseHas('workout_lines', ['id' => $line->id]);
});
