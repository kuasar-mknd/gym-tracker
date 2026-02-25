<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\postJson;

test('cannot create workout line with another users private exercise', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    // Create a private exercise for User B
    $privateExercise = Exercise::factory()->create([
        'user_id' => $userB->id,
        'name' => 'User B Private Exercise',
    ]);

    // Create a workout for User A
    $workout = Workout::factory()->create(['user_id' => $userA->id]);

    Sanctum::actingAs($userA);

    // Attempt to add User B's private exercise to User A's workout
    postJson(route('api.v1.workout-lines.store'), [
        'workout_id' => $workout->id,
        'exercise_id' => $privateExercise->id,
        'notes' => 'Stealing exercises',
    ])
        ->assertUnprocessable() // Expect 422
        ->assertJsonValidationErrors(['exercise_id']);
});
