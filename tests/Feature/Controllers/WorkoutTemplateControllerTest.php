<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutTemplate;
use Inertia\Testing\AssertableInertia as Assert;

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

            $response->assertNotFound();

            $this->assertDatabaseMissing('workouts', ['user_id' => $user->id]);
        });

        /*
         * « Démarrer » renvoyait déjà vers la séance ouverte ; démarrer un
         * modèle en ouvrait une deuxième, que le bandeau cachait derrière la
         * plus récente (#1958).
         */
        it('renvoie vers la séance ouverte, avec un message, sans en ouvrir une deuxième', function (): void {
            $user = User::factory()->create();
            $seanceOuverte = Workout::factory()->create([
                'user_id' => $user->id,
                'started_at' => now()->subMinutes(20),
                'ended_at' => null,
            ]);
            $exercise = Exercise::factory()->create(['user_id' => $user->id]);
            $template = WorkoutTemplate::factory()->create(['user_id' => $user->id, 'name' => 'Jambes']);
            $template->workoutTemplateLines()->create(['exercise_id' => $exercise->id, 'order' => 0]);

            $this->actingAs($user)
                ->post(route('templates.execute', $template))
                ->assertRedirect(route('workouts.show', $seanceOuverte))
                ->assertSessionHas('error', fn (string $message): bool => str_contains($message, '« Jambes »')
                    && str_contains($message, 'une séance est déjà en cours'));

            expect(Workout::query()->where('user_id', $user->id)->whereNull('ended_at')->pluck('id')->all())
                ->toBe([$seanceOuverte->id])
                ->and($seanceOuverte->workoutLines()->count())->toBe(0);
        });

        it('affiche le message sur la séance ouverte', function (): void {
            $user = User::factory()->create();
            Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
            $template = WorkoutTemplate::factory()->create(['user_id' => $user->id, 'name' => 'Dos']);

            $this->actingAs($user)
                ->followingRedirects()
                ->post(route('templates.execute', $template))
                ->assertOk()
                ->assertInertia(fn (Assert $page): Assert => $page
                    ->component('Workouts/Show')
                    ->where('flash.error', fn (string $message): bool => str_contains($message, '« Dos »')));
        });

        it('démarre le modèle quand la dernière séance est terminée', function (): void {
            $user = User::factory()->create();
            Workout::factory()->create([
                'user_id' => $user->id,
                'started_at' => now()->subHours(3),
                'ended_at' => now()->subHours(2),
            ]);
            $template = WorkoutTemplate::factory()->create(['user_id' => $user->id, 'name' => 'Pectoraux']);

            $this->actingAs($user)
                ->post(route('templates.execute', $template))
                ->assertSessionMissing('error');

            $nouvelle = Workout::query()->where('user_id', $user->id)->whereNull('ended_at')->sole();

            expect($nouvelle->name)->toBe('Pectoraux');
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

            $response->assertNotFound();

            $this->assertDatabaseMissing('workout_templates', ['user_id' => $user->id]);
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

            $response->assertNotFound();

            $this->assertDatabaseHas('workout_templates', ['id' => $template->id]);
        });
    });

    describe('unimplemented routes', function (): void {
        /*
         * Only show is left here. There is no Templates/Show page and nothing
         * links to the route, so its 404 is honest.
         *
         * edit and update used to sit alongside it, and their 404 was not: the
         * page, the update action, its request and the policy ability all
         * existed and were wired to the API controller, while the web methods
         * aborted and nothing in the interface linked to templates.edit. They
         * are covered for real in WorkoutTemplatesControllerTest now.
         */
        it('returns 404 for show', function (): void {
            $user = User::factory()->create();
            $template = WorkoutTemplate::factory()->create(['user_id' => $user->id]);

            $response = $this->actingAs($user)
                ->get(route('templates.show', $template));

            $response->assertNotFound();
        });
    });
});
