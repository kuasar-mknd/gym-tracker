<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\BodyMeasurement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BodyMeasurementTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_measurements(): void
    {
        $user = User::factory()->create();
        BodyMeasurement::factory()->count(3)->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/body-measurements');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_user_cannot_see_others_measurements(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        BodyMeasurement::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/body-measurements');

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_api_is_protected(): void
    {
        $response = $this->getJson('/api/v1/body-measurements');
        $response->assertUnauthorized();
    }

    public function test_user_can_create_measurement(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/body-measurements', [
            'weight' => 75.5,
            'measured_at' => '2023-10-25',
            'notes' => 'Feeling good',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.weight', '75.50')
            ->assertJsonPath('data.measured_at', '2023-10-25');

        $this->assertDatabaseHas('body_measurements', [
            'user_id' => $user->id,
            'weight' => 75.5,
        ]);
    }

    public function test_user_can_view_own_measurement(): void
    {
        $user = User::factory()->create();
        $measurement = BodyMeasurement::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/body-measurements/{$measurement->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $measurement->id);
    }

    public function test_user_can_update_own_measurement(): void
    {
        $user = User::factory()->create();
        $measurement = BodyMeasurement::factory()->create(['user_id' => $user->id, 'weight' => 70.0]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/body-measurements/{$measurement->id}", [
            'weight' => 72.5,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.weight', '72.50');

        $this->assertDatabaseHas('body_measurements', [
            'id' => $measurement->id,
            'weight' => 72.5,
        ]);
    }

    public function test_user_cannot_update_others_measurement(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $measurement = BodyMeasurement::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/body-measurements/{$measurement->id}", [
            'weight' => 80.0,
        ]);

        $response->assertForbidden();
    }

    public function test_user_can_delete_own_measurement(): void
    {
        $user = User::factory()->create();
        $measurement = BodyMeasurement::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/body-measurements/{$measurement->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('body_measurements', ['id' => $measurement->id]);
    }

    public function test_user_cannot_delete_others_measurement(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $measurement = BodyMeasurement::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/body-measurements/{$measurement->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('body_measurements', ['id' => $measurement->id]);
    }
}
