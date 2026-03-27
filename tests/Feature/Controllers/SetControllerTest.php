<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();

    $this->workout = Workout::factory()->create([
        'user_id' => $this->user->id,
        'started_at' => now(),
        'ended_at' => null,
    ]);

    $this->workoutLine = WorkoutLine::factory()->create([
        'workout_id' => $this->workout->id,
    ]);

    $this->set = Set::factory()->create([
        'workout_line_id' => $this->workoutLine->id,
        'weight' => 100,
        'reps' => 10,
    ]);
});

// Happy Path Tests

it('can store a new set', function (): void {
    $data = [
        'weight' => 50.5,
        'reps' => 12,
        'is_warmup' => false,
    ];

    $response = $this->actingAs($this->user)
        ->post(route('sets.store', $this->workoutLine), $data);

    $response->assertRedirect();

    $this->assertDatabaseHas('sets', [
        'workout_line_id' => $this->workoutLine->id,
        'weight' => 50.5,
        'reps' => 12,
        'is_warmup' => false,
    ]);
});

it('can update an existing set', function (): void {
    $data = [
        'weight' => 110,
        'reps' => 8,
        'is_warmup' => true,
    ];

    $response = $this->actingAs($this->user)
        ->patch(route('sets.update', $this->set), $data);

    $response->assertRedirect();

    $this->assertDatabaseHas('sets', [
        'id' => $this->set->id,
        'weight' => 110,
        'reps' => 8,
        'is_warmup' => true,
    ]);
});

it('can destroy a set', function (): void {
    $response = $this->actingAs($this->user)
        ->delete(route('sets.destroy', $this->set));

    $response->assertRedirect();

    $this->assertDatabaseMissing('sets', [
        'id' => $this->set->id,
    ]);
});

// Validation Tests

it('cannot store a set with negative weight', function (): void {
    $data = [
        'weight' => -10,
        'reps' => 10,
    ];

    $response = $this->actingAs($this->user)
        ->post(route('sets.store', $this->workoutLine), $data);

    $response->assertSessionHasErrors(['weight']);
});

it('cannot store a set with negative reps', function (): void {
    $data = [
        'weight' => 100,
        'reps' => -5,
    ];

    $response = $this->actingAs($this->user)
        ->post(route('sets.store', $this->workoutLine), $data);

    $response->assertSessionHasErrors(['reps']);
});

it('cannot update a set with negative weight', function (): void {
    $data = [
        'weight' => -10,
    ];

    $response = $this->actingAs($this->user)
        ->patch(route('sets.update', $this->set), $data);

    $response->assertSessionHasErrors(['weight']);
});

// Authorization Tests

it('cannot store a set for another user\'s workout', function (): void {
    $otherUser = User::factory()->create();

    $data = [
        'weight' => 50,
        'reps' => 10,
    ];

    $response = $this->actingAs($otherUser)
        ->post(route('sets.store', $this->workoutLine), $data);

    $response->assertForbidden();
});

it('cannot update a set for another user\'s workout', function (): void {
    $otherUser = User::factory()->create();

    $data = [
        'weight' => 50,
    ];

    $response = $this->actingAs($otherUser)
        ->patch(route('sets.update', $this->set), $data);

    $response->assertForbidden();
});

it('cannot destroy a set for another user\'s workout', function (): void {
    $otherUser = User::factory()->create();

    $response = $this->actingAs($otherUser)
        ->delete(route('sets.destroy', $this->set));

    $response->assertForbidden();
});

it('cannot store a set if workout is ended', function (): void {
    $this->workout->update(['ended_at' => now()]);

    $data = [
        'weight' => 50,
        'reps' => 10,
    ];

    $response = $this->actingAs($this->user)
        ->post(route('sets.store', $this->workoutLine), $data);

    $response->assertForbidden();
});

it('cannot update a set if workout is ended', function (): void {
    $this->workout->update(['ended_at' => now()]);

    $data = [
        'weight' => 50,
    ];

    $response = $this->actingAs($this->user)
        ->patch(route('sets.update', $this->set), $data);

    $response->assertForbidden();
});

it('cannot destroy a set if workout is ended', function (): void {
    $this->workout->update(['ended_at' => now()]);

    $response = $this->actingAs($this->user)
        ->delete(route('sets.destroy', $this->set));

    $response->assertForbidden();
});
