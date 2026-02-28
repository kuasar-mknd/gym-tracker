<?php

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Laravel\Sanctum\Sanctum;

it('can create a set via API with minimal data', function (): void {
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
    $this->assertDatabaseHas('sets', [
        'workout_line_id' => $workoutLine->id,
        'weight' => 50,
        'reps' => 10,
    ]);
});
