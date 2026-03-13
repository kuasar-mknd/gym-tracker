<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\WorkoutTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

test('user can list their workout templates', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $userTemplate = WorkoutTemplate::factory()->create(['user_id' => $user->id]);
    $otherUserTemplate = WorkoutTemplate::factory()->create(['user_id' => $otherUser->id]);

    $response = actingAs($user)->getJson(route('api.v1.workout-templates.index'));

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonFragment(['id' => $userTemplate->id])
        ->assertJsonMissing(['id' => $otherUserTemplate->id]);
});

test('unauthenticated user cannot list workout templates', function (): void {
    getJson(route('api.v1.workout-templates.index'))->assertUnauthorized();
});

test('user can create workout template', function (): void {
    $user = User::factory()->create();

    $data = [
        'name' => 'My Push Day',
        'description' => 'A heavy push day routine',
    ];

    $response = actingAs($user)->postJson(route('api.v1.workout-templates.store'), $data);

    $response->assertCreated()
        ->assertJsonFragment(['name' => 'My Push Day', 'description' => 'A heavy push day routine']);

    $this->assertDatabaseHas('workout_templates', [
        'name' => 'My Push Day',
        'description' => 'A heavy push day routine',
        'user_id' => $user->id,
    ]);
});

test('user can view own workout template', function (): void {
    $user = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->getJson(route('api.v1.workout-templates.show', $template))
        ->assertOk()
        ->assertJsonFragment(['id' => $template->id, 'name' => $template->name]);
});

test('user cannot view other user workout template', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->getJson(route('api.v1.workout-templates.show', $template))
        ->assertForbidden();
});

test('user can update own workout template', function (): void {
    $user = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $user->id]);

    $data = [
        'name' => 'Updated Name',
        'description' => 'Updated Description',
    ];

    $response = actingAs($user)->putJson(route('api.v1.workout-templates.update', $template), $data);

    $response->assertOk()
        ->assertJsonFragment(['name' => 'Updated Name', 'description' => 'Updated Description']);

    $this->assertDatabaseHas('workout_templates', [
        'id' => $template->id,
        'name' => 'Updated Name',
        'description' => 'Updated Description',
    ]);
});

test('user cannot update other user workout template', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $otherUser->id]);

    $data = [
        'name' => 'Hacked Name',
    ];

    actingAs($user)
        ->putJson(route('api.v1.workout-templates.update', $template), $data)
        ->assertForbidden();
});

test('user can delete own workout template', function (): void {
    $user = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->deleteJson(route('api.v1.workout-templates.destroy', $template))
        ->assertNoContent();

    $this->assertDatabaseMissing('workout_templates', ['id' => $template->id]);
});

test('user cannot delete other user workout template', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->deleteJson(route('api.v1.workout-templates.destroy', $template))
        ->assertForbidden();

    $this->assertDatabaseHas('workout_templates', ['id' => $template->id]);
});

test('workout template creation requires valid data', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson(route('api.v1.workout-templates.store'), [
            // missing 'name'
            'description' => 'test description',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});
