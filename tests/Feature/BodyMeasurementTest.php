<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\BodyMeasurement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BodyMeasurementTest extends TestCase
{
    use RefreshDatabase;

    public function test_measurements_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/body-measurements');

        $response->assertOk();
    }

    public function test_can_add_measurement(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/body-measurements', [
            'weight' => 80.5,
            'measured_at' => '2023-01-01',
            'notes' => 'Test note',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('body_measurements', [
            'user_id' => $user->id,
            'weight' => 80.5,
            'measured_at' => '2023-01-01',
        ]);
    }

    public function test_can_delete_measurement(): void
    {
        $user = User::factory()->create();
        $measurement = BodyMeasurement::factory()->create([
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->delete("/body-measurements/{$measurement->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('body_measurements', [
            'id' => $measurement->id,
        ]);
    }

    public function test_cannot_delete_others_measurement(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $measurement = BodyMeasurement::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        $response = $this->actingAs($user)->delete("/body-measurements/{$measurement->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('body_measurements', [
            'id' => $measurement->id,
        ]);
    }
}
