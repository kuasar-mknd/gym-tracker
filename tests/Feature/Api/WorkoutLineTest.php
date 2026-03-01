<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

test('index returns lines for user', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    $otherUser = User::factory()->create();
    $otherWorkout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $otherLine = WorkoutLine::factory()->create(['workout_id' => $otherWorkout->id]);

    Sanctum::actingAs($user);

    getJson(route('api.v1.workout-lines.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonFragment(['id' => $line->id])
        ->assertJsonMissing(['id' => $otherLine->id]);
});

test('store creates line', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $exercise = Exercise::factory()->create();

    Sanctum::actingAs($user);

    postJson(route('api.v1.workout-lines.store'), [
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
        'notes' => 'Test note',
    ])
        ->assertCreated()
        ->assertJsonFragment(['notes' => 'Test note']);

    assertDatabaseHas('workout_lines', [
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
        'notes' => 'Test note',
    ]);
});

test('store forbids other user workout', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $exercise = Exercise::factory()->create();

    Sanctum::actingAs($user);

    postJson(route('api.v1.workout-lines.store'), [
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['workout_id']);
});

test('update updates line', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    Sanctum::actingAs($user);

    putJson(route('api.v1.workout-lines.update', $line), [
        'notes' => 'Updated note',
    ])
        ->assertOk()
        ->assertJsonFragment(['notes' => 'Updated note']);

    assertDatabaseHas('workout_lines', [
        'id' => $line->id,
        'notes' => 'Updated note',
    ]);
});

test('destroy deletes line', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    Sanctum::actingAs($user);

    deleteJson(route('api.v1.workout-lines.destroy', $line))
        ->assertNoContent();

    assertDatabaseMissing('workout_lines', ['id' => $line->id]);
});
