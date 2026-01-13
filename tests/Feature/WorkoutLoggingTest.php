<?php

namespace Tests\Feature;

use Tests\TestCase;

class WorkoutLoggingTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    public function test_user_can_create_workout(): void
    {
        $user = \App\Models\User::factory()->create();

        $response = $this->actingAs($user)->post(route('workouts.store'));

        $response->assertRedirect();
        $this->assertDatabaseHas('workouts', [
            'user_id' => $user->id,
        ]);
    }

    public function test_user_can_add_exercise_to_workout(): void
    {
        $user = \App\Models\User::factory()->create();
        $workout = \App\Models\Workout::factory()->create(['user_id' => $user->id]);
        $exercise = \App\Models\Exercise::factory()->create();

        $response = $this->actingAs($user)->post(route('workout-lines.store', $workout), [
            'exercise_id' => $exercise->id,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('workout_lines', [
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);
    }

    public function test_user_can_add_set_to_workout_line(): void
    {
        $user = \App\Models\User::factory()->create();
        $workout = \App\Models\Workout::factory()->create(['user_id' => $user->id]);
        $exercise = \App\Models\Exercise::factory()->create();
        $line = \App\Models\WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);

        $response = $this->actingAs($user)->post(route('sets.store', $line), [
            'weight' => 50,
            'reps' => 10,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('sets', [
            'workout_line_id' => $line->id,
            'weight' => 50,
            'reps' => 10,
        ]);
    }
}
