<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Laravel\Sanctum\Sanctum;

describe('WorkoutLineController@store', function (): void {
    it('creates a workout line and returns it (Happy Path)', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
        $exercise = Exercise::factory()->create(['user_id' => null]); // Global exercise

        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.workout-lines.store'), [
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('workout_lines', [
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
            'order' => 0,
        ]);
    });

    it('returns 422 if exercise_id is missing', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);

        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.workout-lines.store'), [
            'workout_id' => $workout->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['exercise_id']);
    });

    it('returns 422 if exercise_id does not exist', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);

        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.workout-lines.store'), [
            'workout_id' => $workout->id,
            'exercise_id' => 99999,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['exercise_id']);
    });

    it('returns 422 if exercise belongs to another user', function (): void {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
        $exercise = Exercise::factory()->create(['user_id' => $otherUser->id]);

        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.workout-lines.store'), [
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['exercise_id']);
    });

    /**
     * The web route answered 403 here, from the policy. The API rejects the same
     * attempt one layer earlier: `workout_id` only accepts the caller's own
     * workouts, so an outsider's workout is simply not a valid value.
     */
    it('returns 422 if the workout belongs to another user', function (): void {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $otherUser->id, 'ended_at' => null]);
        $exercise = Exercise::factory()->create(['user_id' => null]);

        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.workout-lines.store'), [
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['workout_id']);
        $this->assertDatabaseCount('workout_lines', 0);
    });

    it('returns 403 if workout is already completed', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => now()]);
        $exercise = Exercise::factory()->create(['user_id' => null]);

        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.workout-lines.store'), [
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);

        $response->assertForbidden();
    });
});

describe('WorkoutLineController@destroy', function (): void {
    it('deletes a workout line and returns no content (Happy Path)', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

        Sanctum::actingAs($user);

        $response = $this->deleteJson(route('api.v1.workout-lines.destroy', $workoutLine));

        $response->assertNoContent();
        $this->assertDatabaseMissing('workout_lines', ['id' => $workoutLine->id]);
    });

    it('hides a line belonging to someone else', function (): void {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $otherUser->id, 'ended_at' => null]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

        Sanctum::actingAs($user);

        $response = $this->deleteJson(route('api.v1.workout-lines.destroy', $workoutLine));

        $response->assertNotFound();
        $this->assertDatabaseHas('workout_lines', ['id' => $workoutLine->id]);
    });

    it('returns 403 if workout is already completed', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => now()]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

        Sanctum::actingAs($user);

        $response = $this->deleteJson(route('api.v1.workout-lines.destroy', $workoutLine));

        $response->assertForbidden();
        $this->assertDatabaseHas('workout_lines', ['id' => $workoutLine->id]);
    });
});
