<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

test('store adds a line to workout', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $exercise = Exercise::factory()->create();

    actingAs($user)
        ->post(route('workout-lines.store', $workout), [
            'exercise_id' => $exercise->id,
        ])
        ->assertRedirect();

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

    actingAs($user)
        ->post(route('workout-lines.store', $workout), ['exercise_id' => $exercise1->id]);

    actingAs($user)
        ->post(route('workout-lines.store', $workout), ['exercise_id' => $exercise2->id]);

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

test('store forbids adding line to another users workout', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $exercise = Exercise::factory()->create();

    actingAs($user)
        ->post(route('workout-lines.store', $workout), [
            'exercise_id' => $exercise->id,
        ])
        ->assertForbidden();

    assertDatabaseCount('workout_lines', 0);
});

test('store requires valid exercise_id', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->post(route('workout-lines.store', $workout), [
            'exercise_id' => 99999, // Non-existent
        ])
        ->assertSessionHasErrors('exercise_id');

    assertDatabaseCount('workout_lines', 0);
});

test('store forbids adding another users private exercise', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $privateExercise = Exercise::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->post(route('workout-lines.store', $workout), [
            'exercise_id' => $privateExercise->id,
        ])
        ->assertSessionHasErrors('exercise_id');

    assertDatabaseCount('workout_lines', 0);
});

test('destroy removes a workout line', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    actingAs($user)
        ->delete(route('workout-lines.destroy', $line))
        ->assertRedirect();

    assertDatabaseMissing('workout_lines', ['id' => $line->id]);
});

test('destroy forbids removing another users workout line', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    actingAs($user)
        ->delete(route('workout-lines.destroy', $line))
        ->assertForbidden();

    assertDatabaseHas('workout_lines', ['id' => $line->id]);
});

test('getRecommendedValuesAttribute returns decoded array when attribute is set and valid', function (): void {
    $line = new WorkoutLine();
    $line->setRawAttributes([
        'recommended_values' => json_encode(['weight' => 50.0, 'reps' => 10, 'distance_km' => 0.0, 'duration_seconds' => 0]),
    ]);

    $values = $line->getRecommendedValuesAttribute();

    expect($values)->toEqual(['weight' => 50.0, 'reps' => 10, 'distance_km' => 0.0, 'duration_seconds' => 0]);
});

test('getRecommendedValuesAttribute returns default values when attribute is set but invalid', function (): void {
    $line = new WorkoutLine();
    $line->setRawAttributes([
        'recommended_values' => 'invalid-json',
    ]);

    $values = $line->getRecommendedValuesAttribute();

    expect($values)->toBe(['weight' => 0.0, 'reps' => 0, 'distance_km' => 0.0, 'duration_seconds' => 0]);
});

test('getRecommendedValuesAttribute delegates to service when attribute is not set', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $exercise = Exercise::factory()->create();
    $line = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    // Create a previous workout line with some sets to calculate from
    $prevWorkout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => $workout->started_at->copy()->subDays(1),
    ]);
    $prevLine = WorkoutLine::factory()->create([
        'workout_id' => $prevWorkout->id,
        'exercise_id' => $exercise->id,
    ]);
    Set::factory()->create([
        'workout_line_id' => $prevLine->id,
        'weight' => 100.0,
        'reps' => 5,
        'distance_km' => 0.0,
        'duration_seconds' => 0,
    ]);

    $values = $line->getRecommendedValuesAttribute();

    expect($values)->toEqual(['weight' => 100.0, 'reps' => 5, 'distance_km' => 0.0, 'duration_seconds' => 0]);
});
