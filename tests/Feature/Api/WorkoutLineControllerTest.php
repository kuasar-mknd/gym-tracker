<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\postJson;

test('it can create a workout line', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $exercise = Exercise::factory()->create();

    actingAs($user, 'sanctum');

    $response = postJson(route('api.v1.workout-lines.store'), [
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
        'order' => 1,
        'notes' => 'Test notes',
    ]);

    $response->assertCreated()
        ->assertJsonFragment([
            'id' => $response->json('data.id'),
            'order' => 1,
            'notes' => 'Test notes',
        ]);

    assertDatabaseHas('workout_lines', [
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
        'order' => 1,
        'notes' => 'Test notes',
    ]);
});

test('it gives the first line of a workout the order 0 when none is provided', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $exercise = Exercise::factory()->create();

    actingAs($user, 'sanctum');

    postJson(route('api.v1.workout-lines.store'), [
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
        'notes' => 'Test Note',
    ])
        ->assertCreated()
        ->assertJsonFragment(['notes' => 'Test Note'])
        ->assertJsonFragment(['order' => 0]);

    expect($workout->workoutLines()->count())->toBe(1);
});

test('it auto-assigns order when creating a workout line if not provided', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $exercise = Exercise::factory()->create();

    // Create a first line with order 5
    WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
        'order' => 5,
    ]);

    actingAs($user, 'sanctum');

    $response = postJson(route('api.v1.workout-lines.store'), [
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
        'notes' => 'Test notes',
    ]);

    $response->assertCreated()
        ->assertJsonFragment([
            'id' => $response->json('data.id'),
            'order' => 6, // Should be max + 1
            'notes' => 'Test notes',
        ]);
});

test('it cannot create a workout line with invalid data', function (): void {
    actingAs(User::factory()->create(), 'sanctum');

    postJson(route('api.v1.workout-lines.store'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['workout_id', 'exercise_id']);
});

test('it cannot create a workout line for another user\'s workout', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $exercise = Exercise::factory()->create();

    actingAs($user, 'sanctum');

    postJson(route('api.v1.workout-lines.store'), [
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
        'order' => 1,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['workout_id']);
});

test('it can delete a workout line', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $exercise = Exercise::factory()->create();
    $workoutLine = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    actingAs($user, 'sanctum');

    deleteJson(route('api.v1.workout-lines.destroy', $workoutLine))
        ->assertNoContent();

    assertDatabaseMissing('workout_lines', ['id' => $workoutLine->id]);
});

test('it cannot delete another user\'s workout line', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $exercise = Exercise::factory()->create();
    $workoutLine = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    actingAs($user, 'sanctum');

    deleteJson(route('api.v1.workout-lines.destroy', $workoutLine))
        ->assertNotFound();

    assertDatabaseHas('workout_lines', ['id' => $workoutLine->id]);
});
