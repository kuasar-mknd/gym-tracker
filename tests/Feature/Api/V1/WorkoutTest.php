<?php

namespace Tests\Feature\Api\V1;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_workouts_with_includes(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create(['name' => 'Bench Press']);

        $workout = Workout::factory()->create(['user_id' => $user->id]);
        $line = $workout->workoutLines()->create(['exercise_id' => $exercise->id, 'order' => 1]);
        $line->sets()->create(['weight' => 100, 'reps' => 5, 'order' => 1]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/workouts?include=workoutLines.exercise,workoutLines.sets');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'lines' => [
                            '*' => [
                                'id',
                                'exercise' => ['id', 'name'],
                                'sets' => [
                                    '*' => ['id', 'weight', 'reps'],
                                ],
                            ],
                        ],
                    ],
                ],
            ])
            ->assertJsonFragment(['name' => 'Bench Press'])
            ->assertJsonFragment(['weight' => 100]);
    }

    public function test_user_cannot_see_others_workouts(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Workout::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/workouts');

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }
}
