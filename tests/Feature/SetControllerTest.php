<?php

declare(strict_types=1);

use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('stores a new set', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    $data = [
        'weight' => 100,
        'reps' => 10,
        'duration_seconds' => null,
        'distance_km' => null,
        'is_warmup' => false,
    ];

    $response = $this->actingAs($user)
        ->from('/some-url')
        ->post(route('sets.store', $workoutLine), $data);

    $response->assertRedirect('/some-url');

    $this->assertDatabaseHas('sets', [
        'workout_line_id' => $workoutLine->id,
        'weight' => 100,
        'reps' => 10,
        'is_warmup' => false,
    ]);
});

it('cannot store a set for another user\'s workout', function (): void {
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

it('cannot store a set for an ended workout', function (): void {
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

it('validates store request', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    $response = $this->actingAs($user)
        ->post(route('sets.store', $workoutLine), [
            'weight' => -10, // Invalid: min:0
        ]);

    $response->assertInvalid(['weight']);
});

it('updates an existing set', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    $set = Set::factory()->create([
        'workout_line_id' => $workoutLine->id,
        'weight' => 50,
        'reps' => 5,
    ]);

    $data = [
        'weight' => 120,
        'reps' => 12,
        'is_completed' => true,
    ];

    $response = $this->actingAs($user)
        ->from('/some-url')
        ->patch(route('sets.update', $set), $data);

    $response->assertRedirect('/some-url');

    $this->assertDatabaseHas('sets', [
        'id' => $set->id,
        'weight' => 120,
        'reps' => 12,
        'is_completed' => true,
    ]);
});

it('cannot update a set for another user\'s workout', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $otherUser->id, 'ended_at' => null]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    $set = Set::factory()->create(['workout_line_id' => $workoutLine->id]);

    $response = $this->actingAs($user)
        ->patch(route('sets.update', $set), [
            'weight' => 100,
        ]);

    $response->assertForbidden();
});

it('cannot update a set for an ended workout', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => now()]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    $set = Set::factory()->create(['workout_line_id' => $workoutLine->id]);

    $response = $this->actingAs($user)
        ->patch(route('sets.update', $set), [
            'weight' => 100,
        ]);

    $response->assertForbidden();
});

it('validates update request', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    $set = Set::factory()->create(['workout_line_id' => $workoutLine->id]);

    $response = $this->actingAs($user)
        ->patch(route('sets.update', $set), [
            'reps' => -5, // Invalid: min:0
        ]);

    $response->assertInvalid(['reps']);
});

it('destroys a set', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    $set = Set::factory()->create(['workout_line_id' => $workoutLine->id]);

    $response = $this->actingAs($user)
        ->from('/some-url')
        ->delete(route('sets.destroy', $set));

    $response->assertRedirect('/some-url');

    $this->assertDatabaseMissing('sets', [
        'id' => $set->id,
    ]);
});

it('cannot destroy a set for another user\'s workout', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $otherUser->id, 'ended_at' => null]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    $set = Set::factory()->create(['workout_line_id' => $workoutLine->id]);

    $response = $this->actingAs($user)
        ->delete(route('sets.destroy', $set));

    $response->assertForbidden();
});

it('cannot destroy a set for an ended workout', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => now()]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    $set = Set::factory()->create(['workout_line_id' => $workoutLine->id]);

    $response = $this->actingAs($user)
        ->delete(route('sets.destroy', $set));

    $response->assertForbidden();
});
