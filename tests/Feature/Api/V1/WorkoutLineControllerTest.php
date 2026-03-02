<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    actingAs($this->user, 'sanctum');
});

test('it can list workout lines for a workout', function (): void {
    $workout = Workout::factory()->create(['user_id' => $this->user->id]);
    $exercise = Exercise::factory()->create();

    WorkoutLine::factory(3)->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    $response = getJson("/api/v1/workout-lines?filter[workout_id]={$workout->id}");

    $response->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'order',
                    'notes',
                ],
            ],
            'meta',
            'links',
        ]);
});

test('it cannot list workout lines for another user\'s workout', function (): void {
    $otherUser = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $exercise = Exercise::factory()->create();

    WorkoutLine::factory(3)->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    $response = getJson("/api/v1/workout-lines?filter[workout_id]={$workout->id}");

    // The index method filters by user_id in the relationship query,
    // so it should return an empty collection instead of 403.
    $response->assertOk()
        ->assertJsonCount(0, 'data');
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

test('it can show a workout line', function (): void {
    $workout = Workout::factory()->create(['user_id' => $this->user->id]);
    $exercise = Exercise::factory()->create();
    $workoutLine = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    $response = getJson("/api/v1/workout-lines/{$workoutLine->id}");

    $response->assertOk()
        ->assertJsonFragment([
            'id' => $workoutLine->id,
        ]);
});

test('it cannot show another user\'s workout line', function (): void {
    $otherUser = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $exercise = Exercise::factory()->create();
    $workoutLine = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    $response = getJson("/api/v1/workout-lines/{$workoutLine->id}");

    $response->assertForbidden();
});

test('it can update a workout line', function (): void {
    $workout = Workout::factory()->create(['user_id' => $this->user->id]);
    $exercise = Exercise::factory()->create();
    $workoutLine = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
        'order' => 1,
        'notes' => 'Old notes',
    ]);

    $newExercise = Exercise::factory()->create();

    $response = putJson("/api/v1/workout-lines/{$workoutLine->id}", [
        'exercise_id' => $newExercise->id,
        'order' => 2,
        'notes' => 'New notes',
    ]);

    $response->assertOk()
        ->assertJsonFragment([
            'id' => $workoutLine->id,
            'order' => 2,
            'notes' => 'New notes',
        ]);

    $this->assertDatabaseHas('workout_lines', [
        'id' => $workoutLine->id,
        'exercise_id' => $newExercise->id,
        'order' => 2,
        'notes' => 'New notes',
    ]);
});

test('it cannot update a workout line with invalid data', function (): void {
    $workout = Workout::factory()->create(['user_id' => $this->user->id]);
    $exercise = Exercise::factory()->create();
    $workoutLine = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    $response = putJson("/api/v1/workout-lines/{$workoutLine->id}", [
        'exercise_id' => 999999, // Invalid ID
        'order' => 'not-an-integer',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['exercise_id', 'order']);
});

test('it cannot update another user\'s workout line', function (): void {
    $otherUser = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $exercise = Exercise::factory()->create();
    $workoutLine = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    $response = putJson("/api/v1/workout-lines/{$workoutLine->id}", [
        'notes' => 'Hacked notes',
    ]);

    $response->assertForbidden();
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

    $response->assertForbidden();

    $this->assertDatabaseHas('workout_lines', [
        'id' => $workoutLine->id,
    ]);
});
