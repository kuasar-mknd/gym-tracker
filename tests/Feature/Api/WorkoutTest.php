<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_workouts_api_is_protected(): void
    {
        $response = $this->getJson('/api/workouts');
        $response->assertUnauthorized();
    }

    public function test_user_can_list_workouts(): void
    {
        $user = User::factory()->create();
        Workout::factory()->count(3)->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->getJson('/api/workouts');

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_user_can_create_workout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/workouts', [
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

        $response = $this->actingAs($user)->getJson("/api/workouts/{$workout->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $workout->id);
    }

    public function test_user_cannot_view_others_workout(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($user)->getJson("/api/workouts/{$workout->id}");

        $response->assertForbidden();
    }

    public function test_user_can_update_own_workout(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->putJson("/api/workouts/{$workout->id}", [
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

        $response = $this->actingAs($user)->putJson("/api/workouts/{$workout->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertForbidden();
    }

    public function test_user_can_delete_own_workout(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->deleteJson("/api/workouts/{$workout->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('workouts', ['id' => $workout->id]);
    }

    public function test_user_cannot_delete_others_workout(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($user)->deleteJson("/api/workouts/{$workout->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('workouts', ['id' => $workout->id]);
    }
}
