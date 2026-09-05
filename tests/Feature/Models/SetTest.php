<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SetTest extends TestCase
{
    use RefreshDatabase;

    public function test_allows_authenticated_user_to_add_a_set_to_their_workout_line(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id]);
        $exercise = Exercise::factory()->create();
        $workoutLine = WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);

        Sanctum::actingAs($user);

        $this->postJson(route('api.v1.sets.store'), [
            'workout_line_id' => $workoutLine->id,
            'weight' => 50.5,
            'reps' => 10,
            'is_warmup' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('sets', [
            'workout_line_id' => $workoutLine->id,
            'weight' => 50.5,
            'reps' => 10,
            'is_warmup' => true,
        ]);
    }

    public function test_validates_input_when_adding_a_set(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id]);
        $exercise = Exercise::factory()->create();
        $workoutLine = WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);

        Sanctum::actingAs($user);

        $this->postJson(route('api.v1.sets.store'), [
            'workout_line_id' => $workoutLine->id,
            'weight' => 'not-a-number',
            'reps' => -5,
        ])->assertJsonValidationErrors(['weight', 'reps']);
    }

    /**
     * The web route answered 403 here, from the policy. The API rejects the same
     * attempt one layer earlier: `workout_line_id` only accepts lines belonging
     * to the caller, so an outsider's line is simply not a valid value.
     */
    public function test_prevents_user_from_adding_a_set_to_another_users_workout_line(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $otherUser->id]);
        $exercise = Exercise::factory()->create();
        $workoutLine = WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);

        Sanctum::actingAs($user);

        $this->postJson(route('api.v1.sets.store'), [
            'workout_line_id' => $workoutLine->id,
            'weight' => 50,
            'reps' => 10,
        ])->assertJsonValidationErrors('workout_line_id');

        $this->assertDatabaseCount('sets', 0);
    }

    public function test_allows_authenticated_user_to_update_their_set(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id]);
        $exercise = Exercise::factory()->create();
        $workoutLine = WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);
        $set = Set::factory()->create([
            'workout_line_id' => $workoutLine->id,
            'weight' => 50,
            'reps' => 10,
        ]);

        Sanctum::actingAs($user);

        $this->patchJson(route('api.v1.sets.update', $set), [
            'weight' => 60,
            'reps' => 8,
            'is_warmup' => false,
        ])->assertOk();

        $this->assertDatabaseHas('sets', [
            'id' => $set->id,
            'weight' => 60,
            'reps' => 8,
        ]);
    }

    public function test_validates_input_when_updating_a_set(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id]);
        $exercise = Exercise::factory()->create();
        $workoutLine = WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);
        $set = Set::factory()->create([
            'workout_line_id' => $workoutLine->id,
        ]);

        Sanctum::actingAs($user);

        $this->patchJson(route('api.v1.sets.update', $set), [
            'weight' => 'invalid',
            'reps' => -1,
        ])->assertJsonValidationErrors(['weight', 'reps']);
    }

    public function test_prevents_user_from_updating_another_users_set(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $otherUser->id]);
        $exercise = Exercise::factory()->create();
        $workoutLine = WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);
        $set = Set::factory()->create([
            'workout_line_id' => $workoutLine->id,
        ]);

        Sanctum::actingAs($user);

        $this->patchJson(route('api.v1.sets.update', $set), [
            'weight' => 60,
            'reps' => 8,
        ])->assertNotFound();
    }

    public function test_allows_authenticated_user_to_delete_their_set(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id]);
        $exercise = Exercise::factory()->create();
        $workoutLine = WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);
        $set = Set::factory()->create([
            'workout_line_id' => $workoutLine->id,
        ]);

        Sanctum::actingAs($user);

        $this->deleteJson(route('api.v1.sets.destroy', $set))->assertNoContent();

        $this->assertDatabaseMissing('sets', [
            'id' => $set->id,
        ]);
    }

    public function test_prevents_user_from_deleting_another_users_set(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $otherUser->id]);
        $exercise = Exercise::factory()->create();
        $workoutLine = WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);
        $set = Set::factory()->create([
            'workout_line_id' => $workoutLine->id,
        ]);

        Sanctum::actingAs($user);

        $this->deleteJson(route('api.v1.sets.destroy', $set))->assertNotFound();
    }

    public function test_it_increments_volumes_on_set_creation(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'workout_volume' => 0]);
        $exercise = Exercise::factory()->create();
        $workoutLine = WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);

        Set::factory()->create([
            'workout_line_id' => $workoutLine->id,
            'weight' => 50,
            'reps' => 10,
        ]);

        $this->assertEquals(500, $user->volumeSouleve());
        $this->assertEquals(500, $workout->refresh()->workout_volume);
    }

    public function test_it_updates_volumes_on_set_update(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'workout_volume' => 0]);
        $exercise = Exercise::factory()->create();
        $workoutLine = WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);

        $set = Set::factory()->create([
            'workout_line_id' => $workoutLine->id,
            'weight' => 50,
            'reps' => 10,
        ]);

        $set->update(['weight' => 60, 'reps' => 10]);

        $this->assertEquals(600, $user->volumeSouleve());
        $this->assertEquals(600, $workout->refresh()->workout_volume);
    }

    public function test_it_decrements_volumes_on_set_deletion(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'workout_volume' => 0]);
        $exercise = Exercise::factory()->create();
        $workoutLine = WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);

        $set = Set::factory()->create([
            'workout_line_id' => $workoutLine->id,
            'weight' => 50,
            'reps' => 10,
        ]);

        $set->delete();

        $this->assertEquals(0, $user->volumeSouleve());
        $this->assertEquals(0, $workout->refresh()->workout_volume);
    }

    public function test_it_handles_missing_relations_gracefully(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'workout_volume' => 0]);
        $exercise = Exercise::factory()->create();
        $workoutLine = WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);

        $set = Set::factory()->create([
            'workout_line_id' => $workoutLine->id,
            'weight' => 50,
            'reps' => 10,
        ]);

        $workout->delete();
        $set->delete();

        $this->expectNotToPerformAssertions();
    }
}
