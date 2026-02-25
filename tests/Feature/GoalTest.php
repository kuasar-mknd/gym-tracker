<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoalTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_a_goal(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create();

        $response = $this->actingAs($user)->post(route('goals.store'), [
            'title' => 'Test Goal',
            'type' => 'weight',
            'target_value' => 100,
            'exercise_id' => $exercise->id,
            'start_value' => 50,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('goals', [
            'user_id' => $user->id,
            'title' => 'Test Goal',
            'target_value' => 100,
        ]);
    }

    public function test_goal_progress_updates_on_workout_set_save(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create();

        $goal = $user->goals()->create([
            'title' => 'Bench 100kg',
            'type' => 'weight',
            'target_value' => 100,
            'exercise_id' => $exercise->id,
            'start_value' => 50,
        ]);

        $workout = $user->workouts()->create(['started_at' => now()]);
        $line = $workout->workoutLines()->create(['exercise_id' => $exercise->id]);

        // This should trigger the saved event and GoalService
        $line->sets()->create([
            'weight' => 80,
            'reps' => 5,
        ]);

        $this->assertEquals(80, $goal->fresh()->current_value);
    }

    public function test_goal_marks_as_completed_when_target_reached(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create();

        $goal = $user->goals()->create([
            'title' => 'Bench 100kg',
            'type' => 'weight',
            'target_value' => 100,
            'exercise_id' => $exercise->id,
            'start_value' => 50,
        ]);

        $workout = $user->workouts()->create(['started_at' => now()]);
        $line = $workout->workoutLines()->create(['exercise_id' => $exercise->id]);

        $line->sets()->create([
            'weight' => 100,
            'reps' => 1,
        ]);

        $this->assertNotNull($goal->fresh()->completed_at);
        $this->assertEquals(100, $goal->fresh()->progress);
    }

    public function test_measurement_goal_updates_on_measurement_save(): void
    {
        $user = User::factory()->create();

        $goal = $user->goals()->create([
            'title' => 'Weight Loss',
            'type' => 'measurement',
            'measurement_type' => 'weight',
            'target_value' => 80,
            'start_value' => 90,
        ]);

        $user->bodyMeasurements()->create([
            'weight' => 85,
            'measured_at' => now(),
        ]);

        $this->assertEquals(85, $goal->fresh()->current_value);
        $this->assertEquals(50, $goal->fresh()->progress); // (90-85)/(90-80) = 5/10 = 50%
    }
}
