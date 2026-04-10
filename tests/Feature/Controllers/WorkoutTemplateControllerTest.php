<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

describe('WorkoutTemplateController', function (): void {
    describe('index', function (): void {
        it('allows a user to view their templates', function (): void {
            $user = User::factory()->create();
            WorkoutTemplate::factory(3)->create(['user_id' => $user->id]);

            $response = $this->actingAs($user)
                ->get(route('templates.index'));

            $response->assertOk()
                ->assertInertia(fn (Assert $page): \Inertia\Testing\AssertableInertia => $page->component('Workouts/Templates/Index')
                    ->has('templates')
                );
        });

        it('prevents a guest from viewing templates', function (): void {
            $response = $this->get(route('templates.index'));

            $response->assertRedirect(route('login'));
        });
    });

    describe('create', function (): void {
        it('allows a user to view the create template form', function (): void {
            $user = User::factory()->create();

            $response = $this->actingAs($user)
                ->get(route('templates.create'));

            $response->assertOk()
                ->assertInertia(fn (Assert $page): \Inertia\Testing\AssertableInertia => $page->component('Workouts/Templates/Create')
                    ->has('exercises')
                );
        });

        it('prevents a guest from viewing the create template form', function (): void {
            $response = $this->get(route('templates.create'));

            $response->assertRedirect(route('login'));
        });
    });

    describe('store', function (): void {
        it('allows a user to create a template', function (): void {
            $user = User::factory()->create();
            $exercise = Exercise::factory()->create();

            $payload = [
                'name' => 'My New Template',
                'description' => 'A great workout',
                'exercises' => [
                    [
                        'id' => $exercise->id,
                        'sets' => [
                            ['reps' => 10, 'weight' => 50, 'is_warmup' => false],
                        ],
                    ],
                ],
            ];

            $response = $this->actingAs($user)
                ->post(route('templates.store'), $payload);

            $response->assertRedirect(route('templates.index'));

            $this->assertDatabaseHas('workout_templates', [
                'user_id' => $user->id,
                'name' => 'My New Template',
                'description' => 'A great workout',
            ]);
        });

        it('returns validation errors for invalid data', function (): void {
            $user = User::factory()->create();

            $response = $this->actingAs($user)
                ->postJson(route('templates.store'), [
                    'name' => '', // Required
                ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['name']);
        });
    });

    describe('execute', function (): void {
        it('allows a user to execute their template', function (): void {
            $user = User::factory()->create();
            $template = WorkoutTemplate::factory()->create(['user_id' => $user->id]);

            $response = $this->actingAs($user)
                ->post(route('templates.execute', $template));

            $workout = Workout::where('user_id', $user->id)->first();

            $response->assertRedirect(route('workouts.show', $workout));

            $this->assertDatabaseHas('workouts', [
                'user_id' => $user->id,
            ]);
        });

        it('prevents a user from executing someone else\'s template', function (): void {
            $user = User::factory()->create();
            $otherUser = User::factory()->create();
            $template = WorkoutTemplate::factory()->create(['user_id' => $otherUser->id]);

            $response = $this->actingAs($user)
                ->post(route('templates.execute', $template));

            $response->assertForbidden();
        });
    });

    describe('saveFromWorkout', function (): void {
        it('allows a user to save a template from a workout', function (): void {
            $user = User::factory()->create();
            $workout = Workout::factory()->create(['user_id' => $user->id, 'name' => 'Morning Session']);

            $response = $this->actingAs($user)
                ->post(route('templates.save-from-workout', $workout));

            $response->assertRedirect(route('templates.index'))
                ->assertSessionHas('success', 'Modèle enregistré avec succès !');

            $this->assertDatabaseHas('workout_templates', [
                'user_id' => $user->id,
                'name' => 'Morning Session (Modèle)',
            ]);
        });

        it('prevents a user from saving a template from someone else\'s workout', function (): void {
            $user = User::factory()->create();
            $otherUser = User::factory()->create();
            $workout = Workout::factory()->create(['user_id' => $otherUser->id]);

            $response = $this->actingAs($user)
                ->post(route('templates.save-from-workout', $workout));

            $response->assertForbidden();
        });
    });

    describe('destroy', function (): void {
        it('allows a user to delete their template', function (): void {
            $user = User::factory()->create();
            $template = WorkoutTemplate::factory()->create(['user_id' => $user->id]);

            $response = $this->actingAs($user)
                ->from(route('templates.index'))
                ->delete(route('templates.destroy', $template));

            $response->assertRedirect(route('templates.index'));

            $this->assertDatabaseMissing('workout_templates', [
                'id' => $template->id,
            ]);
        });

        it('prevents a user from deleting someone else\'s template', function (): void {
            $user = User::factory()->create();
            $otherUser = User::factory()->create();
            $template = WorkoutTemplate::factory()->create(['user_id' => $otherUser->id]);

            $response = $this->actingAs($user)
                ->delete(route('templates.destroy', $template));

            $response->assertForbidden();
        });
    });

    describe('unimplemented routes', function (): void {
        it('returns 404 for show', function (): void {
            $user = User::factory()->create();
            $template = WorkoutTemplate::factory()->create(['user_id' => $user->id]);

            $response = $this->actingAs($user)
                ->get(route('templates.show', $template));

            $response->assertNotFound();
        });

        it('returns 404 for edit', function (): void {
            $user = User::factory()->create();
            $template = WorkoutTemplate::factory()->create(['user_id' => $user->id]);

            $response = $this->actingAs($user)
                ->get(route('templates.edit', $template));

            $response->assertNotFound();
        });

        it('returns 404 for update', function (): void {
            $user = User::factory()->create();
            $template = WorkoutTemplate::factory()->create(['user_id' => $user->id]);

            $response = $this->actingAs($user)
                ->patch(route('templates.update', $template));

            $response->assertNotFound();
        });
    });
});
