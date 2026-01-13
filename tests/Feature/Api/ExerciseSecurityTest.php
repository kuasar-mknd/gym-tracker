<?php

namespace Tests\Feature\Api;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExerciseSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_exercises_api_is_protected(): void
    {
        // GET /api/exercises
        $response = $this->getJson('/api/exercises');
        $response->assertUnauthorized();

        // POST /api/exercises
        $response = $this->postJson('/api/exercises', [
            'name' => 'Hacker Exercise',
            'type' => 'strength',
        ]);
        $response->assertUnauthorized();

        // PUT /api/exercises/{id}
        $exercise = Exercise::factory()->create();
        $response = $this->putJson("/api/exercises/{$exercise->id}", [
            'name' => 'Hacked Name',
        ]);
        $response->assertUnauthorized();

        // DELETE /api/exercises/{id}
        $response = $this->deleteJson("/api/exercises/{$exercise->id}");
        $response->assertUnauthorized();
    }

    public function test_authenticated_user_can_access_exercises_api(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create();

        // GET /api/exercises
        $response = $this->actingAs($user)->getJson('/api/exercises');
        $response->assertOk();

        // POST /api/exercises
        $response = $this->actingAs($user)->postJson('/api/exercises', [
            'name' => 'New Exercise',
            'type' => 'strength',
        ]);
        $response->assertCreated();

        // PUT /api/exercises/{id}
        $newExercise = Exercise::where('name', 'New Exercise')->first();
        $response = $this->actingAs($user)->putJson("/api/exercises/{$newExercise->id}", [
            'name' => 'Updated Exercise',
            'type' => 'strength',
        ]);
        $response->assertOk();

        // DELETE /api/exercises/{id}
        $response = $this->actingAs($user)->deleteJson("/api/exercises/{$newExercise->id}");
        $response->assertNoContent();
    }
}
