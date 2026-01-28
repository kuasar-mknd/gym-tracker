<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\BodyPartMeasurement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BodyPartMeasurementControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_measurements(): void
    {
        $user = User::factory()->create();
        BodyPartMeasurement::factory()->count(3)->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/body-part-measurements');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_user_cannot_see_others_measurements(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        BodyPartMeasurement::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/body-part-measurements');

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_user_can_create_measurement(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/body-part-measurements', [
            'part' => 'bicep',
            'value' => 35.5,
            'unit' => 'cm',
            'measured_at' => '2023-10-25',
            'notes' => 'Pumped',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.part', 'bicep')
            ->assertJsonPath('data.value', 35.5)
            ->assertJsonPath('data.unit', 'cm')
            ->assertJsonPath('data.measured_at', '2023-10-25');

        $this->assertDatabaseHas('body_part_measurements', [
            'user_id' => $user->id,
            'part' => 'bicep',
            'value' => 35.5,
        ]);
    }

    public function test_user_can_view_own_measurement(): void
    {
        $user = User::factory()->create();
        $measurement = BodyPartMeasurement::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/body-part-measurements/{$measurement->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $measurement->id);
    }

    public function test_user_can_update_own_measurement(): void
    {
        $user = User::factory()->create();
        $measurement = BodyPartMeasurement::factory()->create([
            'user_id' => $user->id,
            'value' => 35.0,
            'unit' => 'cm',
        ]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/body-part-measurements/{$measurement->id}", [
            'part' => $measurement->part,
            'unit' => 'cm',
            'measured_at' => $measurement->measured_at->format('Y-m-d'),
            'value' => 36.5,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.value', 36.5);

        $this->assertDatabaseHas('body_part_measurements', [
            'id' => $measurement->id,
            'value' => 36.5,
        ]);
    }

    public function test_user_can_patch_update_own_measurement(): void
    {
        $user = User::factory()->create();
        $measurement = BodyPartMeasurement::factory()->create([
            'user_id' => $user->id,
            'value' => 35.0,
            'part' => 'bicep',
        ]);

        $response = $this->actingAs($user, 'sanctum')->patchJson("/api/v1/body-part-measurements/{$measurement->id}", [
            'value' => 37.0,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.value', 37)
            ->assertJsonPath('data.part', 'bicep'); // Should remain unchanged

        $this->assertDatabaseHas('body_part_measurements', [
            'id' => $measurement->id,
            'value' => 37.0,
            'part' => 'bicep',
        ]);
    }

    public function test_user_cannot_update_others_measurement(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $measurement = BodyPartMeasurement::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/body-part-measurements/{$measurement->id}", [
            'part' => 'bicep',
            'value' => 40.0,
            'unit' => 'cm',
            'measured_at' => '2023-10-25',
        ]);

        $response->assertForbidden();
    }

    public function test_user_can_delete_own_measurement(): void
    {
        $user = User::factory()->create();
        $measurement = BodyPartMeasurement::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/body-part-measurements/{$measurement->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('body_part_measurements', ['id' => $measurement->id]);
    }

    public function test_user_cannot_delete_others_measurement(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $measurement = BodyPartMeasurement::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/body-part-measurements/{$measurement->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('body_part_measurements', ['id' => $measurement->id]);
    }

    public function test_validation_errors(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/body-part-measurements', [
            // empty
        ]);

        $response->assertJsonValidationErrors(['part', 'value', 'unit', 'measured_at']);
    }
}
