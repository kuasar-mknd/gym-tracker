<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('WorkoutLineController', function () {
    describe('store', function () {
        it('allows a user to add a workout line to their workout', function () {
            $user = User::factory()->create();
            $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
            $exercise = Exercise::factory()->create();

            $response = $this->actingAs($user)
                ->post(route('workout-lines.store', $workout), [
                    'exercise_id' => $exercise->id,
                ]);

            $response->assertRedirect(route('workouts.show', $workout));

            $this->assertDatabaseHas('workout_lines', [
                'workout_id' => $workout->id,
                'exercise_id' => $exercise->id,
                'order' => 0,
            ]);
        });

        it('assigns correct order when adding multiple workout lines', function () {
            $user = User::factory()->create();
            $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
            $exercise1 = Exercise::factory()->create();
            $exercise2 = Exercise::factory()->create();

            WorkoutLine::factory()->create([
                'workout_id' => $workout->id,
                'exercise_id' => $exercise1->id,
            ]);

            $response = $this->actingAs($user)
                ->post(route('workout-lines.store', $workout), [
                    'exercise_id' => $exercise2->id,
                ]);

            $response->assertRedirect(route('workouts.show', $workout));

            $this->assertDatabaseHas('workout_lines', [
                'workout_id' => $workout->id,
                'exercise_id' => $exercise2->id,
                'order' => 1,
            ]);
        });

        it('returns validation error if exercise_id is missing', function () {
            $user = User::factory()->create();
            $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);

            $response = $this->actingAs($user)
                ->post(route('workout-lines.store', $workout), []);

            $response->assertSessionHasErrors(['exercise_id']);
            $this->assertDatabaseCount('workout_lines', 0);
        });

        it('prevents adding a workout line to an ended workout', function () {
            $user = User::factory()->create();
            $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => now()]);
            $exercise = Exercise::factory()->create();

            $response = $this->actingAs($user)
                ->post(route('workout-lines.store', $workout), [
                    'exercise_id' => $exercise->id,
                ]);

            $response->assertForbidden();
            $this->assertDatabaseCount('workout_lines', 0);
        });

        it('prevents adding a workout line to another users workout', function () {
            $user = User::factory()->create();
            $otherUser = User::factory()->create();
            $workout = Workout::factory()->create(['user_id' => $otherUser->id, 'ended_at' => null]);
            $exercise = Exercise::factory()->create();

            $response = $this->actingAs($user)
                ->post(route('workout-lines.store', $workout), [
                    'exercise_id' => $exercise->id,
                ]);

            $response->assertForbidden();
            $this->assertDatabaseCount('workout_lines', 0);
        });

        it('prevents adding a workout line with another users exercise', function () {
            $user = User::factory()->create();
            $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);

            $otherUser = User::factory()->create();
            $exercise = Exercise::factory()->create(['user_id' => $otherUser->id]);

            $response = $this->actingAs($user)
                ->post(route('workout-lines.store', $workout), [
                    'exercise_id' => $exercise->id,
                ]);

            $response->assertSessionHasErrors(['exercise_id']);
            $this->assertDatabaseCount('workout_lines', 0);
        });
    });

    describe('destroy', function () {
        it('allows a user to delete their workout line', function () {
            $user = User::factory()->create();
            $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
            $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

            $response = $this->actingAs($user)
                ->delete(route('workout-lines.destroy', $workoutLine));

            $response->assertRedirect();
            $this->assertDatabaseMissing('workout_lines', [
                'id' => $workoutLine->id,
            ]);
        });

        it('prevents deleting a workout line from an ended workout', function () {
            $user = User::factory()->create();
            $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => now()]);
            $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

            $response = $this->actingAs($user)
                ->delete(route('workout-lines.destroy', $workoutLine));

            $response->assertForbidden();
            $this->assertDatabaseHas('workout_lines', [
                'id' => $workoutLine->id,
            ]);
        });

        it('prevents deleting a workout line from another users workout', function () {
            $user = User::factory()->create();
            $otherUser = User::factory()->create();
            $workout = Workout::factory()->create(['user_id' => $otherUser->id, 'ended_at' => null]);
            $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

            $response = $this->actingAs($user)
                ->delete(route('workout-lines.destroy', $workoutLine));

            $response->assertForbidden();
            $this->assertDatabaseHas('workout_lines', [
                'id' => $workoutLine->id,
            ]);
        });
    });
});
