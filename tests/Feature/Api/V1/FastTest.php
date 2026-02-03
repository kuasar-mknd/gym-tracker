<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\Fast;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FastTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_fasts(): void
    {
        $user = User::factory()->create();
        Fast::factory()->count(3)->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/fasts');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'user_id',
                        'start_time',
                        'end_time',
                        'target_duration_minutes',
                        'type',
                        'status',
                    ],
                ],
                'meta',
            ])
            ->assertJsonCount(3, 'data');
    }

    public function test_can_create_fast(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/fasts', [
            'start_time' => now()->toIso8601String(),
            'target_duration_minutes' => 16 * 60,
            'type' => 'intermittent',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('fasts', [
            'user_id' => $user->id,
            'type' => 'intermittent',
            'status' => 'active',
        ]);
    }

    public function test_cannot_create_fast_if_one_active(): void
    {
        $user = User::factory()->create();
        Fast::factory()->create(['user_id' => $user->id, 'status' => 'active']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/fasts', [
            'start_time' => now()->toIso8601String(),
            'target_duration_minutes' => 16 * 60,
            'type' => 'intermittent',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('base');
    }

    public function test_can_show_fast(): void
    {
        $user = User::factory()->create();
        $fast = Fast::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/fasts/{$fast->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $fast->id);
    }

    public function test_cannot_show_other_users_fast(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $fast = Fast::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/fasts/{$fast->id}");

        $response->assertForbidden();
    }

    public function test_can_update_fast(): void
    {
        $user = User::factory()->create();
        $fast = Fast::factory()->create(['user_id' => $user->id, 'status' => 'active']);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/fasts/{$fast->id}", [
            'status' => 'completed',
            'end_time' => now()->toIso8601String(),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertDatabaseHas('fasts', [
            'id' => $fast->id,
            'status' => 'completed',
        ]);
    }

    public function test_cannot_update_other_users_fast(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $fast = Fast::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/fasts/{$fast->id}", [
            'status' => 'completed',
        ]);

        $response->assertForbidden();
    }

    public function test_can_delete_fast(): void
    {
        $user = User::factory()->create();
        $fast = Fast::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/fasts/{$fast->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('fasts', ['id' => $fast->id]);
    }

    public function test_cannot_delete_other_users_fast(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $fast = Fast::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/fasts/{$fast->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('fasts', ['id' => $fast->id]);
    }
}
