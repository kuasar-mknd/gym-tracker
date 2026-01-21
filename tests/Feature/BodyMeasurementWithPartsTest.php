<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BodyMeasurementWithPartsTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_store_measurement_with_parts()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('body-measurements.store'), [
            'weight' => 80.5,
            'body_fat' => 15.2,
            'measured_at' => now()->format('Y-m-d'),
            'parts' => [
                ['part' => 'neck', 'value' => 35],
                ['part' => 'waist', 'value' => 85],
            ],
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('body_measurements', [
            'user_id' => $user->id,
            'weight' => 80.5,
            'body_fat' => 15.2,
        ]);

        $this->assertDatabaseHas('body_part_measurements', [
            'user_id' => $user->id,
            'part' => 'neck',
            'value' => 35,
        ]);

        $this->assertDatabaseHas('body_part_measurements', [
            'user_id' => $user->id,
            'part' => 'waist',
            'value' => 85,
        ]);
    }
}
