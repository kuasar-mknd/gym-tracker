<?php

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

test('cannot link workout line to another user exercise', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $otherExercise = Exercise::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->postJson(route('api.v1.workout-lines.store'), [
            'workout_id' => $workout->id,
            'exercise_id' => $otherExercise->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['exercise_id']);
});

test('cannot link workout line to another user workout', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherWorkout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $exercise = Exercise::factory()->create();

    actingAs($user)
        ->postJson(route('api.v1.workout-lines.store'), [
            'workout_id' => $otherWorkout->id,
            'exercise_id' => $exercise->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['workout_id']);
});

test('cannot create set for another user workout line', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherWorkout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $otherLine = WorkoutLine::factory()->create(['workout_id' => $otherWorkout->id]);

    actingAs($user)
        ->postJson(route('api.v1.sets.store'), [
            'workout_line_id' => $otherLine->id,
            'reps' => 10,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['workout_line_id']);
});

test('cannot update workout line with another user exercise', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    $otherExercise = Exercise::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->putJson(route('api.v1.workout-lines.update', $line), [
            'exercise_id' => $otherExercise->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['exercise_id']);
});
