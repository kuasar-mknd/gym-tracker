<?php

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

test('index returns user workout lines', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    // Other user's data
    $otherWorkout = Workout::factory()->create(['user_id' => $otherUser->id]);
    WorkoutLine::factory()->create(['workout_id' => $otherWorkout->id]);

    actingAs($user)
        ->getJson(route('api.v1.workout-lines.index', ['filter[workout_id]' => $workout->id]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonFragment(['id' => $line->id]);
});

test('store creates workout line', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $exercise = Exercise::factory()->create();

    $data = [
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
        'notes' => 'Test Note',
    ];

    actingAs($user)
        ->postJson(route('api.v1.workout-lines.store'), $data)
        ->assertCreated()
        ->assertJsonFragment(['notes' => 'Test Note'])
        ->assertJsonFragment(['order' => 0]);

    expect($workout->workoutLines()->count())->toBe(1);
});

test('store validates workout ownership', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $exercise = Exercise::factory()->create();

    actingAs($user)
        ->postJson(route('api.v1.workout-lines.store'), [
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ])
        ->assertForbidden();
});

test('show returns workout line', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    actingAs($user)
        ->getJson(route('api.v1.workout-lines.show', $line))
        ->assertOk()
        ->assertJsonFragment(['id' => $line->id]);
});

test('update updates workout line', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'notes' => 'Old Note']);

    actingAs($user)
        ->putJson(route('api.v1.workout-lines.update', $line), [
            'notes' => 'New Note',
        ])
        ->assertOk()
        ->assertJsonFragment(['notes' => 'New Note']);
});

test('destroy deletes workout line', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    actingAs($user)
        ->deleteJson(route('api.v1.workout-lines.destroy', $line))
        ->assertNoContent();

    expect(WorkoutLine::find($line->id))->toBeNull();
});
