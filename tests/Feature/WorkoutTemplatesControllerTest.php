<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the templates index page', function (): void {
    $user = User::factory()->create();
    WorkoutTemplate::factory()->count(3)->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->get(route('templates.index'));

    $response->assertStatus(200)
        ->assertInertia(fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
            ->component('Workouts/Templates/Index')
            ->has('templates', 3)
        );
});

it('renders the template creation page', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('templates.create'));

    $response->assertStatus(200)
        ->assertInertia(fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
            ->component('Workouts/Templates/Create')
            ->has('exercises')
        );
});

it('stores a new workout template', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();

    $response = $this->actingAs($user)->post(route('templates.store'), [
        'name' => 'My New Template',
        'description' => 'Template Description',
        'exercises' => [
            [
                'id' => $exercise->id,
                'sets' => [
                    ['reps' => 10, 'weight' => 50, 'is_warmup' => false],
                ],
            ],
        ],
    ]);

    $response->assertRedirect(route('templates.index'));
    $this->assertDatabaseHas('workout_templates', [
        'name' => 'My New Template',
        'user_id' => $user->id,
    ]);
});

it('fails to store template with invalid data (422)', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('templates.store'), [
        'name' => '', // Required field
        'exercises' => [
            [
                'id' => 9999, // Invalid exercise ID
            ],
        ],
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'exercises.0.id']);
});

it('executes a workout template', function (): void {
    $user = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->post(route('templates.execute', $template));

    $response->assertRedirect();
    $workout = Workout::where('user_id', $user->id)->first();
    expect($workout)->not->toBeNull();
    $response->assertRedirect(route('workouts.show', $workout));
});

it('forbids executing another users template (403)', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $user1->id]);

    $response = $this->actingAs($user2)->post(route('templates.execute', $template));

    $response->assertStatus(403);
});

it('saves a workout as a new template', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'name' => 'My Session',
    ]);

    $response = $this->actingAs($user)->post(route('templates.save-from-workout', $workout));

    $response->assertRedirect(route('templates.index'));
    $response->assertSessionHas('success', 'Modèle enregistré avec succès !');

    $this->assertDatabaseHas('workout_templates', [
        'name' => 'My Session (Modèle)',
        'user_id' => $user->id,
    ]);
});

it('forbids saving another users workout as template (403)', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user1->id]);

    $response = $this->actingAs($user2)->post(route('templates.save-from-workout', $workout));

    $response->assertStatus(403);
});

it('deletes a workout template', function (): void {
    $user = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->from(route('templates.index'))->delete(route('templates.destroy', $template));

    $response->assertRedirect(route('templates.index'));
    $this->assertDatabaseMissing('workout_templates', ['id' => $template->id]);
});

it('forbids deleting another users template (403)', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $user1->id]);

    $response = $this->actingAs($user2)->delete(route('templates.destroy', $template));

    $response->assertStatus(403);
    $this->assertDatabaseHas('workout_templates', ['id' => $template->id]);
});

it('redirects unauthenticated users', function (): void {
    $response = $this->get(route('templates.index'));
    $response->assertRedirect(route('login'));
});
