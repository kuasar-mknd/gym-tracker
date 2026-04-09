<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

describe('ExerciseController', function (): void {
    describe('index', function (): void {
        it('allows a user to view their exercises', function (): void {
            $user = User::factory()->create();
            Exercise::factory(3)->create(['user_id' => $user->id]);

            $response = $this->actingAs($user)
                ->get(route('exercises.index'));

            $response->assertOk()
                ->assertInertia(fn (Assert $page): \Inertia\Testing\AssertableInertia => $page->component('Exercises/Index'));
        });

        it('prevents a guest from viewing exercises', function (): void {
            $response = $this->get(route('exercises.index'));

            $response->assertRedirect(route('login'));
        });
    });

    describe('show', function (): void {
        it('allows a user to view their own exercise', function (): void {
            $user = User::factory()->create();
            $exercise = Exercise::factory()->create(['user_id' => $user->id]);

            $response = $this->actingAs($user)
                ->get(route('exercises.show', $exercise));

            $response->assertOk()
                ->assertInertia(fn (Assert $page): \Inertia\Testing\AssertableInertia => $page->component('Exercises/Show'));
        });

        it('allows a user to view a global exercise', function (): void {
            $user = User::factory()->create();
            $exercise = Exercise::factory()->create(['user_id' => null]);

            $response = $this->actingAs($user)
                ->get(route('exercises.show', $exercise));

            $response->assertOk()
                ->assertInertia(fn (Assert $page): \Inertia\Testing\AssertableInertia => $page->component('Exercises/Show'));
        });

        it('prevents a user from viewing another users exercise', function (): void {
            $user = User::factory()->create();
            $otherUser = User::factory()->create();
            $exercise = Exercise::factory()->create(['user_id' => $otherUser->id]);

            $response = $this->actingAs($user)
                ->get(route('exercises.show', $exercise));

            $response->assertForbidden();
        });
    });

    describe('store', function (): void {
        it('allows a user to create an exercise via standard request', function (): void {
            $user = User::factory()->create();

            $data = [
                'name' => 'New Bench Press',
                'type' => 'strength',
                'category' => \App\Enums\ExerciseCategory::Pectoraux->value,
            ];

            $response = $this->actingAs($user)
                ->post(route('exercises.store'), $data);

            $response->assertRedirect()
                ->assertSessionHas('success', 'Exercice créé avec succès');

            $this->assertDatabaseHas('exercises', [
                'name' => 'New Bench Press',
                'user_id' => $user->id,
            ]);
        });

        it('allows a user to create an exercise via json request', function (): void {
            $user = User::factory()->create();

            $data = [
                'name' => 'Quick Squat',
                'type' => 'strength',
                'category' => \App\Enums\ExerciseCategory::Jambes->value,
            ];

            $response = $this->actingAs($user)
                ->postJson(route('exercises.store'), $data);

            $response->assertCreated()
                ->assertJsonPath('exercise.name', 'Quick Squat');

            $this->assertDatabaseHas('exercises', [
                'name' => 'Quick Squat',
                'user_id' => $user->id,
            ]);
        });

        it('returns validation error if name is missing', function (): void {
            $user = User::factory()->create();

            $data = [
                'type' => 'strength',
            ];

            $response = $this->actingAs($user)
                ->post(route('exercises.store'), $data);

            $response->assertSessionHasErrors(['name']);
        });

        it('returns validation error if type is invalid', function (): void {
            $user = User::factory()->create();

            $data = [
                'name' => 'Bad Type Exercise',
                'type' => 'invalid_type',
            ];

            $response = $this->actingAs($user)
                ->post(route('exercises.store'), $data);

            $response->assertSessionHasErrors(['type']);
        });

        it('returns validation error if name is not unique for user', function (): void {
            $user = User::factory()->create();
            Exercise::factory()->create(['user_id' => $user->id, 'name' => 'Duplicate']);

            $data = [
                'name' => 'Duplicate',
                'type' => 'strength',
            ];

            $response = $this->actingAs($user)
                ->post(route('exercises.store'), $data);

            $response->assertSessionHasErrors(['name']);
        });
    });

    describe('update', function (): void {
        it('allows a user to update their own exercise', function (): void {
            $user = User::factory()->create();
            $exercise = Exercise::factory()->create(['user_id' => $user->id, 'name' => 'Old Name']);

            $data = [
                'name' => 'Updated Name',
                'type' => 'strength',
            ];

            $response = $this->actingAs($user)
                ->put(route('exercises.update', $exercise), $data);

            $response->assertRedirect();
            $this->assertDatabaseHas('exercises', [
                'id' => $exercise->id,
                'name' => 'Updated Name',
            ]);
        });

        it('prevents a user from updating another users exercise', function (): void {
            $user = User::factory()->create();
            $otherUser = User::factory()->create();
            $exercise = Exercise::factory()->create(['user_id' => $otherUser->id, 'name' => 'Old Name']);

            $data = [
                'name' => 'Hacked Name',
                'type' => 'strength',
            ];

            $response = $this->actingAs($user)
                ->put(route('exercises.update', $exercise), $data);

            $response->assertForbidden();
            $this->assertDatabaseMissing('exercises', [
                'id' => $exercise->id,
                'name' => 'Hacked Name',
            ]);
        });

        it('returns validation error on update if name is not unique for user', function (): void {
            $user = User::factory()->create();
            $exerciseToKeep = Exercise::factory()->create(['user_id' => $user->id, 'name' => 'Existing Name']);
            $exerciseToUpdate = Exercise::factory()->create(['user_id' => $user->id, 'name' => 'Old Name']);

            $data = [
                'name' => 'Existing Name',
                'type' => 'strength',
            ];

            $response = $this->actingAs($user)
                ->put(route('exercises.update', $exerciseToUpdate), $data);

            $response->assertSessionHasErrors(['name']);
        });
    });

    describe('destroy', function (): void {
        it('allows a user to delete their own exercise', function (): void {
            $user = User::factory()->create();
            $exercise = Exercise::factory()->create(['user_id' => $user->id]);

            $response = $this->actingAs($user)
                ->delete(route('exercises.destroy', $exercise));

            $response->assertRedirect();
            $this->assertDatabaseMissing('exercises', [
                'id' => $exercise->id,
            ]);
        });

        it('prevents a user from deleting another users exercise', function (): void {
            $user = User::factory()->create();
            $otherUser = User::factory()->create();
            $exercise = Exercise::factory()->create(['user_id' => $otherUser->id]);

            $response = $this->actingAs($user)
                ->delete(route('exercises.destroy', $exercise));

            $response->assertForbidden();
            $this->assertDatabaseHas('exercises', [
                'id' => $exercise->id,
            ]);
        });

        it('prevents deletion if exercise is linked to a workout line', function (): void {
            $user = User::factory()->create();
            $exercise = Exercise::factory()->create(['user_id' => $user->id]);
            WorkoutLine::factory()->create(['exercise_id' => $exercise->id]);

            $response = $this->actingAs($user)
                ->delete(route('exercises.destroy', $exercise));

            $response->assertRedirect()
                ->assertSessionHasErrors(['exercise']);

            $this->assertDatabaseHas('exercises', [
                'id' => $exercise->id,
            ]);
        });
    });
});
