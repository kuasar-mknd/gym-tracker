<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExerciseTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_exercises(): void
    {
        $user = User::factory()->create();
        // Create 3 system exercises and 2 user exercises
        Exercise::factory()->count(3)->create(['user_id' => null]);
        Exercise::factory()->count(2)->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/exercises');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'type',
                        'category',
                    ],
                ],
                'meta', // Pagination
            ])
            ->assertJsonCount(5, 'data');
    }

    public function test_can_filter_exercises(): void
    {
        $user = User::factory()->create();
        Exercise::factory()->create(['name' => 'Bench Press', 'type' => 'strength', 'user_id' => null]);
        Exercise::factory()->create(['name' => 'Running', 'type' => 'cardio', 'user_id' => null]);

        // Filter by type
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/exercises?filter[type]=strength');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['name' => 'Bench Press']);

        // Filter by name
        $response2 = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/exercises?filter[name]=Run');

        $response2->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['name' => 'Running']);
    }

    public function test_exercises_api_is_protected(): void
    {
        $response = $this->getJson('/api/v1/exercises');
        $response->assertUnauthorized();

        $response = $this->postJson('/api/v1/exercises', ['name' => 'Test']);
        $response->assertUnauthorized();
    }

    public function test_can_create_exercise(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/exercises', [
            'name' => 'New Exercise',
            'type' => 'strength',
            'category' => 'Test',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'New Exercise');

        $this->assertDatabaseHas('exercises', [
            'name' => 'New Exercise',
            'user_id' => $user->id,
        ]);
    }

    public function test_can_update_own_exercise(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/exercises/{$exercise->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Name');

        $this->assertDatabaseHas('exercises', [
            'id' => $exercise->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_cannot_update_system_exercise(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create(['user_id' => null]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/exercises/{$exercise->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertForbidden();
    }

    public function test_can_delete_own_exercise(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/exercises/{$exercise->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('exercises', ['id' => $exercise->id]);
    }

    public function test_cannot_delete_system_exercise(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create(['user_id' => null]);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/exercises/{$exercise->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('exercises', ['id' => $exercise->id]);
    }
}
