<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BodyPartMeasurementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_store_body_part_measurement()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('body-parts.store'), [
            'part' => 'neck',
            'value' => 35.5,
            'measured_at' => now()->format('Y-m-d'),
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('body_part_measurements', [
            'user_id' => $user->id,
            'part' => 'neck',
            'value' => 35.5,
        ]);
    }

    public function test_store_validation()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('body-parts.store'), [
            'part' => '', // Required
            'value' => 'not-a-number', // Numeric
        ]);

        $response->assertSessionHasErrors(['part', 'value', 'measured_at']);
    }
}
