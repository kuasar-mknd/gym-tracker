<?php

declare(strict_types=1);

use App\Models\Supplement;
use App\Models\SupplementLog;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

test('user can view their supplement logs', function (): void {
    $user = User::factory()->create();
    SupplementLog::factory()->count(3)->create(['user_id' => $user->id]);
    SupplementLog::factory()->count(2)->create(); // Other user's logs

    $response = actingAs($user)->getJson(route('api.v1.supplement-logs.index'));

    $response->assertOk()
        ->assertJsonCount(3, 'data');
});

test('user can create a supplement log', function (): void {
    $user = User::factory()->create();
    $supplement = Supplement::factory()->create(['user_id' => $user->id]);

    $payload = [
        'supplement_id' => $supplement->id,
        'quantity' => 2,
        'consumed_at' => now()->toDateTimeString(),
    ];

    $response = actingAs($user)->postJson(route('api.v1.supplement-logs.store'), $payload);

    $response->assertCreated()
        ->assertJsonPath('data.quantity', 2);

    assertDatabaseHas('supplement_logs', [
        'user_id' => $user->id,
        'supplement_id' => $supplement->id,
        'quantity' => 2,
    ]);
});

test('user cannot create a supplement log with invalid data', function (): void {
    $user = User::factory()->create();

    $response = actingAs($user)->postJson(route('api.v1.supplement-logs.store'), [
        'supplement_id' => 9999, // Non-existent
        'quantity' => 0, // Invalid quantity (min: 1)
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['supplement_id', 'quantity', 'consumed_at']);
});

test('user cannot log another users supplement', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherSupplement = Supplement::factory()->create(['user_id' => $otherUser->id]);

    $response = actingAs($user)->postJson(route('api.v1.supplement-logs.store'), [
        'supplement_id' => $otherSupplement->id,
        'quantity' => 1,
        'consumed_at' => now()->toDateTimeString(),
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['supplement_id']);
});

test('user can view a specific supplement log', function (): void {
    $user = User::factory()->create();
    $log = SupplementLog::factory()->create(['user_id' => $user->id]);

    $response = actingAs($user)->getJson(route('api.v1.supplement-logs.show', $log));

    $response->assertOk()
        ->assertJsonPath('data.id', $log->id);
});

test('user cannot view another users supplement log', function (): void {
    $user = User::factory()->create();
    $log = SupplementLog::factory()->create(); // Belongs to a different user

    $response = actingAs($user)->getJson(route('api.v1.supplement-logs.show', $log));

    $response->assertForbidden();
});

test('user can update their supplement log', function (): void {
    $user = User::factory()->create();
    $log = SupplementLog::factory()->create([
        'user_id' => $user->id,
        'quantity' => 1,
    ]);

    $response = actingAs($user)->putJson(route('api.v1.supplement-logs.update', $log), [
        'quantity' => 3,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.quantity', 3);

    assertDatabaseHas('supplement_logs', [
        'id' => $log->id,
        'quantity' => 3,
    ]);
});

test('user cannot update another users supplement log', function (): void {
    $user = User::factory()->create();
    $log = SupplementLog::factory()->create();

    $response = actingAs($user)->putJson(route('api.v1.supplement-logs.update', $log), [
        'quantity' => 3,
    ]);

    $response->assertForbidden();
});

test('user can delete their supplement log', function (): void {
    $user = User::factory()->create();
    $log = SupplementLog::factory()->create(['user_id' => $user->id]);

    $response = actingAs($user)->deleteJson(route('api.v1.supplement-logs.destroy', $log));

    $response->assertNoContent();
    assertDatabaseMissing('supplement_logs', ['id' => $log->id]);
});

test('user cannot delete another users supplement log', function (): void {
    $user = User::factory()->create();
    $log = SupplementLog::factory()->create();

    $response = actingAs($user)->deleteJson(route('api.v1.supplement-logs.destroy', $log));

    $response->assertForbidden();
    assertDatabaseHas('supplement_logs', ['id' => $log->id]);
});
