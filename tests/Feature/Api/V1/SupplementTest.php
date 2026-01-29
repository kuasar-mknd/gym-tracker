<?php

declare(strict_types=1);

use App\Models\Supplement;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

test('index returns a list of supplements for the authenticated user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    Supplement::factory()->count(3)->create(['user_id' => $user->id]);
    Supplement::factory()->count(2)->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->getJson(route('api.v1.supplements.index'))
        ->assertStatus(200)
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'brand',
                    'dosage',
                    'servings_remaining',
                    'low_stock_threshold',
                ],
            ],
            'links',
            'meta',
        ]);
});

test('store creates a new supplement', function () {
    $user = User::factory()->create();

    $data = [
        'name' => 'Test Supplement',
        'brand' => 'Test Brand',
        'dosage' => '10mg',
        'servings_remaining' => 50,
        'low_stock_threshold' => 5,
    ];

    actingAs($user)
        ->postJson(route('api.v1.supplements.store'), $data)
        ->assertStatus(201)
        ->assertJsonFragment($data);

    assertDatabaseHas('supplements', [
        'user_id' => $user->id,
        'name' => 'Test Supplement',
        'servings_remaining' => 50,
    ]);
});

test('show returns the correct supplement', function () {
    $user = User::factory()->create();
    $supplement = Supplement::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->getJson(route('api.v1.supplements.show', $supplement))
        ->assertStatus(200)
        ->assertJsonFragment([
            'id' => $supplement->id,
            'name' => $supplement->name,
        ]);
});

test('update modifies an existing supplement', function () {
    $user = User::factory()->create();
    $supplement = Supplement::factory()->create(['user_id' => $user->id]);

    $data = [
        'name' => 'Updated Name',
        'brand' => 'Updated Brand',
        'dosage' => '20mg',
        'servings_remaining' => 40,
        'low_stock_threshold' => 10,
    ];

    actingAs($user)
        ->putJson(route('api.v1.supplements.update', $supplement), $data)
        ->assertStatus(200)
        ->assertJsonFragment($data);

    assertDatabaseHas('supplements', [
        'id' => $supplement->id,
        'name' => 'Updated Name',
        'servings_remaining' => 40,
    ]);
});

test('destroy removes a supplement', function () {
    $user = User::factory()->create();
    $supplement = Supplement::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->deleteJson(route('api.v1.supplements.destroy', $supplement))
        ->assertStatus(204);

    assertDatabaseMissing('supplements', ['id' => $supplement->id]);
});

test('store requires valid data', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson(route('api.v1.supplements.store'), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'servings_remaining', 'low_stock_threshold']);

    actingAs($user)
        ->postJson(route('api.v1.supplements.store'), [
            'name' => 123, // Should be string
            'servings_remaining' => 'not-a-number', // Should be integer
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'servings_remaining']);
});

test('update requires valid data', function () {
    $user = User::factory()->create();
    $supplement = Supplement::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->putJson(route('api.v1.supplements.update', $supplement), [
            'name' => '', // Required
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

test('user cannot access another users supplement', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $supplement = Supplement::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->getJson(route('api.v1.supplements.show', $supplement))
        ->assertStatus(403);
});

test('user cannot update another users supplement', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $supplement = Supplement::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->putJson(route('api.v1.supplements.update', $supplement), [
            'name' => 'Hacked',
            'servings_remaining' => 10,
            'low_stock_threshold' => 1,
        ])
        ->assertStatus(403);
});

test('user cannot delete another users supplement', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $supplement = Supplement::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->deleteJson(route('api.v1.supplements.destroy', $supplement))
        ->assertStatus(403);

    assertDatabaseHas('supplements', ['id' => $supplement->id]);
});

test('unauthenticated users cannot access supplements', function () {
    getJson(route('api.v1.supplements.index'))->assertStatus(401);
    postJson(route('api.v1.supplements.store'), [])->assertStatus(401);
});
