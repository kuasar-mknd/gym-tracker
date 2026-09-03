<?php

declare(strict_types=1);

use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Laravel\Sanctum\Sanctum;

describe('SetController@store', function (): void {
    it('creates a set and returns it (Happy Path)', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.sets.store'), [
            'workout_line_id' => $workoutLine->id,
            'weight' => 100,
            'reps' => 10,
            'is_warmup' => false,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.weight', 100);
        $this->assertDatabaseHas('sets', [
            'workout_line_id' => $workoutLine->id,
            'weight' => 100,
            'reps' => 10,
            'is_warmup' => false,
        ]);
    });

    it('returns 422 if validation fails', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.sets.store'), [
            'workout_line_id' => $workoutLine->id,
            'weight' => -10,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['weight']);
    });

    /**
     * The web route answered 403 here, from the policy. The API rejects the same
     * attempt one layer earlier: `workout_line_id` only accepts lines belonging
     * to the caller, so an outsider's line is simply not a valid value.
     */
    it('returns 422 if the workout line belongs to another user', function (): void {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $otherUser->id, 'ended_at' => null]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.sets.store'), [
            'workout_line_id' => $workoutLine->id,
            'weight' => 100,
            'reps' => 10,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['workout_line_id']);
        $this->assertDatabaseCount('sets', 0);
    });

    it('returns 403 if workout is completed', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => now()]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.sets.store'), [
            'workout_line_id' => $workoutLine->id,
            'weight' => 100,
            'reps' => 10,
        ]);

        $response->assertForbidden();
    });
});

describe('SetController@update', function (): void {
    it('updates a set and returns it (Happy Path)', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
        $set = Set::factory()->create(['workout_line_id' => $workoutLine->id, 'weight' => 50, 'reps' => 5]);

        Sanctum::actingAs($user);

        $response = $this->patchJson(route('api.v1.sets.update', $set), [
            'weight' => 60,
            'reps' => 8,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('sets', [
            'id' => $set->id,
            'weight' => 60,
            'reps' => 8,
        ]);
    });

    it('returns 422 if validation fails', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
        $set = Set::factory()->create(['workout_line_id' => $workoutLine->id, 'weight' => 50, 'reps' => 5]);

        Sanctum::actingAs($user);

        $response = $this->patchJson(route('api.v1.sets.update', $set), [
            'weight' => -60,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['weight']);
    });

    it('hides a set belonging to someone else', function (): void {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $otherUser->id, 'ended_at' => null]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
        $set = Set::factory()->create(['workout_line_id' => $workoutLine->id]);

        Sanctum::actingAs($user);

        $response = $this->patchJson(route('api.v1.sets.update', $set), [
            'weight' => 60,
        ]);

        $response->assertNotFound();
    });

    it('returns 403 if workout is completed', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => now()]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
        $set = Set::factory()->create(['workout_line_id' => $workoutLine->id]);

        Sanctum::actingAs($user);

        $response = $this->patchJson(route('api.v1.sets.update', $set), [
            'weight' => 60,
        ]);

        $response->assertForbidden();
    });
});

describe('SetController@destroy', function (): void {
    it('deletes a set and returns no content (Happy Path)', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
        $set = Set::factory()->create(['workout_line_id' => $workoutLine->id]);

        Sanctum::actingAs($user);

        $response = $this->deleteJson(route('api.v1.sets.destroy', $set));

        $response->assertNoContent();
        $this->assertDatabaseMissing('sets', ['id' => $set->id]);
    });

    it('hides a set belonging to someone else', function (): void {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $otherUser->id, 'ended_at' => null]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
        $set = Set::factory()->create(['workout_line_id' => $workoutLine->id]);

        Sanctum::actingAs($user);

        $response = $this->deleteJson(route('api.v1.sets.destroy', $set));

        $response->assertNotFound();
        $this->assertDatabaseHas('sets', ['id' => $set->id]);
    });

    it('returns 403 if workout is completed', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => now()]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
        $set = Set::factory()->create(['workout_line_id' => $workoutLine->id]);

        Sanctum::actingAs($user);

        $response = $this->deleteJson(route('api.v1.sets.destroy', $set));

        $response->assertForbidden();
        $this->assertDatabaseHas('sets', ['id' => $set->id]);
    });
});
