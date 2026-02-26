<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SetValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_validates_maximum_values_for_set_fields(): void
    {
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
            'weight' => 2000,
            'reps' => 2000,
            'distance_km' => 2000,
            'duration_seconds' => 100000,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['weight', 'reps', 'distance_km', 'duration_seconds']);
    }

    public function test_it_allows_valid_set_values(): void
    {
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
            'weight' => 500,
            'reps' => 50,
            'distance_km' => 10,
            'duration_seconds' => 3600,
        ]);

        $response->assertStatus(201);
    }
}
