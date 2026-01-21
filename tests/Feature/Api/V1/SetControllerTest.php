<?php

use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

// Happy Path Tests

test('user can list their sets', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    $set = Set::factory()->create(['workout_line_id' => $line->id]);

    // Create another user's set to ensure isolation
    $otherUser = User::factory()->create();
    $otherWorkout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $otherLine = WorkoutLine::factory()->create(['workout_id' => $otherWorkout->id]);
    Set::factory()->create(['workout_line_id' => $otherLine->id]);

    actingAs($user, 'sanctum')
        ->getJson(route('api.v1.sets.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $set->id);
});

test('user can create a set in their workout line', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    $data = [
        'workout_line_id' => $line->id,
        'weight' => 100.5,
        'reps' => 10,
        'is_warmup' => false,
        'is_completed' => true,
    ];

    actingAs($user, 'sanctum')
        ->postJson(route('api.v1.sets.store'), $data)
        ->assertCreated()
        ->assertJsonPath('data.weight', 100.5)
        ->assertJsonPath('data.reps', 10);

    assertDatabaseHas('sets', [
        'workout_line_id' => $line->id,
        'weight' => 100.5,
        'reps' => 10,
    ]);
});

test('user can show a specific set', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    $set = Set::factory()->create(['workout_line_id' => $line->id]);

    actingAs($user, 'sanctum')
        ->getJson(route('api.v1.sets.show', $set))
        ->assertOk()
        ->assertJsonPath('data.id', $set->id);
});

test('user can update their set', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    $set = Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 50]);

    actingAs($user, 'sanctum')
        ->patchJson(route('api.v1.sets.update', $set), [
            'weight' => 60,
            'reps' => 8,
        ])
        ->assertOk()
        ->assertJsonPath('data.weight', 60)
        ->assertJsonPath('data.reps', 8);

    assertDatabaseHas('sets', [
        'id' => $set->id,
        'weight' => 60,
        'reps' => 8,
    ]);
});

test('user can delete their set', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    $set = Set::factory()->create(['workout_line_id' => $line->id]);

    actingAs($user, 'sanctum')
        ->deleteJson(route('api.v1.sets.destroy', $set))
        ->assertNoContent();

    assertDatabaseMissing('sets', ['id' => $set->id]);
});

// Authorization Tests

test('user cannot create set in another users workout line', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherWorkout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $otherLine = WorkoutLine::factory()->create(['workout_id' => $otherWorkout->id]);

    $data = [
        'workout_line_id' => $otherLine->id,
        'weight' => 100,
        'reps' => 10,
    ];

    actingAs($user, 'sanctum')
        ->postJson(route('api.v1.sets.store'), $data)
        ->assertForbidden();
});

test('user cannot show another users set', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherWorkout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $otherLine = WorkoutLine::factory()->create(['workout_id' => $otherWorkout->id]);
    $otherSet = Set::factory()->create(['workout_line_id' => $otherLine->id]);

    actingAs($user, 'sanctum')
        ->getJson(route('api.v1.sets.show', $otherSet))
        ->assertForbidden();
});

test('user cannot update another users set', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherWorkout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $otherLine = WorkoutLine::factory()->create(['workout_id' => $otherWorkout->id]);
    $otherSet = Set::factory()->create(['workout_line_id' => $otherLine->id]);

    actingAs($user, 'sanctum')
        ->patchJson(route('api.v1.sets.update', $otherSet), ['weight' => 100])
        ->assertForbidden();
});

test('user cannot delete another users set', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherWorkout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $otherLine = WorkoutLine::factory()->create(['workout_id' => $otherWorkout->id]);
    $otherSet = Set::factory()->create(['workout_line_id' => $otherLine->id]);

    actingAs($user, 'sanctum')
        ->deleteJson(route('api.v1.sets.destroy', $otherSet))
        ->assertForbidden();
});

// Validation Tests

test('store requires workout_line_id', function (): void {
    $user = User::factory()->create();

    actingAs($user, 'sanctum')
        ->postJson(route('api.v1.sets.store'), [
            'weight' => 100,
            'reps' => 10,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('workout_line_id');
});

test('store requires valid workout_line_id', function (): void {
    $user = User::factory()->create();

    actingAs($user, 'sanctum')
        ->postJson(route('api.v1.sets.store'), [
            'workout_line_id' => 99999,
            'weight' => 100,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('workout_line_id');
});

test('store validates numeric constraints', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    actingAs($user, 'sanctum')
        ->postJson(route('api.v1.sets.store'), [
            'workout_line_id' => $line->id,
            'weight' => 'not-a-number',
            'reps' => 'not-an-integer',
            'duration_seconds' => -5,
            'distance_km' => -1,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['weight', 'reps', 'duration_seconds', 'distance_km']);
});

test('store validates boolean constraints', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    actingAs($user, 'sanctum')
        ->postJson(route('api.v1.sets.store'), [
            'workout_line_id' => $line->id,
            'is_warmup' => 'not-boolean',
            'is_completed' => 'not-boolean',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['is_warmup', 'is_completed']);
});

test('update validates numeric constraints', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    $set = Set::factory()->create(['workout_line_id' => $line->id]);

    actingAs($user, 'sanctum')
        ->patchJson(route('api.v1.sets.update', $set), [
            'weight' => -10,
            'reps' => 1.5, // should be integer
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['weight', 'reps']);
});
