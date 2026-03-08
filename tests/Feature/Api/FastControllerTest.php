<?php

declare(strict_types=1);

use App\Models\Fast;
use App\Models\User;
use function Pest\Laravel\actingAs;

test('authenticated user can view their fasts', function (): void {
    $user = User::factory()->create();
    Fast::factory()->count(3)->create(['user_id' => $user->id]);

    actingAs($user)
        ->getJson(route('api.v1.fasts.index'))
        ->assertOk()
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
        ])
        ->assertJsonCount(3, 'data');
});

test('user cannot view other users fasts', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    Fast::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->getJson(route('api.v1.fasts.index'))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('authenticated user can create a fast', function (): void {
    $user = User::factory()->create();

    $payload = [
        'start_time' => now()->toIso8601String(),
        'target_duration_minutes' => 960,
        'type' => '16:8',
    ];

    actingAs($user)
        ->postJson(route('api.v1.fasts.store'), $payload)
        ->assertCreated()
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

test('user cannot create a fast if an active one already exists', function (): void {
    $user = User::factory()->create();

    // Create an active fast
    Fast::factory()->create([
        'user_id' => $user->id,
        'status' => 'active',
    ]);

    $payload = [
        'start_time' => now()->toIso8601String(),
        'target_duration_minutes' => 960,
        'type' => '16:8',
    ];

    actingAs($user)
        ->postJson(route('api.v1.fasts.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['base']);
});

test('user can create a fast if previous fasts are completed or broken', function (): void {
    $user = User::factory()->create();

    Fast::factory()->create([
        'user_id' => $user->id,
        'status' => 'completed',
    ]);

    Fast::factory()->create([
        'user_id' => $user->id,
        'status' => 'broken',
    ]);

    $payload = [
        'start_time' => now()->toIso8601String(),
        'target_duration_minutes' => 1200,
        'type' => '20:4',
    ];

    actingAs($user)
        ->postJson(route('api.v1.fasts.store'), $payload)
        ->assertCreated()
        ->assertJsonPath('data.status', 'active');
});

test('store fast validation errors', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson(route('api.v1.fasts.store'), [
            'target_duration_minutes' => 'invalid',
            'type' => 'invalid_type',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['start_time', 'target_duration_minutes', 'type']);
});

test('user can view a specific fast', function (): void {
    $user = User::factory()->create();
    $fast = Fast::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->getJson(route('api.v1.fasts.show', $fast))
        ->assertOk()
        ->assertJsonPath('data.id', $fast->id)
        ->assertJsonStructure([
            'data' => [
                'id',
                'start_time',
                'end_time',
                'target_duration_minutes',
                'type',
                'status',
            ],
        ]);
});

test('user cannot view another users fast', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $fast = Fast::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->getJson(route('api.v1.fasts.show', $fast))
        ->assertForbidden();
});

test('user can update their fast', function (): void {
    $user = User::factory()->create();
    $fast = Fast::factory()->create([
        'user_id' => $user->id,
        'status' => 'active',
        'target_duration_minutes' => 960,
    ]);

    $payload = [
        'end_time' => now()->addHours(16)->toIso8601String(),
        'status' => 'completed',
        'target_duration_minutes' => 1000,
    ];

    actingAs($user)
        ->putJson(route('api.v1.fasts.update', $fast), $payload)
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.target_duration_minutes', 1000);

    $this->assertDatabaseHas('fasts', [
        'id' => $fast->id,
        'status' => 'completed',
        'target_duration_minutes' => 1000,
    ]);
});

test('update fast validation errors', function (): void {
    $user = User::factory()->create();
    $fast = Fast::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->putJson(route('api.v1.fasts.update', $fast), [
            'status' => 'invalid_status',
            'target_duration_minutes' => -10,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status', 'target_duration_minutes']);
});

test('user cannot update another users fast', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $fast = Fast::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->putJson(route('api.v1.fasts.update', $fast), [
            'status' => 'completed',
        ])
        ->assertForbidden();
});

test('user can delete their fast', function (): void {
    $user = User::factory()->create();
    $fast = Fast::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->deleteJson(route('api.v1.fasts.destroy', $fast))
        ->assertNoContent();

    $this->assertDatabaseMissing('fasts', [
        'id' => $fast->id,
    ]);
});

test('user cannot delete another users fast', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $fast = Fast::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->deleteJson(route('api.v1.fasts.destroy', $fast))
        ->assertForbidden();

    $this->assertDatabaseHas('fasts', [
        'id' => $fast->id,
    ]);
});
