<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Models\Exercise;
use Laravel\Sanctum\Sanctum;
use function Pest\Laravel\postJson;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('SetStoreRequest can handle zero weight or reps without being always false', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $exercise = Exercise::factory()->create();
    $workoutLine = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id
    ]);

    Sanctum::actingAs($user);

    $response = postJson(route('api.v1.sets.store'), [
        'workout_line_id' => $workoutLine->id,
        'weight' => 0,
        'reps' => 0,
    ]);

    $response->assertCreated();
});
