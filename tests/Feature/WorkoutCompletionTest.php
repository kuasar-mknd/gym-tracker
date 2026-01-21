<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkoutCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_finish_workout(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now(), 'ended_at' => null]);

        $response = $this->actingAs($user)->patch(route('workouts.update', $workout), [
            'is_finished' => true,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertNotNull($workout->fresh()->ended_at);
        // Should redirect back or to dashboard depending on implementation, but update usually redirects back or to generic route.
        // In our Vue component we handle the redirect, but the controller likely returns a redirect.
    }

    public function test_user_cannot_modify_finished_workout(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subHour(),
            'ended_at' => now(),
        ]);

        $response = $this->actingAs($user)->patch(route('workouts.update', $workout), [
            'name' => 'New Name',
        ]);

        $response->assertForbidden();
    }

    public function test_user_cannot_add_exercise_to_finished_workout(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subHour(),
            'ended_at' => now(),
        ]);
        $exercise = Exercise::factory()->create();

        $response = $this->actingAs($user)->post(route('workout-lines.store', $workout), [
            'exercise_id' => $exercise->id,
        ]);

        $response->assertForbidden();
    }

    public function test_user_cannot_remove_exercise_from_finished_workout(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subHour(),
            'ended_at' => now(),
        ]);
        $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

        $response = $this->actingAs($user)->delete(route('workout-lines.destroy', $line));

        $response->assertForbidden();
    }

    public function test_user_cannot_add_set_to_finished_workout(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subHour(),
            'ended_at' => now(),
        ]);
        $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

        $response = $this->actingAs($user)->post(route('sets.store', $line), [
            'weight' => 100,
            'reps' => 10,
        ]);

        $response->assertForbidden();
    }

    public function test_user_cannot_update_set_in_finished_workout(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subHour(),
            'ended_at' => now(),
        ]);
        $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
        $set = Set::factory()->create(['workout_line_id' => $line->id]);

        $response = $this->actingAs($user)->patch(route('sets.update', $set), [
            'weight' => 105,
        ]);

        $response->assertForbidden();
    }

    public function test_user_cannot_delete_set_in_finished_workout(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subHour(),
            'ended_at' => now(),
        ]);
        $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
        $set = Set::factory()->create(['workout_line_id' => $line->id]);

        $response = $this->actingAs($user)->delete(route('sets.destroy', $set));

        $response->assertForbidden();
    }
}
