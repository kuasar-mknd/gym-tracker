<?php

namespace Tests\Feature\Api\V1;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExerciseTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_exercises(): void
    {
        $user = User::factory()->create();
        Exercise::factory()->count(5)->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/exercises');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'type',
                        'category',
                    ],
                ],
                'meta', // Pagination
            ]);
    }

    public function test_can_filter_exercises(): void
    {
        $user = User::factory()->create();
        Exercise::factory()->create(['name' => 'Bench Press', 'type' => 'strength']);
        Exercise::factory()->create(['name' => 'Running', 'type' => 'cardio']);

        // Filter by type
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/exercises?filter[type]=strength');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['name' => 'Bench Press']);

        // Filter by name
        $response2 = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/exercises?filter[name]=Run');

        $response2->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['name' => 'Running']);
    }
}
