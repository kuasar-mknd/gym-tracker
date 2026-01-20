<?php

use App\Models\Exercise;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

// Happy Path Tests

test('authenticated user can list exercises (system and own)', function () {
    $user = User::factory()->create();

    // Create system exercises
    Exercise::factory()->count(3)->create(['user_id' => null]);

    // Create user's own exercises
    Exercise::factory()->count(2)->create(['user_id' => $user->id]);

    // Create other user's exercises (should not be visible)
    $otherUser = User::factory()->create();
    Exercise::factory()->count(2)->create(['user_id' => $otherUser->id]);

    Sanctum::actingAs($user);

    $response = getJson('/api/v1/exercises');

    $response->assertOk()
        ->assertJsonCount(5, 'data'); // 3 system + 2 own
});

test('authenticated user can filter exercises by name', function () {
    $user = User::factory()->create();

    Exercise::factory()->create(['name' => 'Bench Press', 'user_id' => null]);
    Exercise::factory()->create(['name' => 'Squat', 'user_id' => null]);

    Sanctum::actingAs($user);

    $response = getJson('/api/v1/exercises?filter[name]=Bench');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonFragment(['name' => 'Bench Press']);
});

test('authenticated user can filter exercises by type', function () {
    $user = User::factory()->create();

    Exercise::factory()->create(['name' => 'Running', 'type' => 'cardio', 'user_id' => null]);
    Exercise::factory()->create(['name' => 'Pushups', 'type' => 'strength', 'user_id' => null]);

    Sanctum::actingAs($user);

    $response = getJson('/api/v1/exercises?filter[type]=cardio');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonFragment(['name' => 'Running']);
});

test('authenticated user can create an exercise', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $data = [
        'name' => 'New Unique Exercise',
        'type' => 'strength',
        'category' => 'Test Category',
    ];

    $response = postJson('/api/v1/exercises', $data);

    $response->assertCreated()
        ->assertJsonFragment(['name' => 'New Unique Exercise']);

    assertDatabaseHas('exercises', [
        'name' => 'New Unique Exercise',
        'user_id' => $user->id,
        'type' => 'strength',
    ]);
});

test('authenticated user can view a specific exercise', function () {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $user->id]);

    Sanctum::actingAs($user);

    $response = getJson("/api/v1/exercises/{$exercise->id}");

    $response->assertOk()
        ->assertJsonFragment(['id' => $exercise->id, 'name' => $exercise->name]);
});

test('authenticated user can view a system exercise', function () {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => null]);

    Sanctum::actingAs($user);

    $response = getJson("/api/v1/exercises/{$exercise->id}");

    $response->assertOk()
        ->assertJsonFragment(['id' => $exercise->id]);
});

test('authenticated user can update their own exercise', function () {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $user->id, 'name' => 'Old Name']);

    Sanctum::actingAs($user);

    $response = putJson("/api/v1/exercises/{$exercise->id}", [
        'name' => 'Updated Name',
        'type' => 'strength', // Type is required if present in request or sometimes rule applies
    ]);

    $response->assertOk()
        ->assertJsonFragment(['name' => 'Updated Name']);

    assertDatabaseHas('exercises', [
        'id' => $exercise->id,
        'name' => 'Updated Name',
    ]);
});

test('authenticated user can delete their own exercise', function () {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $user->id]);

    Sanctum::actingAs($user);

    $response = deleteJson("/api/v1/exercises/{$exercise->id}");

    $response->assertNoContent();

    assertDatabaseMissing('exercises', ['id' => $exercise->id]);
});

// Validation Tests

test('create exercise requires name and type', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = postJson('/api/v1/exercises', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'type']);
});

test('create exercise requires valid type', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = postJson('/api/v1/exercises', [
        'name' => 'Test Exercise',
        'type' => 'invalid_type',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['type']);
});

test('cannot create exercise with duplicate name (same user)', function () {
    $user = User::factory()->create();
    Exercise::factory()->create(['user_id' => $user->id, 'name' => 'Duplicate Name']);

    Sanctum::actingAs($user);

    $response = postJson('/api/v1/exercises', [
        'name' => 'Duplicate Name',
        'type' => 'strength',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('cannot create exercise with duplicate name (system exercise)', function () {
    $user = User::factory()->create();
    Exercise::factory()->create(['user_id' => null, 'name' => 'System Exercise']);

    Sanctum::actingAs($user);

    $response = postJson('/api/v1/exercises', [
        'name' => 'System Exercise',
        'type' => 'strength',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('update exercise requires valid type if provided', function () {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $user->id]);

    Sanctum::actingAs($user);

    $response = putJson("/api/v1/exercises/{$exercise->id}", [
        'type' => 'invalid_type',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['type']);
});

test('cannot update exercise to duplicate name (existing system exercise)', function () {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $user->id, 'name' => 'My Exercise']);
    Exercise::factory()->create(['user_id' => null, 'name' => 'System Exercise']);

    Sanctum::actingAs($user);

    $response = putJson("/api/v1/exercises/{$exercise->id}", [
        'name' => 'System Exercise',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

// Authorization Tests

test('unauthenticated user cannot access exercises', function () {
    getJson('/api/v1/exercises')->assertUnauthorized();
    postJson('/api/v1/exercises', [])->assertUnauthorized();

    $exercise = Exercise::factory()->create();
    getJson("/api/v1/exercises/{$exercise->id}")->assertUnauthorized();
    putJson("/api/v1/exercises/{$exercise->id}", [])->assertUnauthorized();
    deleteJson("/api/v1/exercises/{$exercise->id}")->assertUnauthorized();
});

test('user cannot view other users exercise', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $otherUser->id]);

    Sanctum::actingAs($user);

    $response = getJson("/api/v1/exercises/{$exercise->id}");

    $response->assertForbidden();
});

test('user cannot update other users exercise', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $otherUser->id]);

    Sanctum::actingAs($user);

    $response = putJson("/api/v1/exercises/{$exercise->id}", [
        'name' => 'Hacked Name',
    ]);

    $response->assertForbidden();
});

test('user cannot update system exercise', function () {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => null]);

    Sanctum::actingAs($user);

    $response = putJson("/api/v1/exercises/{$exercise->id}", [
        'name' => 'Hacked Name',
    ]);

    $response->assertForbidden();
});

test('user cannot delete other users exercise', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $otherUser->id]);

    Sanctum::actingAs($user);

    $response = deleteJson("/api/v1/exercises/{$exercise->id}");

    $response->assertForbidden();
});

test('user cannot delete system exercise', function () {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => null]);

    Sanctum::actingAs($user);

    $response = deleteJson("/api/v1/exercises/{$exercise->id}");

    $response->assertForbidden();
});
