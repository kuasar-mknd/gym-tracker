<?php

declare(strict_types=1);

use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

// Happy Path Tests

test('user can add a set to their workout line', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    Sanctum::actingAs($user);

    postJson(route('api.v1.sets.store'), [
        'workout_line_id' => $workoutLine->id,
        'weight' => 100,
        'reps' => 10,
        'is_warmup' => false,
    ])->assertCreated();

    assertDatabaseHas('sets', [
        'workout_line_id' => $workoutLine->id,
        'weight' => 100,
        'reps' => 10,
        'is_warmup' => false,
    ]);
});

test('user can update their set', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    $set = Set::factory()->create([
        'workout_line_id' => $workoutLine->id,
        'weight' => 50,
        'reps' => 5,
        'is_completed' => false,
    ]);

    Sanctum::actingAs($user);

    patchJson(route('api.v1.sets.update', $set), [
        'weight' => 60,
        'reps' => 8,
        'is_completed' => true,
    ])->assertOk();

    assertDatabaseHas('sets', [
        'id' => $set->id,
        'weight' => 60,
        'reps' => 8,
        'is_completed' => true,
    ]);
});

test('user can delete their set', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    $set = Set::factory()->create([
        'workout_line_id' => $workoutLine->id,
    ]);

    Sanctum::actingAs($user);

    deleteJson(route('api.v1.sets.destroy', $set))->assertNoContent();

    assertDatabaseMissing('sets', ['id' => $set->id]);
});

// Authorization Tests

/**
 * Store scopes `workout_line_id` to the caller's own lines, so an outsider's
 * line is rejected as an invalid value (422) rather than by the policy (403).
 */
test('user cannot add set to another users workout line', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherWorkout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $otherWorkoutLine = WorkoutLine::factory()->create(['workout_id' => $otherWorkout->id]);

    Sanctum::actingAs($user);

    postJson(route('api.v1.sets.store'), [
        'workout_line_id' => $otherWorkoutLine->id,
        'weight' => 100,
        'reps' => 10,
    ])->assertStatus(422)->assertJsonValidationErrors(['workout_line_id']);
});

test('user cannot update another users set', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherWorkout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $otherWorkoutLine = WorkoutLine::factory()->create(['workout_id' => $otherWorkout->id]);
    $otherSet = Set::factory()->create(['workout_line_id' => $otherWorkoutLine->id]);

    Sanctum::actingAs($user);

    patchJson(route('api.v1.sets.update', $otherSet), ['weight' => 100])
        ->assertForbidden();
});

test('user cannot delete another users set', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherWorkout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $otherWorkoutLine = WorkoutLine::factory()->create(['workout_id' => $otherWorkout->id]);
    $otherSet = Set::factory()->create(['workout_line_id' => $otherWorkoutLine->id]);

    Sanctum::actingAs($user);

    deleteJson(route('api.v1.sets.destroy', $otherSet))->assertForbidden();
});

// Validation Tests

test('store validates numeric constraints', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    Sanctum::actingAs($user);

    postJson(route('api.v1.sets.store'), [
        'workout_line_id' => $workoutLine->id,
        'weight' => 'not-a-number',
        'reps' => 'not-an-integer',
        'duration_seconds' => -5,
        'distance_km' => -1,
    ])->assertInvalid(['weight', 'reps', 'duration_seconds', 'distance_km']);
});

test('update validates numeric constraints', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    $set = Set::factory()->create(['workout_line_id' => $workoutLine->id]);

    Sanctum::actingAs($user);

    patchJson(route('api.v1.sets.update', $set), [
        'weight' => -10,
        'reps' => 1.5, // should be integer
    ])->assertInvalid(['weight', 'reps']);
});
