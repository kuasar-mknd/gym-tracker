<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Fast;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(RefreshDatabase::class);

test('index returns only the users fasts', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    Fast::factory()->count(3)->create(['user_id' => $user->id]);
    Fast::factory()->count(2)->create(['user_id' => $otherUser->id]);

    $response = actingAs($user)->getJson('/api/v1/fasts');

    $response->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'start_time',
                    'end_time',
                    'target_duration_minutes',
                    'type',
                    'status',
                ],
            ],
            'meta',
            'links',
        ]);
});

test('index requires authentication', function () {
    getJson('/api/v1/fasts')->assertUnauthorized();
});

test('store creates a new fast', function () {
    $user = User::factory()->create();

    $data = [
        'start_time' => now()->subHours(2)->toIso8601String(),
        'target_duration_minutes' => 960,
        'type' => '16:8',
    ];

    $response = actingAs($user)->postJson('/api/v1/fasts', $data);

    $response->assertCreated()
        ->assertJsonPath('data.target_duration_minutes', 960)
        ->assertJsonPath('data.type', '16:8')
        ->assertJsonPath('data.status', 'active');

    $this->assertDatabaseHas('fasts', [
        'user_id' => $user->id,
        'target_duration_minutes' => 960,
        'type' => '16:8',
        'status' => 'active',
    ]);
});

test('store validates required fields', function () {
    $user = User::factory()->create();

    $response = actingAs($user)->postJson('/api/v1/fasts', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['start_time', 'target_duration_minutes', 'type']);
});

test('store prevents multiple active fasts', function () {
    $user = User::factory()->create();

    Fast::factory()->create([
        'user_id' => $user->id,
        'status' => 'active',
    ]);

    $data = [
        'start_time' => now()->toIso8601String(),
        'target_duration_minutes' => 960,
        'type' => '16:8',
    ];

    $response = actingAs($user)->postJson('/api/v1/fasts', $data);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['base']);
});

test('show returns the requested fast', function () {
    $user = User::factory()->create();
    $fast = Fast::factory()->create(['user_id' => $user->id]);

    $response = actingAs($user)->getJson("/api/v1/fasts/{$fast->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $fast->id);
});

test('show forbids accessing another users fast', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $fast = Fast::factory()->create(['user_id' => $otherUser->id]);

    $response = actingAs($user)->getJson("/api/v1/fasts/{$fast->id}");

    $response->assertForbidden();
});

test('update modifies an existing fast', function () {
    $user = User::factory()->create();
    $fast = Fast::factory()->create([
        'user_id' => $user->id,
        'status' => 'active',
    ]);

    $endTime = now()->toIso8601String();

    $response = actingAs($user)->putJson("/api/v1/fasts/{$fast->id}", [
        'status' => 'completed',
        'end_time' => $endTime,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'completed');

    $this->assertDatabaseHas('fasts', [
        'id' => $fast->id,
        'status' => 'completed',
    ]);
});

test('update validates status values', function () {
    $user = User::factory()->create();
    $fast = Fast::factory()->create(['user_id' => $user->id]);

    $response = actingAs($user)->putJson("/api/v1/fasts/{$fast->id}", [
        'status' => 'invalid_status',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

test('update forbids modifying another users fast', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $fast = Fast::factory()->create(['user_id' => $otherUser->id]);

    $response = actingAs($user)->putJson("/api/v1/fasts/{$fast->id}", [
        'status' => 'completed',
    ]);

    $response->assertForbidden();
});

test('destroy deletes the fast', function () {
    $user = User::factory()->create();
    $fast = Fast::factory()->create(['user_id' => $user->id]);

    $response = actingAs($user)->deleteJson("/api/v1/fasts/{$fast->id}");

    $response->assertNoContent();

    $this->assertDatabaseMissing('fasts', ['id' => $fast->id]);
});

test('destroy forbids deleting another users fast', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $fast = Fast::factory()->create(['user_id' => $otherUser->id]);

    $response = actingAs($user)->deleteJson("/api/v1/fasts/{$fast->id}");

    $response->assertForbidden();

    $this->assertDatabaseHas('fasts', ['id' => $fast->id]);
});
