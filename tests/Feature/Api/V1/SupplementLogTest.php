<?php

declare(strict_types=1);

use App\Models\Supplement;
use App\Models\SupplementLog;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

test('users can list their supplement logs', function (): void {
    $user = User::factory()->create();
    $logs = SupplementLog::factory()->count(3)->create(['user_id' => $user->id]);
    $otherUserLog = SupplementLog::factory()->create();

    actingAs($user)
        ->getJson(route('api.v1.supplement-logs.index'))
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonFragment(['id' => $logs->first()->id])
        ->assertJsonMissing(['id' => $otherUserLog->id]);
});

test('users can view a specific supplement log', function (): void {
    $user = User::factory()->create();
    $log = SupplementLog::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->getJson(route('api.v1.supplement-logs.show', $log))
        ->assertOk()
        ->assertJsonFragment(['id' => $log->id]);
});

test('users can create a supplement log', function (): void {
    $user = User::factory()->create();
    $supplement = Supplement::factory()->create(['user_id' => $user->id, 'servings_remaining' => 10]);
    $data = [
        'supplement_id' => $supplement->id,
        'quantity' => 1,
        'consumed_at' => now()->toIso8601String(),
    ];

    actingAs($user)
        ->postJson(route('api.v1.supplement-logs.store'), $data)
        ->assertCreated()
        ->assertJsonFragment(['quantity' => 1]);

    assertDatabaseHas('supplement_logs', [
        'user_id' => $user->id,
        'supplement_id' => $supplement->id,
        'quantity' => 1,
    ]);
});

test('users can update a supplement log', function (): void {
    $user = User::factory()->create();
    $log = SupplementLog::factory()->create(['user_id' => $user->id, 'quantity' => 1]);
    $data = [
        'quantity' => 5,
    ];

    actingAs($user)
        ->putJson(route('api.v1.supplement-logs.update', $log), $data)
        ->assertOk()
        ->assertJsonFragment(['quantity' => 5]);

    assertDatabaseHas('supplement_logs', [
        'id' => $log->id,
        'quantity' => 5,
    ]);
});

test('users can delete a supplement log', function (): void {
    $user = User::factory()->create();
    $log = SupplementLog::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->deleteJson(route('api.v1.supplement-logs.destroy', $log))
        ->assertNoContent();

    assertDatabaseMissing('supplement_logs', ['id' => $log->id]);
});

test('validation errors for creating supplement log', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson(route('api.v1.supplement-logs.store'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['supplement_id', 'quantity']);
});

test('users cannot create log for others supplement', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $supplement = Supplement::factory()->create(['user_id' => $otherUser->id]);

    $data = [
        'supplement_id' => $supplement->id,
        'quantity' => 1,
    ];

    actingAs($user)
        ->postJson(route('api.v1.supplement-logs.store'), $data)
        ->assertUnprocessable() // Validation rule should catch this
        ->assertJsonValidationErrors(['supplement_id']);
});

test('users cannot view others logs', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $log = SupplementLog::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->getJson(route('api.v1.supplement-logs.show', $log))
        ->assertForbidden();
});

test('users cannot update others logs', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $log = SupplementLog::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->putJson(route('api.v1.supplement-logs.update', $log), [
            'quantity' => 10,
        ])
        ->assertForbidden();
});

test('users cannot delete others logs', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $log = SupplementLog::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->deleteJson(route('api.v1.supplement-logs.destroy', $log))
        ->assertForbidden();
});

test('unauthenticated users cannot access logs', function (): void {
    getJson(route('api.v1.supplement-logs.index'))->assertUnauthorized();
    postJson(route('api.v1.supplement-logs.store'), [])->assertUnauthorized();
});
