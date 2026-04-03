<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutLine;
use App\Enums\ExerciseCategory;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('ExerciseController', function () {
    describe('index', function () {
        it('requires authentication', function () {
            $this->get(route('exercises.index'))
                ->assertRedirect(route('login'));
        });

        it('displays the exercise index page with props', function () {
            $user = User::factory()->create();
            Exercise::factory()->create(['user_id' => $user->id, 'name' => 'My Squat']);

            $this->actingAs($user)
                ->get(route('exercises.index'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Exercises/Index')
                    ->has('exercises')
                    ->has('categories')
                    ->has('types')
                );
        });
    });

    describe('show', function () {
        it('requires authentication', function () {
            $exercise = Exercise::factory()->create(['user_id' => User::factory()->create()->id]);
            $this->get(route('exercises.show', $exercise))
                ->assertRedirect(route('login'));
        });

        it('displays the exercise show page for an owned exercise', function () {
            $user = User::factory()->create();
            $exercise = Exercise::factory()->create(['user_id' => $user->id]);

            $this->actingAs($user)
                ->get(route('exercises.show', $exercise))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Exercises/Show')
                    ->has('exercise')
                    ->has('progress')
                    ->has('history')
                );
        });

        it('displays the exercise show page for a global exercise', function () {
            $user = User::factory()->create();
            $exercise = Exercise::factory()->create(['user_id' => null]); // global

            $this->actingAs($user)
                ->get(route('exercises.show', $exercise))
                ->assertOk();
        });

        it('forbids viewing another users exercise', function () {
            $user = User::factory()->create();
            $otherUser = User::factory()->create();
            $exercise = Exercise::factory()->create(['user_id' => $otherUser->id]);

            $this->actingAs($user)
                ->get(route('exercises.show', $exercise))
                ->assertForbidden();
        });
    });

    describe('store', function () {
        it('requires authentication', function () {
            $this->post(route('exercises.store'), [])
                ->assertRedirect(route('login'));
        });

        it('creates an exercise and redirects', function () {
            $user = User::factory()->create();
            $data = [
                'name' => 'New Exercise',
                'type' => 'strength',
                'category' => ExerciseCategory::Jambes->value,
            ];

            $this->actingAs($user)
                ->post(route('exercises.store'), $data)
                ->assertRedirect()
                ->assertSessionHas('success');

            $this->assertDatabaseHas('exercises', [
                'user_id' => $user->id,
                'name' => 'New Exercise',
                'type' => 'strength',
            ]);
        });

        it('creates an exercise and returns json when requested', function () {
            $user = User::factory()->create();
            $data = [
                'name' => 'Quick Exercise',
                'type' => 'cardio',
                'category' => ExerciseCategory::Cardio->value,
            ];

            $this->actingAs($user)
                ->postJson(route('exercises.store'), $data, ['X-Quick-Create' => '1'])
                ->assertCreated()
                ->assertJsonStructure(['exercise' => ['id', 'name']]);

            $this->assertDatabaseHas('exercises', [
                'user_id' => $user->id,
                'name' => 'Quick Exercise',
            ]);
        });

        it('validates required fields', function () {
            $user = User::factory()->create();

            $this->actingAs($user)
                ->post(route('exercises.store'), [])
                ->assertInvalid(['name', 'type']);
        });

        it('validates unique name per user', function () {
            $user = User::factory()->create();
            Exercise::factory()->create(['user_id' => $user->id, 'name' => 'Duplicate Squat']);

            $this->actingAs($user)
                ->post(route('exercises.store'), [
                    'name' => 'Duplicate Squat',
                    'type' => 'strength',
                ])
                ->assertInvalid(['name']);
        });

        it('allows same name for different users', function () {
            $otherUser = User::factory()->create();
            Exercise::factory()->create(['user_id' => $otherUser->id, 'name' => 'Shared Name']);

            $user = User::factory()->create();

            $this->actingAs($user)
                ->post(route('exercises.store'), [
                    'name' => 'Shared Name',
                    'type' => 'strength',
                    'category' => ExerciseCategory::Bras->value,
                ])
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        });
    });

    describe('update', function () {
        it('requires authentication', function () {
            $exercise = Exercise::factory()->create();
            $this->put(route('exercises.update', $exercise), [])
                ->assertRedirect(route('login'));
        });

        it('updates an exercise', function () {
            Cache::spy();
            $user = User::factory()->create();
            $exercise = Exercise::factory()->create(['user_id' => $user->id, 'name' => 'Old Name']);

            $this->actingAs($user)
                ->put(route('exercises.update', $exercise), [
                    'name' => 'Updated Name',
                    'type' => 'strength',
                ])
                ->assertRedirect();

            $this->assertDatabaseHas('exercises', [
                'id' => $exercise->id,
                'name' => 'Updated Name',
            ]);

            Cache::shouldHaveReceived('forget')->atLeast()->once();
        });

        it('forbids updating another users exercise', function () {
            $user = User::factory()->create();
            $otherUser = User::factory()->create();
            $exercise = Exercise::factory()->create(['user_id' => $otherUser->id]);

            $this->actingAs($user)
                ->put(route('exercises.update', $exercise), [
                    'name' => 'Hacked Name',
                ])
                ->assertForbidden();
        });

        it('validates unique name on update ignoring self', function () {
            $user = User::factory()->create();
            $exercise = Exercise::factory()->create(['user_id' => $user->id, 'name' => 'My Squat']);

            $this->actingAs($user)
                ->put(route('exercises.update', $exercise), [
                    'name' => 'My Squat',
                    'type' => 'strength',
                ])
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        });
    });

    describe('destroy', function () {
        it('requires authentication', function () {
            $exercise = Exercise::factory()->create();
            $this->delete(route('exercises.destroy', $exercise))
                ->assertRedirect(route('login'));
        });

        it('deletes an exercise', function () {
            Cache::spy();
            $user = User::factory()->create();
            $exercise = Exercise::factory()->create(['user_id' => $user->id]);

            $this->actingAs($user)
                ->delete(route('exercises.destroy', $exercise))
                ->assertRedirect();

            $this->assertDatabaseMissing('exercises', ['id' => $exercise->id]);
            Cache::shouldHaveReceived('forget')->atLeast()->once();
        });

        it('prevents deletion if workout lines exist', function () {
            $user = User::factory()->create();
            $exercise = Exercise::factory()->create(['user_id' => $user->id]);
            WorkoutLine::factory()->create(['exercise_id' => $exercise->id]);

            $this->actingAs($user)
                ->delete(route('exercises.destroy', $exercise))
                ->assertRedirect()
                ->assertSessionHasErrors(['exercise']);

            $this->assertDatabaseHas('exercises', ['id' => $exercise->id]);
        });

        it('forbids deleting another users exercise', function () {
            $user = User::factory()->create();
            $otherUser = User::factory()->create();
            $exercise = Exercise::factory()->create(['user_id' => $otherUser->id]);

            $this->actingAs($user)
                ->delete(route('exercises.destroy', $exercise))
                ->assertForbidden();
        });
    });
});
