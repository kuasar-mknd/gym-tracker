<?php

use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Models\Exercise;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

it('allows authenticated user to add a set to their workout line', function () {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $exercise = Exercise::factory()->create();
    $workoutLine = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    actingAs($user)
        ->post(route('sets.store', $workoutLine), [
            'weight' => 50.5,
            'reps' => 10,
            'is_warmup' => true,
        ])
        ->assertRedirect();

    assertDatabaseHas('sets', [
        'workout_line_id' => $workoutLine->id,
        'weight' => 50.5,
        'reps' => 10,
        'is_warmup' => true,
    ]);
});

it('validates input when adding a set', function () {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $exercise = Exercise::factory()->create();
    $workoutLine = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    actingAs($user)
        ->post(route('sets.store', $workoutLine), [
            'weight' => 'not-a-number',
            'reps' => -5,
        ])
        ->assertSessionHasErrors(['weight', 'reps']);
});

it('prevents user from adding a set to another user\'s workout line', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $exercise = Exercise::factory()->create();
    $workoutLine = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    actingAs($user)
        ->post(route('sets.store', $workoutLine), [
            'weight' => 50,
            'reps' => 10,
        ])
        ->assertForbidden();
});

it('allows authenticated user to update their set', function () {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $exercise = Exercise::factory()->create();
    $workoutLine = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);
    $set = Set::factory()->create([
        'workout_line_id' => $workoutLine->id,
        'weight' => 50,
        'reps' => 10,
    ]);

    actingAs($user)
        ->patch(route('sets.update', $set), [
            'weight' => 60,
            'reps' => 8,
            'is_warmup' => false,
        ])
        ->assertRedirect();

    assertDatabaseHas('sets', [
        'id' => $set->id,
        'weight' => 60,
        'reps' => 8,
    ]);
});

it('validates input when updating a set', function () {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $exercise = Exercise::factory()->create();
    $workoutLine = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);
    $set = Set::factory()->create([
        'workout_line_id' => $workoutLine->id,
    ]);

    actingAs($user)
        ->patch(route('sets.update', $set), [
            'weight' => 'invalid',
            'reps' => -1,
        ])
        ->assertSessionHasErrors(['weight', 'reps']);
});

it('prevents user from updating another user\'s set', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $exercise = Exercise::factory()->create();
    $workoutLine = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);
    $set = Set::factory()->create([
        'workout_line_id' => $workoutLine->id,
    ]);

    actingAs($user)
        ->patch(route('sets.update', $set), [
            'weight' => 60,
            'reps' => 8,
        ])
        ->assertForbidden();
});

it('allows authenticated user to delete their set', function () {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $exercise = Exercise::factory()->create();
    $workoutLine = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);
    $set = Set::factory()->create([
        'workout_line_id' => $workoutLine->id,
    ]);

    actingAs($user)
        ->delete(route('sets.destroy', $set))
        ->assertRedirect();

    assertDatabaseMissing('sets', [
        'id' => $set->id,
    ]);
});

it('prevents user from deleting another user\'s set', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $exercise = Exercise::factory()->create();
    $workoutLine = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);
    $set = Set::factory()->create([
        'workout_line_id' => $workoutLine->id,
    ]);

    actingAs($user)
        ->delete(route('sets.destroy', $set))
        ->assertForbidden();
});
