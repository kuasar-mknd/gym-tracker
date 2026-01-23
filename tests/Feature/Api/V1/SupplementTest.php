<?php

use App\Models\Supplement;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

test('index returns successful response', function () {
    $user = User::factory()->create();
    Supplement::factory()->count(3)->create(['user_id' => $user->id]);

    actingAs($user)
        ->getJson(route('api.v1.supplements.index'))
        ->assertStatus(200)
        ->assertJsonCount(3, 'data');
});

test('index only returns user supplements', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    Supplement::factory()->create(['user_id' => $user->id]);
    Supplement::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->getJson(route('api.v1.supplements.index'))
        ->assertStatus(200)
        ->assertJsonCount(1, 'data');
});

test('store creates supplement successfully', function () {
    $user = User::factory()->create();
    $data = [
        'name' => 'Creatine',
        'brand' => 'Optimum Nutrition',
        'dosage' => '5g',
        'servings_remaining' => 50,
        'low_stock_threshold' => 10,
    ];

    actingAs($user)
        ->postJson(route('api.v1.supplements.store'), $data)
        ->assertStatus(201)
        ->assertJsonFragment($data);

    $this->assertDatabaseHas('supplements', [
        'user_id' => $user->id,
        'name' => 'Creatine',
    ]);
});

test('store validates required fields', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson(route('api.v1.supplements.store'), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'servings_remaining', 'low_stock_threshold']);
});

test('show returns supplement details', function () {
    $user = User::factory()->create();
    $supplement = Supplement::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->getJson(route('api.v1.supplements.show', $supplement))
        ->assertStatus(200)
        ->assertJsonFragment(['id' => $supplement->id, 'name' => $supplement->name]);
});

test('show returns 403 for other user supplement', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $supplement = Supplement::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->getJson(route('api.v1.supplements.show', $supplement))
        ->assertStatus(403);
});

test('update modifies supplement successfully', function () {
    $user = User::factory()->create();
    $supplement = Supplement::factory()->create(['user_id' => $user->id]);
    $updateData = [
        'name' => 'Updated Name',
        'servings_remaining' => 100,
        'low_stock_threshold' => 20,
    ];

    actingAs($user)
        ->putJson(route('api.v1.supplements.update', $supplement), $updateData)
        ->assertStatus(200)
        ->assertJsonFragment($updateData);

    $this->assertDatabaseHas('supplements', [
        'id' => $supplement->id,
        'name' => 'Updated Name',
    ]);
});

test('update validates input', function () {
    $user = User::factory()->create();
    $supplement = Supplement::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->putJson(route('api.v1.supplements.update', $supplement), ['name' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

test('update forbids modifying other user supplement', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $supplement = Supplement::factory()->create(['user_id' => $otherUser->id]);

    $data = [
        'name' => 'Hacked',
        'servings_remaining' => 50,
        'low_stock_threshold' => 10,
    ];

    actingAs($user)
        ->putJson(route('api.v1.supplements.update', $supplement), $data)
        ->assertStatus(403);
});

test('destroy deletes supplement', function () {
    $user = User::factory()->create();
    $supplement = Supplement::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->deleteJson(route('api.v1.supplements.destroy', $supplement))
        ->assertStatus(204);

    $this->assertDatabaseMissing('supplements', ['id' => $supplement->id]);
});

test('destroy forbids deleting other user supplement', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $supplement = Supplement::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->deleteJson(route('api.v1.supplements.destroy', $supplement))
        ->assertStatus(403);

    $this->assertDatabaseHas('supplements', ['id' => $supplement->id]);
});
