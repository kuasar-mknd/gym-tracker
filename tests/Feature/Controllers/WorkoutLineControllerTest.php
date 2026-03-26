<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;

use function Pest\Laravel\actingAs;

test('authenticated user can add a line to their active workout', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
    $exercise = Exercise::factory()->create(['user_id' => null]); // Global exercise

    actingAs($user)
        ->post(route('workout-lines.store', $workout), [
            'exercise_id' => $exercise->id,
        ])
        ->assertRedirect(route('workouts.show', $workout));

    $this->assertDatabaseHas('workout_lines', [
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
        'order' => 0,
    ]);
});

test('authenticated user cannot add a line to another users workout', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user2->id, 'ended_at' => null]);
    $exercise = Exercise::factory()->create(['user_id' => null]);

    actingAs($user1)
        ->post(route('workout-lines.store', $workout), [
            'exercise_id' => $exercise->id,
        ])
        ->assertForbidden();

    $this->assertDatabaseMissing('workout_lines', [
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);
});

test('authenticated user cannot add a line to an ended workout', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => now()]);
    $exercise = Exercise::factory()->create(['user_id' => null]);

    actingAs($user)
        ->post(route('workout-lines.store', $workout), [
            'exercise_id' => $exercise->id,
        ])
        ->assertForbidden();

    $this->assertDatabaseMissing('workout_lines', [
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);
});

test('authenticated user cannot add a line with missing exercise_id', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);

    actingAs($user)
        ->post(route('workout-lines.store', $workout), [])
        ->assertSessionHasErrors(['exercise_id']);

    $this->assertDatabaseCount('workout_lines', 0);
});

test('authenticated user cannot add a line with another users exercise', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user1->id, 'ended_at' => null]);
    $exercise = Exercise::factory()->create(['user_id' => $user2->id]);

    actingAs($user1)
        ->post(route('workout-lines.store', $workout), [
            'exercise_id' => $exercise->id,
        ])
        ->assertSessionHasErrors(['exercise_id']);

    $this->assertDatabaseMissing('workout_lines', [
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);
});

test('authenticated user can delete their own workout line in an active workout', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    actingAs($user)
        ->delete(route('workout-lines.destroy', $workoutLine))
        ->assertRedirect(); // back() redirect

    $this->assertDatabaseMissing('workout_lines', [
        'id' => $workoutLine->id,
    ]);
});

test('authenticated user cannot delete another users workout line', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user2->id, 'ended_at' => null]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    actingAs($user1)
        ->delete(route('workout-lines.destroy', $workoutLine))
        ->assertForbidden();

    $this->assertDatabaseHas('workout_lines', [
        'id' => $workoutLine->id,
    ]);
});

test('authenticated user cannot delete a workout line from an ended workout', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => now()]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    actingAs($user)
        ->delete(route('workout-lines.destroy', $workoutLine))
        ->assertForbidden();

    $this->assertDatabaseHas('workout_lines', [
        'id' => $workoutLine->id,
    ]);
});
