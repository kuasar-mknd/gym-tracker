<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetsTest extends TestCase
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

        $this->actingAs($user)
            ->post(route('sets.store', $workoutLine), [
                'weight' => 50.5,
                'reps' => 10,
                'is_warmup' => true,
            ])
            ->assertRedirect();

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

        $this->actingAs($user)
            ->post(route('sets.store', $workoutLine), [
                'weight' => 'not-a-number',
                'reps' => -5,
            ])
            ->assertSessionHasErrors(['weight', 'reps']);
    }

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

        $this->actingAs($user)
            ->post(route('sets.store', $workoutLine), [
                'weight' => 50,
                'reps' => 10,
            ])
            ->assertForbidden();
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

        $this->actingAs($user)
            ->patch(route('sets.update', $set), [
                'weight' => 60,
                'reps' => 8,
                'is_warmup' => false,
            ])
            ->assertRedirect();

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

        $this->actingAs($user)
            ->patch(route('sets.update', $set), [
                'weight' => 'invalid',
                'reps' => -1,
            ])
            ->assertSessionHasErrors(['weight', 'reps']);
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

        $this->actingAs($user)
            ->patch(route('sets.update', $set), [
                'weight' => 60,
                'reps' => 8,
            ])
            ->assertForbidden();
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

        $this->actingAs($user)
            ->delete(route('sets.destroy', $set))
            ->assertRedirect();

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

        $this->actingAs($user)
            ->delete(route('sets.destroy', $set))
            ->assertForbidden();
    }
}
