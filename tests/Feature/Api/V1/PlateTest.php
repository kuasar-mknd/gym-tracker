<?php

namespace Tests\Feature\Api\V1;

use App\Models\Plate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlateTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_plates(): void
    {
        $user = User::factory()->create();
        Plate::factory()->count(3)->create(['user_id' => $user->id]);
        Plate::factory()->count(2)->create(['user_id' => User::factory()->create()->id]); // Other user's plates

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/plates');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'weight',
                        'quantity',
                        'user_id',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'meta',
            ])
            ->assertJsonCount(3, 'data');
    }

    public function test_can_store_plate(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/plates', [
                'weight' => 20.5,
                'quantity' => 2,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.weight', 20.5)
            ->assertJsonPath('data.quantity', 2)
            ->assertJsonPath('data.user_id', $user->id);
    }

    public function test_can_show_plate(): void
    {
        $user = User::factory()->create();
        $plate = Plate::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/plates/{$plate->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $plate->id);
    }

    public function test_cannot_show_other_users_plate(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $plate = Plate::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/plates/{$plate->id}");

        $response->assertForbidden();
    }

    public function test_can_update_plate(): void
    {
        $user = User::factory()->create();
        $plate = Plate::factory()->create(['user_id' => $user->id, 'weight' => 10, 'quantity' => 2]);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/plates/{$plate->id}", [
                'weight' => 15.5,
                'quantity' => 4,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.weight', 15.5)
            ->assertJsonPath('data.quantity', 4);

        $this->assertDatabaseHas('plates', [
            'id' => $plate->id,
            'weight' => 15.5,
            'quantity' => 4,
        ]);
    }

    public function test_cannot_update_other_users_plate(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $plate = Plate::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/plates/{$plate->id}", [
                'weight' => 15.5,
                'quantity' => 2,
            ]);

        $response->assertForbidden();
    }

    public function test_can_destroy_plate(): void
    {
        $user = User::factory()->create();
        $plate = Plate::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/plates/{$plate->id}");

        $response->assertNoContent();

        $this->assertDatabaseMissing('plates', ['id' => $plate->id]);
    }

    public function test_cannot_destroy_other_users_plate(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $plate = Plate::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/plates/{$plate->id}");

        $response->assertForbidden();
    }
}
