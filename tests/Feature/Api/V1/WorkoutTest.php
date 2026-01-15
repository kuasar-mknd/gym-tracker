<?php

namespace Tests\Feature\Api\V1;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_workouts_with_includes(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create(['name' => 'Bench Press']);

        $workout = Workout::factory()->create(['user_id' => $user->id]);
        $line = $workout->workoutLines()->create(['exercise_id' => $exercise->id, 'order' => 1]);
        $line->sets()->create(['weight' => 100, 'reps' => 5, 'order' => 1]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/workouts?include=workoutLines.exercise,workoutLines.sets');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'lines' => [
                            '*' => [
                                'id',
                                'exercise' => ['id', 'name'],
                                'sets' => [
                                    '*' => ['id', 'weight', 'reps'],
                                ],
                            ],
                        ],
                    ],
                ],
            ])
            ->assertJsonFragment(['name' => 'Bench Press'])
            ->assertJsonFragment(['weight' => 100]);
    }

    public function test_user_cannot_see_others_workouts(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Workout::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/workouts');

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_workouts_api_is_protected(): void
    {
        $response = $this->getJson('/api/v1/workouts');
        $response->assertUnauthorized();
    }

    public function test_user_can_create_workout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/workouts', [
            'name' => 'My New Workout',
            'started_at' => now()->toIso8601String(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'My New Workout')
            ->assertJsonPath('data.id', fn ($id) => is_int($id));

        $this->assertDatabaseHas('workouts', [
            'user_id' => $user->id,
            'name' => 'My New Workout',
        ]);
    }

    public function test_user_can_view_own_workout(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/workouts/{$workout->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $workout->id);
    }

    public function test_show_endpoint_includes_relations_by_default(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create(['name' => 'Bench Press']);
        $workout = Workout::factory()->create(['user_id' => $user->id]);
        $line = $workout->workoutLines()->create(['exercise_id' => $exercise->id, 'order' => 1]);
        $line->sets()->create(['weight' => 100, 'reps' => 5, 'order' => 1]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/workouts/{$workout->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $workout->id)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'lines' => [
                        '*' => [
                            'id',
                            'exercise' => ['id', 'name'],
                            'sets' => [
                                '*' => ['id', 'weight', 'reps'],
                            ],
                        ],
                    ],
                ],
            ])
            ->assertJsonFragment(['name' => 'Bench Press']);
    }

    public function test_user_can_update_own_workout(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/workouts/{$workout->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Name');

        $this->assertDatabaseHas('workouts', [
            'id' => $workout->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_user_cannot_update_others_workout(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/workouts/{$workout->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertForbidden();
    }

    public function test_user_can_delete_own_workout(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/workouts/{$workout->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('workouts', ['id' => $workout->id]);
    }

    public function test_user_cannot_delete_others_workout(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/workouts/{$workout->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('workouts', ['id' => $workout->id]);
    }
}
