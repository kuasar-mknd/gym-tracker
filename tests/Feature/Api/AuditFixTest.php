<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditFixTest extends TestCase
{
    use RefreshDatabase;

    public function test_set_store_request_can_handle_zero_weight_or_reps_without_being_always_false(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id]);
        $exercise = Exercise::factory()->create();
        $workoutLine = WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.sets.store'), [
            'workout_line_id' => $workoutLine->id,
            'weight' => 0,
            'reps' => 0,
        ]);

        $response->assertCreated();
    }

    public function test_set_policy_handles_missing_relationships(): void
    {
        $user = User::factory()->create();
        $set = new Set();
        // Relationship workoutLine is not set

        Sanctum::actingAs($user);

        // This would trigger policy check if we tried to update it via API
        // But here we can check the policy directly
        $policy = new \App\Policies\SetPolicy();

        $this->assertFalse($policy->view($user, $set));
    }
}
