<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    actingAs($this->user, 'sanctum');
});

test('it can create a workout line', function (): void {
    $workout = Workout::factory()->create(['user_id' => $this->user->id]);
    $exercise = Exercise::factory()->create();

    $response = postJson('/api/v1/workout-lines', [
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

    $this->assertDatabaseHas('workout_lines', [
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
        'order' => 1,
        'notes' => 'Test notes',
    ]);
});

test('it auto-assigns order when creating a workout line if not provided', function (): void {
    $workout = Workout::factory()->create(['user_id' => $this->user->id]);
    $exercise = Exercise::factory()->create();

    // Create a first line with order 5
    WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
        'order' => 5,
    ]);

    $response = postJson('/api/v1/workout-lines', [
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
    $response = postJson('/api/v1/workout-lines', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['workout_id', 'exercise_id']);
});

test('it cannot create a workout line for another user\'s workout', function (): void {
    $otherUser = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $exercise = Exercise::factory()->create();

    $response = postJson('/api/v1/workout-lines', [
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
        'order' => 1,
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['workout_id']);
});

test('it can delete a workout line', function (): void {
    $workout = Workout::factory()->create(['user_id' => $this->user->id]);
    $exercise = Exercise::factory()->create();
    $workoutLine = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    $response = deleteJson("/api/v1/workout-lines/{$workoutLine->id}");

    $response->assertNoContent();

    $this->assertDatabaseMissing('workout_lines', [
        'id' => $workoutLine->id,
    ]);
});

test('it cannot delete another user\'s workout line', function (): void {
    $otherUser = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $exercise = Exercise::factory()->create();
    $workoutLine = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    $response = deleteJson("/api/v1/workout-lines/{$workoutLine->id}");

    $response->assertNotFound();

    $this->assertDatabaseHas('workout_lines', [
        'id' => $workoutLine->id,
    ]);
});
