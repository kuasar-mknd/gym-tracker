<?php

declare(strict_types=1);

use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

uses(RefreshDatabase::class);

describe('SetController', function (): void {
    describe('store', function (): void {
        it('allows a user to add a set to their workout line', function (): void {
            $user = User::factory()->create();
            $workout = Workout::factory()->create(['user_id' => $user->id]);
            $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

            $response = actingAs($user)
                ->from('/workouts/'.$workout->id)
                ->post(route('sets.store', $workoutLine), [
                    'weight' => 50,
                    'reps' => 10,
                ]);

            $response->assertRedirect('/workouts/'.$workout->id);

            assertDatabaseHas('sets', [
                'workout_line_id' => $workoutLine->id,
                'weight' => 50,
                'reps' => 10,
            ]);
        });

        it('prevents a user from adding a set to another users workout line', function (): void {
            $user = User::factory()->create();
            $otherUser = User::factory()->create();
            $workout = Workout::factory()->create(['user_id' => $otherUser->id]);
            $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

            $response = actingAs($user)
                ->post(route('sets.store', $workoutLine), [
                    'weight' => 50,
                    'reps' => 10,
                ]);

            $response->assertForbidden();

            assertDatabaseMissing('sets', [
                'workout_line_id' => $workoutLine->id,
            ]);
        });

        it('prevents a user from adding a set to a completed workout', function (): void {
            $user = User::factory()->create();
            $workout = Workout::factory()->create([
                'user_id' => $user->id,
                'ended_at' => now(),
            ]);
            $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

            $response = actingAs($user)
                ->post(route('sets.store', $workoutLine), [
                    'weight' => 50,
                    'reps' => 10,
                ]);

            $response->assertForbidden();
        });

        it('validates the set data on creation', function (): void {
            $user = User::factory()->create();
            $workout = Workout::factory()->create(['user_id' => $user->id]);
            $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

            $response = actingAs($user)
                ->post(route('sets.store', $workoutLine), [
                    'weight' => -10, // Invalid weight
                    'reps' => -5,    // Invalid reps
                ]);

            $response->assertSessionHasErrors(['weight', 'reps']);
        });
    });

    describe('update', function (): void {
        it('allows a user to update their set', function (): void {
            $user = User::factory()->create();
            $workout = Workout::factory()->create(['user_id' => $user->id]);
            $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
            $set = Set::factory()->create([
                'workout_line_id' => $workoutLine->id,
                'weight' => 50,
                'reps' => 10,
            ]);

            $response = actingAs($user)
                ->from('/workouts/'.$workout->id)
                ->patch(route('sets.update', $set), [
                    'weight' => 60,
                    'reps' => 8,
                    'is_completed' => true,
                ]);

            $response->assertRedirect('/workouts/'.$workout->id);

            assertDatabaseHas('sets', [
                'id' => $set->id,
                'weight' => 60,
                'reps' => 8,
                'is_completed' => true,
            ]);
        });

        it('prevents a user from updating another users set', function (): void {
            $user = User::factory()->create();
            $otherUser = User::factory()->create();
            $workout = Workout::factory()->create(['user_id' => $otherUser->id]);
            $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
            $set = Set::factory()->create([
                'workout_line_id' => $workoutLine->id,
                'weight' => 50,
                'reps' => 10,
            ]);

            $response = actingAs($user)
                ->patch(route('sets.update', $set), [
                    'weight' => 60,
                ]);

            $response->assertForbidden();
        });

        it('prevents a user from updating a set on a completed workout', function (): void {
            $user = User::factory()->create();
            $workout = Workout::factory()->create([
                'user_id' => $user->id,
                'ended_at' => now(),
            ]);
            $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
            $set = Set::factory()->create([
                'workout_line_id' => $workoutLine->id,
                'weight' => 50,
                'reps' => 10,
            ]);

            $response = actingAs($user)
                ->patch(route('sets.update', $set), [
                    'weight' => 60,
                ]);

            $response->assertForbidden();
        });

        it('validates the set data on update', function (): void {
            $user = User::factory()->create();
            $workout = Workout::factory()->create(['user_id' => $user->id]);
            $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
            $set = Set::factory()->create([
                'workout_line_id' => $workoutLine->id,
                'weight' => 50,
                'reps' => 10,
            ]);

            $response = actingAs($user)
                ->patch(route('sets.update', $set), [
                    'weight' => -10, // Invalid weight
                    'reps' => -5,    // Invalid reps
                ]);

            $response->assertSessionHasErrors(['weight', 'reps']);
        });
    });

    describe('destroy', function (): void {
        it('allows a user to delete their set', function (): void {
            $user = User::factory()->create();
            $workout = Workout::factory()->create(['user_id' => $user->id]);
            $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
            $set = Set::factory()->create([
                'workout_line_id' => $workoutLine->id,
                'weight' => 50,
                'reps' => 10,
            ]);

            $response = actingAs($user)
                ->from('/workouts/'.$workout->id)
                ->delete(route('sets.destroy', $set));

            $response->assertRedirect('/workouts/'.$workout->id);

            assertDatabaseMissing('sets', [
                'id' => $set->id,
            ]);
        });

        it('prevents a user from deleting another users set', function (): void {
            $user = User::factory()->create();
            $otherUser = User::factory()->create();
            $workout = Workout::factory()->create(['user_id' => $otherUser->id]);
            $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
            $set = Set::factory()->create([
                'workout_line_id' => $workoutLine->id,
                'weight' => 50,
                'reps' => 10,
            ]);

            $response = actingAs($user)
                ->delete(route('sets.destroy', $set));

            $response->assertForbidden();

            assertDatabaseHas('sets', [
                'id' => $set->id,
            ]);
        });

        it('prevents a user from deleting a set on a completed workout', function (): void {
            $user = User::factory()->create();
            $workout = Workout::factory()->create([
                'user_id' => $user->id,
                'ended_at' => now(),
            ]);
            $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
            $set = Set::factory()->create([
                'workout_line_id' => $workoutLine->id,
                'weight' => 50,
                'reps' => 10,
            ]);

            $response = actingAs($user)
                ->delete(route('sets.destroy', $set));

            $response->assertForbidden();

            assertDatabaseHas('sets', [
                'id' => $set->id,
            ]);
        });
    });
});
