<?php

declare(strict_types=1);

use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

describe('SetController Store', function (): void {
    it('allows user to add a set to their active workout', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

        $payload = [
            'weight' => 100,
            'reps' => 10,
            'is_warmup' => false,
        ];

        actingAs($user)
            ->post(route('sets.store', $workoutLine), $payload)
            ->assertRedirect();

        assertDatabaseHas('sets', [
            'workout_line_id' => $workoutLine->id,
            'weight' => 100,
            'reps' => 10,
            'is_warmup' => false,
        ]);
    });

    it('rejects adding a set to a completed workout', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => now()]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

        $payload = [
            'weight' => 100,
            'reps' => 10,
        ];

        actingAs($user)
            ->post(route('sets.store', $workoutLine), $payload)
            ->assertForbidden();
    });

    it('rejects adding a set to another user\'s workout line', function (): void {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $otherUser->id, 'ended_at' => null]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

        $payload = [
            'weight' => 100,
            'reps' => 10,
        ];

        actingAs($user)
            ->post(route('sets.store', $workoutLine), $payload)
            ->assertForbidden();
    });

    it('validates store request correctly', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

        $payload = [
            'weight' => -10, // Invalid: negative weight
            'reps' => -5,    // Invalid: negative reps
        ];

        actingAs($user)
            ->post(route('sets.store', $workoutLine), $payload)
            ->assertSessionHasErrors(['weight', 'reps']);
    });
});

describe('SetController Update', function (): void {
    it('allows user to update their own set in an active workout', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
        $set = Set::factory()->create([
            'workout_line_id' => $workoutLine->id,
            'weight' => 50,
            'reps' => 5,
            'is_warmup' => false,
        ]);

        $payload = [
            'weight' => 100,
            'reps' => 10,
            'is_completed' => true,
        ];

        actingAs($user)
            ->patch(route('sets.update', $set), $payload)
            ->assertRedirect();

        assertDatabaseHas('sets', [
            'id' => $set->id,
            'weight' => 100,
            'reps' => 10,
            'is_completed' => true,
        ]);
    });

    it('rejects updating a set in a completed workout', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => now()]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
        $set = Set::factory()->create(['workout_line_id' => $workoutLine->id]);

        $payload = [
            'weight' => 100,
        ];

        actingAs($user)
            ->patch(route('sets.update', $set), $payload)
            ->assertForbidden();
    });

    it('rejects updating a set belonging to another user', function (): void {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $otherUser->id, 'ended_at' => null]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
        $set = Set::factory()->create(['workout_line_id' => $workoutLine->id]);

        $payload = [
            'weight' => 100,
        ];

        actingAs($user)
            ->patch(route('sets.update', $set), $payload)
            ->assertForbidden();
    });

    it('validates update request correctly', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
        $set = Set::factory()->create(['workout_line_id' => $workoutLine->id]);

        $payload = [
            'duration_seconds' => -10, // Invalid
            'distance_km' => -5,      // Invalid
        ];

        actingAs($user)
            ->patch(route('sets.update', $set), $payload)
            ->assertSessionHasErrors(['duration_seconds', 'distance_km']);
    });
});

describe('SetController Destroy', function (): void {
    it('allows user to delete their own set from an active workout', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
        $set = Set::factory()->create(['workout_line_id' => $workoutLine->id]);

        actingAs($user)
            ->delete(route('sets.destroy', $set))
            ->assertRedirect();

        assertDatabaseMissing('sets', [
            'id' => $set->id,
        ]);
    });

    it('rejects deleting a set from a completed workout', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => now()]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
        $set = Set::factory()->create(['workout_line_id' => $workoutLine->id]);

        actingAs($user)
            ->delete(route('sets.destroy', $set))
            ->assertForbidden();

        assertDatabaseHas('sets', [
            'id' => $set->id,
        ]);
    });

    it('rejects deleting a set belonging to another user', function (): void {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $otherUser->id, 'ended_at' => null]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
        $set = Set::factory()->create(['workout_line_id' => $workoutLine->id]);

        actingAs($user)
            ->delete(route('sets.destroy', $set))
            ->assertForbidden();

        assertDatabaseHas('sets', [
            'id' => $set->id,
        ]);
    });
});
