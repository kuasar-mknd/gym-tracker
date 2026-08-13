<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
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
        $this->assertNotNull($workout->refresh()->ended_at);
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

        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.workout-lines.store'), [
            'workout_id' => $workout->id,
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

        Sanctum::actingAs($user);

        $response = $this->deleteJson(route('api.v1.workout-lines.destroy', $line));

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

        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.sets.store'), [
            'workout_line_id' => $line->id,
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

        Sanctum::actingAs($user);

        $response = $this->patchJson(route('api.v1.sets.update', $set), [
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

        Sanctum::actingAs($user);

        $response = $this->deleteJson(route('api.v1.sets.destroy', $set));

        $response->assertForbidden();
    }
}
