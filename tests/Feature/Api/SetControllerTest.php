<?php

namespace Tests\Feature\Api;

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_their_sets()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $workout = Workout::factory()->create(['user_id' => $user->id]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
        $set = Set::factory()->create(['workout_line_id' => $workoutLine->id]);

        $otherWorkout = Workout::factory()->create(['user_id' => $otherUser->id]);
        $otherWorkoutLine = WorkoutLine::factory()->create(['workout_id' => $otherWorkout->id]);
        $otherSet = Set::factory()->create(['workout_line_id' => $otherWorkoutLine->id]);

        $response = $this->actingAs($user)->getJson(route('api.v1.sets.index'));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $set->id);
    }

    public function test_user_can_create_set_in_their_workout_line()
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

        $data = [
            'workout_line_id' => $workoutLine->id,
            'weight' => 100,
            'reps' => 10,
            'is_warmup' => false,
            'is_completed' => true,
        ];

        $response = $this->actingAs($user)->postJson(route('api.v1.sets.store'), $data);

        $response->assertCreated()
            ->assertJsonPath('data.weight', 100)
            ->assertJsonPath('data.reps', 10);

        $this->assertDatabaseHas('sets', [
            'workout_line_id' => $workoutLine->id,
            'weight' => 100,
        ]);
    }

    public function test_user_cannot_create_set_in_others_workout_line()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherWorkout = Workout::factory()->create(['user_id' => $otherUser->id]);
        $otherWorkoutLine = WorkoutLine::factory()->create(['workout_id' => $otherWorkout->id]);

        $data = [
            'workout_line_id' => $otherWorkoutLine->id,
            'weight' => 100,
            'reps' => 10,
        ];

        $response = $this->actingAs($user)->postJson(route('api.v1.sets.store'), $data);

        $response->assertForbidden();
        $this->assertDatabaseCount('sets', 0);
    }

    public function test_user_can_update_their_set()
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
        $set = Set::factory()->create(['workout_line_id' => $workoutLine->id, 'weight' => 50]);

        $data = [
            'weight' => 60,
        ];

        $response = $this->actingAs($user)->patchJson(route('api.v1.sets.update', $set), $data);

        $response->assertOk()
            ->assertJsonPath('data.weight', 60);

        $this->assertDatabaseHas('sets', [
            'id' => $set->id,
            'weight' => 60,
        ]);
    }

    public function test_user_cannot_update_others_set()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherWorkout = Workout::factory()->create(['user_id' => $otherUser->id]);
        $otherWorkoutLine = WorkoutLine::factory()->create(['workout_id' => $otherWorkout->id]);
        $otherSet = Set::factory()->create(['workout_line_id' => $otherWorkoutLine->id, 'weight' => 50]);

        $data = [
            'weight' => 60,
        ];

        $response = $this->actingAs($user)->patchJson(route('api.v1.sets.update', $otherSet), $data);

        $response->assertForbidden();
        $this->assertDatabaseHas('sets', [
            'id' => $otherSet->id,
            'weight' => 50,
        ]);
    }

    public function test_user_can_delete_their_set()
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
        $set = Set::factory()->create(['workout_line_id' => $workoutLine->id]);

        $response = $this->actingAs($user)->deleteJson(route('api.v1.sets.destroy', $set));

        $response->assertNoContent();
        $this->assertDatabaseMissing('sets', ['id' => $set->id]);
    }

    public function test_user_cannot_delete_others_set()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherWorkout = Workout::factory()->create(['user_id' => $otherUser->id]);
        $otherWorkoutLine = WorkoutLine::factory()->create(['workout_id' => $otherWorkout->id]);
        $otherSet = Set::factory()->create(['workout_line_id' => $otherWorkoutLine->id]);

        $response = $this->actingAs($user)->deleteJson(route('api.v1.sets.destroy', $otherSet));

        $response->assertForbidden();
        $this->assertDatabaseHas('sets', ['id' => $otherSet->id]);
    }
}
