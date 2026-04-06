<?php

declare(strict_types=1);

use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('SetController@store', function (): void {
    it('creates a set and redirects (Happy Path)', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

        $response = $this->actingAs($user)
            ->post(route('sets.store', $workoutLine), [
                'weight' => 100,
                'reps' => 10,
                'is_warmup' => false,
            ]);

        $response->assertStatus(302);
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

        $response = $this->actingAs($user)
            ->post(route('sets.store', $workoutLine), [
                'weight' => -10,
            ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors(['weight']);
    });

    it('returns 403 if user is not authorized', function (): void {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $otherUser->id, 'ended_at' => null]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

        $response = $this->actingAs($user)
            ->post(route('sets.store', $workoutLine), [
                'weight' => 100,
                'reps' => 10,
            ]);

        $response->assertForbidden();
    });

    it('returns 403 if workout is completed', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => now()]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

        $response = $this->actingAs($user)
            ->post(route('sets.store', $workoutLine), [
                'weight' => 100,
                'reps' => 10,
            ]);

        $response->assertForbidden();
    });
});

describe('SetController@update', function (): void {
    it('updates a set and redirects (Happy Path)', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
        $set = Set::factory()->create(['workout_line_id' => $workoutLine->id, 'weight' => 50, 'reps' => 5]);

        $response = $this->actingAs($user)
            ->patch(route('sets.update', $set), [
                'weight' => 60,
                'reps' => 8,
            ]);

        $response->assertStatus(302);
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

        $response = $this->actingAs($user)
            ->patch(route('sets.update', $set), [
                'weight' => -60,
            ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors(['weight']);
    });

    it('returns 403 if user is not authorized', function (): void {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $otherUser->id, 'ended_at' => null]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
        $set = Set::factory()->create(['workout_line_id' => $workoutLine->id]);

        $response = $this->actingAs($user)
            ->patch(route('sets.update', $set), [
                'weight' => 60,
            ]);

        $response->assertForbidden();
    });

    it('returns 403 if workout is completed', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => now()]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
        $set = Set::factory()->create(['workout_line_id' => $workoutLine->id]);

        $response = $this->actingAs($user)
            ->patch(route('sets.update', $set), [
                'weight' => 60,
            ]);

        $response->assertForbidden();
    });
});

describe('SetController@destroy', function (): void {
    it('deletes a set and redirects (Happy Path)', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
        $set = Set::factory()->create(['workout_line_id' => $workoutLine->id]);

        $response = $this->actingAs($user)
            ->delete(route('sets.destroy', $set));

        $response->assertStatus(302);
        $this->assertDatabaseMissing('sets', ['id' => $set->id]);
    });

    it('returns 403 if user is not authorized', function (): void {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $otherUser->id, 'ended_at' => null]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
        $set = Set::factory()->create(['workout_line_id' => $workoutLine->id]);

        $response = $this->actingAs($user)
            ->delete(route('sets.destroy', $set));

        $response->assertForbidden();
        $this->assertDatabaseHas('sets', ['id' => $set->id]);
    });

    it('returns 403 if workout is completed', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => now()]);
        $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
        $set = Set::factory()->create(['workout_line_id' => $workoutLine->id]);

        $response = $this->actingAs($user)
            ->delete(route('sets.destroy', $set));

        $response->assertForbidden();
        $this->assertDatabaseHas('sets', ['id' => $set->id]);
    });
});
