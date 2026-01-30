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

test('users can list their supplements', function (): void {
    $user = User::factory()->create();
    $supplements = Supplement::factory()->count(3)->create(['user_id' => $user->id]);
    $otherUserSupplement = Supplement::factory()->create();

    actingAs($user)
        ->getJson(route('api.v1.supplements.index'))
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonFragment(['id' => $supplements->first()->id])
        ->assertJsonMissing(['id' => $otherUserSupplement->id]);
});

test('users can view a specific supplement', function (): void {
    $user = User::factory()->create();
    $supplement = Supplement::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->getJson(route('api.v1.supplements.show', $supplement))
        ->assertOk()
        ->assertJsonFragment(['name' => $supplement->name]);
});

test('users can create a supplement', function (): void {
    $user = User::factory()->create();
    $data = [
        'name' => 'Creatine Monohydrate',
        'brand' => 'MyProtein',
        'dosage' => '5g',
        'servings_remaining' => 100,
        'low_stock_threshold' => 10,
    ];

    actingAs($user)
        ->postJson(route('api.v1.supplements.store'), $data)
        ->assertCreated()
        ->assertJsonFragment(['name' => 'Creatine Monohydrate']);

    assertDatabaseHas('supplements', [
        'user_id' => $user->id,
        'name' => 'Creatine Monohydrate',
    ]);
});

test('users can update a supplement', function (): void {
    $user = User::factory()->create();
    $supplement = Supplement::factory()->create(['user_id' => $user->id]);
    $data = [
        'name' => 'Updated Name',
        'servings_remaining' => 50,
        'low_stock_threshold' => 5,
    ];

    actingAs($user)
        ->putJson(route('api.v1.supplements.update', $supplement), $data)
        ->assertOk()
        ->assertJsonFragment(['name' => 'Updated Name']);

    assertDatabaseHas('supplements', [
        'id' => $supplement->id,
        'name' => 'Updated Name',
    ]);
});

test('users can delete a supplement', function (): void {
    $user = User::factory()->create();
    $supplement = Supplement::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->deleteJson(route('api.v1.supplements.destroy', $supplement))
        ->assertNoContent();

    assertDatabaseMissing('supplements', ['id' => $supplement->id]);
});

test('validation errors for creating supplement', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson(route('api.v1.supplements.store'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'servings_remaining', 'low_stock_threshold']);
});

test('users cannot view others supplements', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $supplement = Supplement::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->getJson(route('api.v1.supplements.show', $supplement))
        ->assertForbidden();
});

test('users cannot update others supplements', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $supplement = Supplement::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->putJson(route('api.v1.supplements.update', $supplement), [
            'name' => 'Hacked',
            'servings_remaining' => 10,
            'low_stock_threshold' => 5
        ])
        ->assertForbidden();
});

test('users cannot delete others supplements', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $supplement = Supplement::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->deleteJson(route('api.v1.supplements.destroy', $supplement))
        ->assertForbidden();
});

test('unauthenticated users cannot access supplements', function (): void {
    getJson(route('api.v1.supplements.index'))->assertUnauthorized();
    postJson(route('api.v1.supplements.store'), [])->assertUnauthorized();
});
