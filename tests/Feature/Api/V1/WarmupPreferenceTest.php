<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\WarmupPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(RefreshDatabase::class);

test('authenticated user can list their warmup preferences', function (): void {
    $user = User::factory()->create();
    WarmupPreference::factory()->count(2)->create(['user_id' => $user->id]);

    Sanctum::actingAs($user);

    $response = getJson(route('api.v1.warmup-preferences.index'));

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

test('user cannot see other users warmup preferences', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    WarmupPreference::factory()->create(['user_id' => $otherUser->id]);

    Sanctum::actingAs($user);

    $response = getJson(route('api.v1.warmup-preferences.index'));

    $response->assertOk()
        ->assertJsonCount(0, 'data');
});

test('authenticated user can create a warmup preference', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $data = [
        'bar_weight' => 20,
        'rounding_increment' => 2.5,
        'steps' => [
            ['percent' => 50, 'reps' => 10, 'label' => 'Warmup'],
        ],
    ];

    $response = postJson(route('api.v1.warmup-preferences.store'), $data);

    $response->assertCreated()
        ->assertJsonFragment([
            'bar_weight' => 20,
            'rounding_increment' => 2.5,
        ]);

    assertDatabaseHas('warmup_preferences', [
        'user_id' => $user->id,
        'bar_weight' => 20,
    ]);
});

test('authenticated user can view their own warmup preference', function (): void {
    $user = User::factory()->create();
    $preference = WarmupPreference::factory()->create(['user_id' => $user->id]);

    Sanctum::actingAs($user);

    $response = getJson(route('api.v1.warmup-preferences.show', $preference));

    $response->assertOk()
        ->assertJsonFragment([
            'id' => $preference->id,
            'bar_weight' => $preference->bar_weight,
        ]);
});

test('user cannot view others warmup preference', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $preference = WarmupPreference::factory()->create(['user_id' => $otherUser->id]);

    Sanctum::actingAs($user);

    $response = getJson(route('api.v1.warmup-preferences.show', $preference));

    $response->assertForbidden();
});

test('authenticated user can update their warmup preference', function (): void {
    $user = User::factory()->create();
    $preference = WarmupPreference::factory()->create(['user_id' => $user->id]);

    Sanctum::actingAs($user);

    $response = putJson(route('api.v1.warmup-preferences.update', $preference), [
        'bar_weight' => 25,
        'rounding_increment' => 5.0,
        'steps' => [['percent' => 60, 'reps' => 5, 'label' => 'Updated']],
    ]);

    $response->assertOk()
        ->assertJsonFragment([
            'bar_weight' => 25,
            'rounding_increment' => 5.0,
        ]);

    assertDatabaseHas('warmup_preferences', [
        'id' => $preference->id,
        'bar_weight' => 25,
    ]);
});

test('user cannot update others warmup preference', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $preference = WarmupPreference::factory()->create(['user_id' => $otherUser->id]);

    Sanctum::actingAs($user);

    $response = putJson(route('api.v1.warmup-preferences.update', $preference), [
        'bar_weight' => 25,
        'rounding_increment' => 5.0,
        'steps' => [['percent' => 60, 'reps' => 5]],
    ]);

    $response->assertForbidden();
});

test('authenticated user can delete their warmup preference', function (): void {
    $user = User::factory()->create();
    $preference = WarmupPreference::factory()->create(['user_id' => $user->id]);

    Sanctum::actingAs($user);

    $response = deleteJson(route('api.v1.warmup-preferences.destroy', $preference));

    $response->assertNoContent();

    assertDatabaseMissing('warmup_preferences', ['id' => $preference->id]);
});

test('user cannot delete others warmup preference', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $preference = WarmupPreference::factory()->create(['user_id' => $otherUser->id]);

    Sanctum::actingAs($user);

    $response = deleteJson(route('api.v1.warmup-preferences.destroy', $preference));

    $response->assertForbidden();

    assertDatabaseHas('warmup_preferences', ['id' => $preference->id]);
});

test('unauthenticated user cannot access endpoints', function (): void {
    $preference = WarmupPreference::factory()->create();

    getJson(route('api.v1.warmup-preferences.index'))->assertUnauthorized();
    postJson(route('api.v1.warmup-preferences.store'), [])->assertUnauthorized();
    getJson(route('api.v1.warmup-preferences.show', $preference))->assertUnauthorized();
    putJson(route('api.v1.warmup-preferences.update', $preference), [])->assertUnauthorized();
    deleteJson(route('api.v1.warmup-preferences.destroy', $preference))->assertUnauthorized();
});

test('store requires valid bar weight', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // Missing field
    postJson(route('api.v1.warmup-preferences.store'), [
        'rounding_increment' => 2.5,
        'steps' => [['percent' => 50, 'reps' => 10]],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['bar_weight']);

    // Invalid type
    postJson(route('api.v1.warmup-preferences.store'), [
        'bar_weight' => 'invalid',
        'rounding_increment' => 2.5,
        'steps' => [['percent' => 50, 'reps' => 10]],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['bar_weight']);

    // Negative value
    postJson(route('api.v1.warmup-preferences.store'), [
        'bar_weight' => -10,
        'rounding_increment' => 2.5,
        'steps' => [['percent' => 50, 'reps' => 10]],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['bar_weight']);
});

test('store requires valid rounding increment', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    postJson(route('api.v1.warmup-preferences.store'), [
        'bar_weight' => 20,
        'rounding_increment' => -2.5,
        'steps' => [['percent' => 50, 'reps' => 10]],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['rounding_increment']);
});

test('store requires valid steps array', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // Not an array
    postJson(route('api.v1.warmup-preferences.store'), [
        'bar_weight' => 20,
        'rounding_increment' => 2.5,
        'steps' => 'invalid',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['steps']);

    // Invalid step structure
    postJson(route('api.v1.warmup-preferences.store'), [
        'bar_weight' => 20,
        'rounding_increment' => 2.5,
        'steps' => [['percent' => 50]], // Missing reps
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['steps.0.reps']);

    // Invalid percent range
    postJson(route('api.v1.warmup-preferences.store'), [
        'bar_weight' => 20,
        'rounding_increment' => 2.5,
        'steps' => [['percent' => 150, 'reps' => 10]],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['steps.0.percent']);
});
