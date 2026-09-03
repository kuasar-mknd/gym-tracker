<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

test('authenticated user can view an exercise they own', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->get(route('exercises.show', $exercise))
        ->assertOk()
        ->assertInertia(fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
            ->component('Exercises/Show')
            ->has('exercise')
            ->has('progress')
            ->has('history')
        );
});

test('user cannot view an exercise owned by another user', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    // Le nom est pose et non tire au sort : la fabrique produit des mots latins
    // courts, et `unde` figure dans la page 404 — l'assertion tombait le jour ou
    // le tirage sortait un mot que la page contient deja.
    $exercise = Exercise::factory()->create([
        'user_id' => $otherUser->id,
        'name' => 'Rowing barre prise supination',
    ]);

    actingAs($user)
        ->get(route('exercises.show', $exercise))
        ->assertNotFound()
        ->assertDontSee($exercise->name);
});

test('authenticated user can store a new exercise with valid data', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('exercises.store'), [
            'name' => 'Deadlift',
            'type' => 'strength',
            'category' => 'Dos',
        ])
        ->assertRedirect();

    assertDatabaseHas('exercises', [
        'name' => 'Deadlift',
        'type' => 'strength',
        'category' => 'Dos',
        'user_id' => $user->id,
    ]);
});

test('storing an exercise fails with validation errors', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('exercises.store'), [
            'name' => '', // Invalid: required
            'type' => 'invalid_type', // Invalid: must be strength, cardio, or timed
            'category' => 'Dos',
        ])
        ->assertSessionHasErrors(['name', 'type']);
});

/*
 * La creation rapide depuis la page de seance.
 *
 * `Templates/Create.vue` et `Templates/Edit.vue` posent `X-Quick-Create` pour
 * recevoir l'exercice en JSON plutot qu'une redirection. Cette branche
 * n'etait couverte par aucun test, et le controleur lisait `header()` comme
 * un booleen : la PRESENCE de l'en-tete etait ce qui comptait, mais une
 * valeur vide se lisait comme une absence.
 */
test('la création rapide répond en JSON quand l’en-tête est posé', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('exercises.store'), [
            'name' => 'Rowing',
            'type' => 'strength',
            'category' => 'Dos',
        ], ['X-Quick-Create' => 'true'])
        ->assertCreated()
        ->assertJsonPath('exercise.name', 'Rowing');
});

test('la création rapide répond en JSON même si l’en-tête est vide', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('exercises.store'), [
            'name' => 'Tirage',
            'type' => 'strength',
            'category' => 'Dos',
        ], ['X-Quick-Create' => ''])
        ->assertCreated()
        ->assertJsonPath('exercise.name', 'Tirage');
});

test('authenticated user can update an exercise they own with valid data', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create([
        'user_id' => $user->id,
        'name' => 'Old Name',
        'type' => 'strength',
    ]);

    actingAs($user)
        ->put(route('exercises.update', $exercise), [
            'name' => 'New Name',
            'type' => 'cardio',
            'category' => 'Cardio',
        ])
        ->assertRedirect();

    assertDatabaseHas('exercises', [
        'id' => $exercise->id,
        'name' => 'New Name',
        'type' => 'cardio',
    ]);
});

test('updating an exercise fails with validation errors', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->put(route('exercises.update', $exercise), [
            'name' => '', // Invalid: required
            'type' => 'not_allowed_type', // Invalid
        ])
        ->assertSessionHasErrors(['name', 'type']);
});

test('user cannot update an exercise owned by another user', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $exercise = Exercise::factory()->create([
        'user_id' => $otherUser->id,
        'name' => 'Other Users Exercise',
    ]);

    actingAs($user)
        ->put(route('exercises.update', $exercise), [
            'name' => 'Updated Name',
            'type' => 'strength',
            'category' => 'Dos',
        ])
        ->assertNotFound();

    assertDatabaseHas('exercises', [
        'id' => $exercise->id,
        'name' => 'Other Users Exercise',
    ]);
});

test('user cannot update a system exercise', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create([
        'user_id' => null, // System exercise
        'name' => 'System Exercise',
    ]);

    actingAs($user)
        ->put(route('exercises.update', $exercise), [
            'name' => 'Modified System Exercise',
            'type' => 'strength',
            'category' => 'Dos',
        ])
        ->assertForbidden();
});

test('authenticated user can destroy their own exercise', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->delete(route('exercises.destroy', $exercise))
        ->assertRedirect();

    assertDatabaseMissing('exercises', [
        'id' => $exercise->id,
    ]);
});

test('user cannot destroy an exercise that is linked to a workout line', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $user->id]);
    $workout = Workout::factory()->create(['user_id' => $user->id]);

    // Create a workout line linked to this exercise
    WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    actingAs($user)
        ->delete(route('exercises.destroy', $exercise))
        ->assertRedirect()
        ->assertSessionHasErrors(['exercise']);

    assertDatabaseHas('exercises', [
        'id' => $exercise->id,
    ]);
});

test('user cannot destroy an exercise owned by another user', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->delete(route('exercises.destroy', $exercise))
        ->assertNotFound();

    assertDatabaseHas('exercises', [
        'id' => $exercise->id,
    ]);
});
