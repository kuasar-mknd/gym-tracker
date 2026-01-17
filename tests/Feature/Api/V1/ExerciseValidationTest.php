<?php

namespace Tests\Feature\Api\V1;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExerciseValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cannot_create_duplicate_exercise_name(): void
    {
        $user = User::factory()->create();
        Exercise::factory()->create(['name' => 'Existing Exercise', 'user_id' => null]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/exercises', [
            'name' => 'Existing Exercise',
            'type' => 'strength',
            'category' => 'Test',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_cannot_update_to_duplicate_exercise_name(): void
    {
        $user = User::factory()->create();
        Exercise::factory()->create(['name' => 'Other Exercise', 'user_id' => null]);
        $exercise = Exercise::factory()->create(['name' => 'My Exercise', 'user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/exercises/{$exercise->id}", [
            'name' => 'Other Exercise',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }
}
