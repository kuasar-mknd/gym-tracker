<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(RefreshDatabase::class);

test('authenticated user can list exercises', function (): void {
    $user = User::factory()->create();
    $systemExercise = Exercise::factory()->create(['user_id' => null]);
    $userExercise = Exercise::factory()->create(['user_id' => $user->id]);
    $otherUserExercise = Exercise::factory()->create(['user_id' => User::factory()->create()->id]);

    $response = actingAs($user)
        ->getJson(route('api.v1.exercises.index'));

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonFragment(['id' => $systemExercise->id])
        ->assertJsonFragment(['id' => $userExercise->id])
        ->assertJsonMissing(['id' => $otherUserExercise->id]);
});

test('unauthenticated user cannot list exercises', function (): void {
    getJson(route('api.v1.exercises.index'))
        ->assertUnauthorized();
});

test('authenticated user can create an exercise', function (): void {
    $user = User::factory()->create();
    $data = [
        'name' => 'My New Exercise',
        'type' => 'strength',
        'category' => 'Legs',
    ];

    $response = actingAs($user)
        ->postJson(route('api.v1.exercises.store'), $data);

    $response->assertCreated()
        ->assertJsonFragment(['name' => 'My New Exercise']);

    $this->assertDatabaseHas('exercises', [
        'name' => 'My New Exercise',
        'user_id' => $user->id,
    ]);
});

test('validation fails when creating exercise with missing name', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson(route('api.v1.exercises.store'), [
            'type' => 'strength',
        ])
        ->assertJsonValidationErrors(['name']);
});

test('validation fails when creating exercise with invalid type', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson(route('api.v1.exercises.store'), [
            'name' => 'Bad Type Exercise',
            'type' => 'invalid-type',
        ])
        ->assertJsonValidationErrors(['type']);
});

test('validation fails when creating exercise with duplicate name for same user', function (): void {
    $user = User::factory()->create();
    Exercise::factory()->create(['user_id' => $user->id, 'name' => 'Duplicate Exercise']);

    actingAs($user)
        ->postJson(route('api.v1.exercises.store'), [
            'name' => 'Duplicate Exercise',
            'type' => 'strength',
        ])
        ->assertJsonValidationErrors(['name']);
});

test('authenticated user can view system exercise', function (): void {
    $user = User::factory()->create();
    $systemExercise = Exercise::factory()->create(['user_id' => null]);

    actingAs($user)
        ->getJson(route('api.v1.exercises.show', $systemExercise))
        ->assertOk()
        ->assertJsonFragment(['id' => $systemExercise->id]);
});

test('authenticated user can view own exercise', function (): void {
    $user = User::factory()->create();
    $userExercise = Exercise::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->getJson(route('api.v1.exercises.show', $userExercise))
        ->assertOk()
        ->assertJsonFragment(['id' => $userExercise->id]);
});

test('authenticated user cannot view other user exercise', function (): void {
    $user = User::factory()->create();
    $otherUserExercise = Exercise::factory()->create(['user_id' => User::factory()->create()->id]);

    actingAs($user)
        ->getJson(route('api.v1.exercises.show', $otherUserExercise))
        ->assertForbidden();
});

test('authenticated user can update own exercise', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $user->id]);

    $response = actingAs($user)
        ->putJson(route('api.v1.exercises.update', $exercise), [
            'name' => 'Updated Name',
            'type' => 'cardio',
        ]);

    $response->assertOk()
        ->assertJsonFragment(['name' => 'Updated Name']);

    $this->assertDatabaseHas('exercises', [
        'id' => $exercise->id,
        'name' => 'Updated Name',
        'type' => 'cardio',
    ]);
});

test('authenticated user cannot update system exercise', function (): void {
    $user = User::factory()->create();
    $systemExercise = Exercise::factory()->create(['user_id' => null]);

    actingAs($user)
        ->putJson(route('api.v1.exercises.update', $systemExercise), [
            'name' => 'Hacked Name',
            'type' => 'cardio',
        ])
        ->assertForbidden();
});

test('authenticated user cannot update other user exercise', function (): void {
    $user = User::factory()->create();
    $otherUserExercise = Exercise::factory()->create(['user_id' => User::factory()->create()->id]);

    actingAs($user)
        ->putJson(route('api.v1.exercises.update', $otherUserExercise), [
            'name' => 'Hacked Name',
            'type' => 'cardio',
        ])
        ->assertForbidden();
});

test('authenticated user can delete own exercise', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->deleteJson(route('api.v1.exercises.destroy', $exercise))
        ->assertNoContent();

    $this->assertDatabaseMissing('exercises', ['id' => $exercise->id]);
});

test('authenticated user cannot delete system exercise', function (): void {
    $user = User::factory()->create();
    $systemExercise = Exercise::factory()->create(['user_id' => null]);

    actingAs($user)
        ->deleteJson(route('api.v1.exercises.destroy', $systemExercise))
        ->assertForbidden();

    $this->assertDatabaseHas('exercises', ['id' => $systemExercise->id]);
});

test('authenticated user cannot delete other user exercise', function (): void {
    $user = User::factory()->create();
    $otherUserExercise = Exercise::factory()->create(['user_id' => User::factory()->create()->id]);

    actingAs($user)
        ->deleteJson(route('api.v1.exercises.destroy', $otherUserExercise))
        ->assertForbidden();

    $this->assertDatabaseHas('exercises', ['id' => $otherUserExercise->id]);
});
