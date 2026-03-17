<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('renders the index page for authenticated user (Happy Path)', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->get(route('workouts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('Workouts/Index')
            ->has('workouts')
        );
});

it('renders the show page for a specific workout (Happy Path)', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->get(route('workouts.show', $workout))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('Workouts/Show')
            ->has('workout')
        );
});

it('forbids viewing another users workout (403 Forbidden)', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->get(route('workouts.show', $workout))
        ->assertForbidden();
});

it('creates a new workout (Happy Path)', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('workouts.store'))
        ->assertRedirect(); // Should redirect to workouts.show
});

it('updates an existing workout (Happy Path)', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->patch(route('workouts.update', $workout), [
            'name' => 'Updated Workout Name',
        ])
        ->assertRedirect(); // back()
});

it('fails to update an existing workout with invalid data (422 Unprocessable)', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->patch(route('workouts.update', $workout), [
            'name' => str_repeat('a', 256), // Max length is 255
            'started_at' => 'not-a-date',
            'notes' => str_repeat('b', 1001), // Max length is 1000
            'is_finished' => 'not-a-boolean',
        ])
        ->assertSessionHasErrors(['name', 'started_at', 'notes', 'is_finished']);
});

it('forbids updating another users workout (403 Forbidden)', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->patch(route('workouts.update', $workout), [
            'name' => 'Sneaky Update',
        ])
        ->assertForbidden();
});

it('deletes a workout (Happy Path)', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->delete(route('workouts.destroy', $workout))
        ->assertRedirect(route('workouts.index'));
});

it('forbids deleting another users workout (403 Forbidden)', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->delete(route('workouts.destroy', $workout))
        ->assertForbidden();
});
