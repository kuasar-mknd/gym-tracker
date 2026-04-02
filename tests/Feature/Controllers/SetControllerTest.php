<?php

declare(strict_types=1);

use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

// Happy Path Tests

test('user can add a set to their workout line', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    $data = [
        'weight' => 100,
        'reps' => 10,
        'is_warmup' => false,
    ];

    actingAs($user)
        ->from(route('workouts.show', $workout))
        ->post(route('sets.store', $workoutLine), $data)
        ->assertRedirect(route('workouts.show', $workout));

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

    actingAs($user)
        ->from(route('workouts.show', $workout))
        ->patch(route('sets.update', $set), [
            'weight' => 60,
            'reps' => 8,
            'is_completed' => true,
        ])
        ->assertRedirect(route('workouts.show', $workout));

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

    actingAs($user)
        ->from(route('workouts.show', $workout))
        ->delete(route('sets.destroy', $set))
        ->assertRedirect(route('workouts.show', $workout));

    assertDatabaseMissing('sets', ['id' => $set->id]);
});

// Authorization Tests

test('user cannot add set to another users workout line', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherWorkout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $otherWorkoutLine = WorkoutLine::factory()->create(['workout_id' => $otherWorkout->id]);

    $data = [
        'weight' => 100,
        'reps' => 10,
    ];

    actingAs($user)
        ->post(route('sets.store', $otherWorkoutLine), $data)
        ->assertForbidden();
});

test('user cannot update another users set', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherWorkout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $otherWorkoutLine = WorkoutLine::factory()->create(['workout_id' => $otherWorkout->id]);
    $otherSet = Set::factory()->create(['workout_line_id' => $otherWorkoutLine->id]);

    actingAs($user)
        ->patch(route('sets.update', $otherSet), ['weight' => 100])
        ->assertForbidden();
});

test('user cannot delete another users set', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherWorkout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $otherWorkoutLine = WorkoutLine::factory()->create(['workout_id' => $otherWorkout->id]);
    $otherSet = Set::factory()->create(['workout_line_id' => $otherWorkoutLine->id]);

    actingAs($user)
        ->delete(route('sets.destroy', $otherSet))
        ->assertForbidden();
});

// Validation Tests

test('store validates numeric constraints', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    actingAs($user)
        ->post(route('sets.store', $workoutLine), [
            'weight' => 'not-a-number',
            'reps' => 'not-an-integer',
            'duration_seconds' => -5,
            'distance_km' => -1,
        ])
        ->assertInvalid(['weight', 'reps', 'duration_seconds', 'distance_km']);
});

test('update validates numeric constraints', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    $set = Set::factory()->create(['workout_line_id' => $workoutLine->id]);

    actingAs($user)
        ->patch(route('sets.update', $set), [
            'weight' => -10,
            'reps' => 1.5, // should be integer
        ])
        ->assertInvalid(['weight', 'reps']);
});
