<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

describe('WorkoutController', function (): void {
    describe('index', function (): void {
        it('allows a user to view their workouts', function (): void {
            $user = User::factory()->create();
            Workout::factory(3)->create(['user_id' => $user->id]);

            $response = $this->actingAs($user)
                ->get(route('workouts.index'));

            $response->assertOk()
                ->assertInertia(fn (Assert $page): \Inertia\Testing\AssertableInertia => $page->component('Workouts/Index'));
        });

        it('prevents a guest from viewing workouts', function (): void {
            $response = $this->get(route('workouts.index'));

            $response->assertRedirect(route('login'));
        });
    });

    describe('show', function (): void {
        it('allows a user to view their own workout', function (): void {
            $user = User::factory()->create();
            $workout = Workout::factory()->create(['user_id' => $user->id]);

            $response = $this->actingAs($user)
                ->get(route('workouts.show', $workout));

            $response->assertOk()
                ->assertInertia(fn (Assert $page): \Inertia\Testing\AssertableInertia => $page->component('Workouts/Show'));
        });

        it('prevents a user from viewing another users workout', function (): void {
            $user = User::factory()->create();
            $otherUser = User::factory()->create();
            $workout = Workout::factory()->create(['user_id' => $otherUser->id]);

            $response = $this->actingAs($user)
                ->get(route('workouts.show', $workout));

            $response->assertForbidden();
        });
    });

    describe('store', function (): void {
        it('allows a user to create a new workout', function (): void {
            $user = User::factory()->create();

            $response = $this->actingAs($user)
                ->post(route('workouts.store'));

            $workout = Workout::where('user_id', $user->id)->first();

            $response->assertRedirect(route('workouts.show', $workout));
            $this->assertDatabaseHas('workouts', [
                'user_id' => $user->id,
                'ended_at' => null,
            ]);
        });

        it('redirects to the active workout if one already exists', function (): void {
            $user = User::factory()->create();
            $activeWorkout = Workout::factory()->create([
                'user_id' => $user->id,
                'ended_at' => null,
            ]);

            $response = $this->actingAs($user)
                ->post(route('workouts.store'));

            $response->assertRedirect(route('workouts.show', $activeWorkout));

            // Should not create a new one
            $this->assertDatabaseCount('workouts', 1);
        });
    });

    describe('update', function (): void {
        it('allows a user to update their workout', function (): void {
            $user = User::factory()->create();
            $workout = Workout::factory()->create(['user_id' => $user->id]);

            $response = $this->actingAs($user)
                ->patch(route('workouts.update', $workout), [
                    'name' => 'Updated Workout Name',
                    'notes' => 'Some notes here',
                ]);

            $response->assertRedirect();
            $this->assertDatabaseHas('workouts', [
                'id' => $workout->id,
                'name' => 'Updated Workout Name',
                'notes' => 'Some notes here',
            ]);
        });

        it('redirects to dashboard when workout is finished', function (): void {
            $user = User::factory()->create();
            $workout = Workout::factory()->create(['user_id' => $user->id]);

            $response = $this->actingAs($user)
                ->patch(route('workouts.update', $workout), [
                    'is_finished' => true,
                ]);

            $response->assertRedirect(route('dashboard'));
            $this->assertDatabaseHas('workouts', [
                'id' => $workout->id,
            ]);
            $this->assertNotNull($workout->refresh()->ended_at);
        });

        it('returns validation errors for invalid data', function (): void {
            $user = User::factory()->create();
            $workout = Workout::factory()->create(['user_id' => $user->id]);

            $response = $this->actingAs($user)
                ->patch(route('workouts.update', $workout), [
                    'name' => str_repeat('a', 256), // Max 255
                    'is_finished' => 'not a boolean',
                ]);

            $response->assertSessionHasErrors(['name', 'is_finished']);
        });

        it('prevents a user from updating another users workout', function (): void {
            $user = User::factory()->create();
            $otherUser = User::factory()->create();
            $workout = Workout::factory()->create(['user_id' => $otherUser->id]);

            $response = $this->actingAs($user)
                ->patch(route('workouts.update', $workout), [
                    'name' => 'Should Not Update',
                ]);

            $response->assertForbidden();
            $this->assertDatabaseMissing('workouts', [
                'id' => $workout->id,
                'name' => 'Should Not Update',
            ]);
        });
    });

    describe('destroy', function (): void {
        it('allows a user to delete their workout', function (): void {
            $user = User::factory()->create();
            $workout = Workout::factory()->create(['user_id' => $user->id]);

            $response = $this->actingAs($user)
                ->delete(route('workouts.destroy', $workout));

            $response->assertRedirect(route('workouts.index'));
            $this->assertDatabaseMissing('workouts', [
                'id' => $workout->id,
            ]);
        });

        it('prevents a user from deleting another users workout', function (): void {
            $user = User::factory()->create();
            $otherUser = User::factory()->create();
            $workout = Workout::factory()->create(['user_id' => $otherUser->id]);

            $response = $this->actingAs($user)
                ->delete(route('workouts.destroy', $workout));

            $response->assertForbidden();
            $this->assertDatabaseHas('workouts', [
                'id' => $workout->id,
            ]);
        });
    });
});
