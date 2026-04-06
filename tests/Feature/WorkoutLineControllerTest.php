<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('WorkoutLineController@store', function (): void {
    it('creates a workout line and redirects (Happy Path)', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
        $exercise = Exercise::factory()->create(['user_id' => null]); // Global exercise

        $response = $this->actingAs($user)
            ->post(route('workouts.lines.store', $workout), [
                'exercise_id' => $exercise->id,
            ]);

        $response->assertRedirect(route('workouts.show', $workout));

        $this->assertDatabaseHas('workout_lines', [
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
            'order' => 0,
        ]);
    });

    it('returns 422 if exercise_id is missing', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);

        $response = $this->actingAs($user)
            ->post(route('workouts.lines.store', $workout), []);

        $response->assertStatus(302);
        $response->assertSessionHasErrors(['exercise_id']);
    });

    it('returns 422 if exercise_id does not exist', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);

        $response = $this->actingAs($user)
            ->post(route('workouts.lines.store', $workout), [
                'exercise_id' => 99999,
            ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors(['exercise_id']);
    });

    it('returns 422 if exercise belongs to another user', function (): void {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
        $exercise = Exercise::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($user)
            ->post(route('workouts.lines.store', $workout), [
                'exercise_id' => $exercise->id,
            ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors(['exercise_id']);
    });

    it('returns 403 if user is not authorized to create line for workout', function (): void {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $otherUser->id, 'ended_at' => null]);
        $exercise = Exercise::factory()->create(['user_id' => null]);

        $response = $this->actingAs($user)
            ->post(route('workouts.lines.store', $workout), [
                'exercise_id' => $exercise->id,
            ]);

        $response->assertForbidden();
    });

    it('returns 403 if workout is already completed', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => now()]);
        $exercise = Exercise::factory()->create(['user_id' => null]);

        $response = $this->actingAs($user)
            ->post(route('workouts.lines.store', $workout), [
                'exercise_id' => $exercise->id,
            ]);

        $response->assertForbidden();
    });
});

describe('WorkoutLineController@destroy', function (): void {
    it('deletes a workout line and redirects back (Happy Path)', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

        $response = $this->actingAs($user)
            ->from(route('workouts.show', $workout))
            ->delete(route('workout-lines.destroy', $workoutLine));

        $response->assertRedirect(route('workouts.show', $workout));
        $this->assertDatabaseMissing('workout_lines', ['id' => $workoutLine->id]);
    });

    it('returns 403 if user is not authorized to delete the line', function (): void {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $otherUser->id, 'ended_at' => null]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

        $response = $this->actingAs($user)
            ->delete(route('workout-lines.destroy', $workoutLine));

        $response->assertForbidden();
        $this->assertDatabaseHas('workout_lines', ['id' => $workoutLine->id]);
    });

    it('returns 403 if workout is already completed', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => now()]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

        $response = $this->actingAs($user)
            ->delete(route('workout-lines.destroy', $workoutLine));

        $response->assertForbidden();
        $this->assertDatabaseHas('workout_lines', ['id' => $workoutLine->id]);
    });
});
