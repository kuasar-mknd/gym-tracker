<?php

use App\Models\User;
use App\Models\WaterLog;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

test('authenticated user can list water logs', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    WaterLog::factory()->count(3)->create(['user_id' => $user->id]);

    // Create logs for another user
    WaterLog::factory()->count(2)->create();

    $response = getJson(route('api.v1.water-logs.index'));

    $response->assertOk()
        ->assertJsonCount(3, 'data');
});

test('authenticated user can create water log', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $data = [
        'amount' => 500,
        'consumed_at' => now()->toDateTimeString(),
    ];

    $response = postJson(route('api.v1.water-logs.store'), $data);

    $response->assertCreated()
        ->assertJsonFragment(['amount' => 500]);

    $this->assertDatabaseHas('water_logs', [
        'user_id' => $user->id,
        'amount' => 500,
    ]);
});

test('create water log validation', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = postJson(route('api.v1.water-logs.store'), []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['amount', 'consumed_at']);

    $response = postJson(route('api.v1.water-logs.store'), [
        'amount' => 'invalid',
        'consumed_at' => 'not-a-date',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['amount', 'consumed_at']);

    $response = postJson(route('api.v1.water-logs.store'), [
        'amount' => 0, // Min 1
        'consumed_at' => now()->toDateTimeString(),
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['amount']);
});

test('authenticated user can show their water log', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $log = WaterLog::factory()->create(['user_id' => $user->id]);

    $response = getJson(route('api.v1.water-logs.show', $log));

    $response->assertOk()
        ->assertJsonFragment(['id' => $log->id]);
});

test('authenticated user cannot show other users water log', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $otherUser = User::factory()->create();
    $log = WaterLog::factory()->create(['user_id' => $otherUser->id]);

    $response = getJson(route('api.v1.water-logs.show', $log));

    $response->assertForbidden();
});

test('authenticated user can update their water log', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $log = WaterLog::factory()->create([
        'user_id' => $user->id,
        'amount' => 200,
    ]);

    $data = [
        'amount' => 1000,
    ];

    $response = putJson(route('api.v1.water-logs.update', $log), $data);

    $response->assertOk()
        ->assertJsonFragment(['amount' => 1000]);

    $this->assertDatabaseHas('water_logs', [
        'id' => $log->id,
        'amount' => 1000,
    ]);
});

test('authenticated user cannot update other users water log', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $otherUser = User::factory()->create();
    $log = WaterLog::factory()->create(['user_id' => $otherUser->id]);

    $data = [
        'amount' => 1000,
    ];

    $response = putJson(route('api.v1.water-logs.update', $log), $data);

    $response->assertForbidden();
});

test('update water log validation', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $log = WaterLog::factory()->create(['user_id' => $user->id]);

    $response = putJson(route('api.v1.water-logs.update', $log), [
        'amount' => 'invalid',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['amount']);
});

test('authenticated user can delete their water log', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $log = WaterLog::factory()->create(['user_id' => $user->id]);

    $response = deleteJson(route('api.v1.water-logs.destroy', $log));

    $response->assertNoContent();

    $this->assertDatabaseMissing('water_logs', ['id' => $log->id]);
});

test('authenticated user cannot delete other users water log', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $otherUser = User::factory()->create();
    $log = WaterLog::factory()->create(['user_id' => $otherUser->id]);

    $response = deleteJson(route('api.v1.water-logs.destroy', $log));

    $response->assertForbidden();

    $this->assertDatabaseHas('water_logs', ['id' => $log->id]);
});
