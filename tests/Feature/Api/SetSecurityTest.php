<?php

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('returns 422 when creating a set for another user workout line via API', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $exercise = Exercise::factory()->create(['user_id' => $otherUser->id]);
    $workout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $workoutLine = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    Sanctum::actingAs($user);

    $response = $this->postJson(route('api.v1.sets.store'), [
        'workout_line_id' => $workoutLine->id,
        'weight' => 50,
        'reps' => 10,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['workout_line_id']);
});

it('can create a set for own workout line', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $exercise = Exercise::factory()->create(['user_id' => $user->id]);
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $workoutLine = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    $response = $this->postJson(route('api.v1.sets.store'), [
        'workout_line_id' => $workoutLine->id,
        'weight' => 50,
        'reps' => 10,
    ]);

    $response->assertStatus(201);
});
