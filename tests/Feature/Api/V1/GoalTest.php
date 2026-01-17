<?php

namespace Tests\Feature\Api\V1;

use App\Models\Exercise;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoalTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_goals_with_includes(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create(['name' => 'Bench Press']);

        Goal::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'title' => 'Increase Bench Press',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/goals?include=exercise');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'title',
                        'exercise' => ['id', 'name'],
                    ],
                ],
            ])
            ->assertJsonFragment(['name' => 'Bench Press'])
            ->assertJsonFragment(['title' => 'Increase Bench Press']);
    }

    public function test_user_cannot_see_others_goals(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Goal::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/goals');

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_goals_api_is_protected(): void
    {
        $response = $this->getJson('/api/v1/goals');
        $response->assertUnauthorized();
    }

    public function test_user_can_create_goal(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/goals', [
            'title' => 'New Goal',
            'type' => 'weight',
            'target_value' => 100,
            'start_value' => 50,
            'exercise_id' => $exercise->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'New Goal')
            ->assertJsonPath('data.id', fn ($id) => is_int($id));

        $this->assertDatabaseHas('goals', [
            'user_id' => $user->id,
            'title' => 'New Goal',
        ]);
    }

    public function test_user_can_view_own_goal(): void
    {
        $user = User::factory()->create();
        $goal = Goal::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/goals/{$goal->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $goal->id);
    }

    public function test_user_can_update_own_goal(): void
    {
        $user = User::factory()->create();
        $goal = Goal::factory()->create(['user_id' => $user->id, 'title' => 'Old Title']);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/goals/{$goal->id}", [
            'title' => 'Updated Title',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.title', 'Updated Title');

        $this->assertDatabaseHas('goals', [
            'id' => $goal->id,
            'title' => 'Updated Title',
        ]);
    }

    public function test_user_cannot_update_others_goal(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $goal = Goal::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/goals/{$goal->id}", [
            'title' => 'Updated Title',
        ]);

        $response->assertForbidden();
    }

    public function test_user_can_delete_own_goal(): void
    {
        $user = User::factory()->create();
        $goal = Goal::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/goals/{$goal->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('goals', ['id' => $goal->id]);
    }

    public function test_user_cannot_delete_others_goal(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $goal = Goal::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/goals/{$goal->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('goals', ['id' => $goal->id]);
    }
}
