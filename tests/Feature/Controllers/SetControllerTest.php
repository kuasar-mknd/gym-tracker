<?php

declare(strict_types=1);

use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

beforeEach(function (): void {
    // StatsService is final, so we cannot mock it directly with Mockery.
    // Instead we can rely on testing DB transactions since it only touches the DB/cache.
});

// Happy Path Tests

test('user can store a set', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    $data = [
        'weight' => 100,
        'reps' => 10,
        'is_warmup' => false,
        'is_completed' => true,
    ];

    actingAs($user)
        ->from('/workout') // Set the referer to test back() redirect
        ->post(route('sets.store', $workoutLine), $data)
        ->assertRedirect('/workout');

    assertDatabaseHas('sets', [
        'workout_line_id' => $workoutLine->id,
        'weight' => 100,
        'reps' => 10,
    ]);
});

test('user can update a set', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    $set = Set::factory()->create(['workout_line_id' => $workoutLine->id, 'weight' => 50, 'reps' => 5]);

    $data = [
        'weight' => 60,
        'reps' => 8,
    ];

    actingAs($user)
        ->from('/workout')
        ->patch(route('sets.update', $set), $data)
        ->assertRedirect('/workout');

    assertDatabaseHas('sets', [
        'id' => $set->id,
        'weight' => 60,
        'reps' => 8,
    ]);
});

test('user can delete a set', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    $set = Set::factory()->create(['workout_line_id' => $workoutLine->id]);

    actingAs($user)
        ->from('/workout')
        ->delete(route('sets.destroy', $set))
        ->assertRedirect('/workout');

    assertDatabaseMissing('sets', [
        'id' => $set->id,
    ]);
});

// Authorization Tests

test('user cannot store set in another users workout line', function (): void {
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

    $data = [
        'weight' => 100,
        'reps' => 10,
    ];

    actingAs($user)
        ->patch(route('sets.update', $otherSet), $data)
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

test('store requires weight and reps to be numeric and integer respectively', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    $data = [
        'weight' => 'invalid',
        'reps' => 'invalid',
    ];

    actingAs($user)
        ->post(route('sets.store', $workoutLine), $data)
        ->assertSessionHasErrors(['weight', 'reps']);
});

test('update requires weight and reps to be numeric and integer respectively', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    $set = Set::factory()->create(['workout_line_id' => $workoutLine->id]);

    $data = [
        'weight' => 'invalid',
        'reps' => 'invalid',
    ];

    actingAs($user)
        ->patch(route('sets.update', $set), $data)
        ->assertSessionHasErrors(['weight', 'reps']);
});
