<?php

use App\Models\Fast;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

test('authenticated user can list fasts', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    Fast::factory()->count(3)->create(['user_id' => $user->id]);

    // Create fasts for another user
    Fast::factory()->count(2)->create();

    $response = getJson(route('api.v1.fasts.index'));

    $response->assertOk()
        ->assertJsonCount(3, 'data');
});

test('authenticated user can create fast', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $data = [
        'start_time' => now()->toDateTimeString(),
        'target_duration_minutes' => 960, // 16 hours
        'type' => '16:8',
    ];

    $response = postJson(route('api.v1.fasts.store'), $data);

    $response->assertCreated()
        ->assertJsonFragment([
            'target_duration_minutes' => 960,
            'type' => '16:8',
            'status' => 'active',
        ]);

    $this->assertDatabaseHas('fasts', [
        'user_id' => $user->id,
        'target_duration_minutes' => 960,
        'type' => '16:8',
        'status' => 'active',
    ]);
});

test('create fast validation', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = postJson(route('api.v1.fasts.store'), []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['start_time', 'target_duration_minutes', 'type']);

    $response = postJson(route('api.v1.fasts.store'), [
        'start_time' => 'not-a-date',
        'target_duration_minutes' => 'invalid',
        'type' => 'invalid_type',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['start_time', 'target_duration_minutes', 'type']);

    $response = postJson(route('api.v1.fasts.store'), [
        'start_time' => now()->toDateTimeString(),
        'target_duration_minutes' => 0, // Min 1
        'type' => '16:8',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['target_duration_minutes']);
});

test('create fast fails if active fast exists', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    Fast::factory()->create([
        'user_id' => $user->id,
        'status' => 'active',
    ]);

    $data = [
        'start_time' => now()->toDateTimeString(),
        'target_duration_minutes' => 960,
        'type' => '16:8',
    ];

    $response = postJson(route('api.v1.fasts.store'), $data);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['base']);
});

test('authenticated user can show their fast', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $fast = Fast::factory()->create(['user_id' => $user->id]);

    $response = getJson(route('api.v1.fasts.show', $fast));

    $response->assertOk()
        ->assertJsonFragment(['id' => $fast->id]);
});

test('authenticated user cannot show other users fast', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $otherUser = User::factory()->create();
    $fast = Fast::factory()->create(['user_id' => $otherUser->id]);

    $response = getJson(route('api.v1.fasts.show', $fast));

    $response->assertForbidden();
});

test('authenticated user can update their fast', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $fast = Fast::factory()->create([
        'user_id' => $user->id,
        'status' => 'active',
        'target_duration_minutes' => 960,
    ]);

    $data = [
        'target_duration_minutes' => 1200, // 20 hours
        'status' => 'completed',
    ];

    $response = putJson(route('api.v1.fasts.update', $fast), $data);

    $response->assertOk()
        ->assertJsonFragment([
            'target_duration_minutes' => 1200,
            'status' => 'completed',
        ]);

    $this->assertDatabaseHas('fasts', [
        'id' => $fast->id,
        'target_duration_minutes' => 1200,
        'status' => 'completed',
    ]);
});

test('authenticated user cannot update other users fast', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $otherUser = User::factory()->create();
    $fast = Fast::factory()->create([
        'user_id' => $otherUser->id,
        'target_duration_minutes' => 960,
    ]);

    $data = [
        'target_duration_minutes' => 1200,
    ];

    $response = putJson(route('api.v1.fasts.update', $fast), $data);

    $response->assertForbidden();
});

test('update fast validation', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $fast = Fast::factory()->create(['user_id' => $user->id]);

    $response = putJson(route('api.v1.fasts.update', $fast), [
        'status' => 'invalid_status',
        'target_duration_minutes' => 'invalid',
        'start_time' => 'invalid',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['status', 'target_duration_minutes', 'start_time']);
});

test('authenticated user can delete their fast', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $fast = Fast::factory()->create(['user_id' => $user->id]);

    $response = deleteJson(route('api.v1.fasts.destroy', $fast));

    $response->assertNoContent();

    $this->assertDatabaseMissing('fasts', ['id' => $fast->id]);
});

test('authenticated user cannot delete other users fast', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $otherUser = User::factory()->create();
    $fast = Fast::factory()->create(['user_id' => $otherUser->id]);

    $response = deleteJson(route('api.v1.fasts.destroy', $fast));

    $response->assertForbidden();

    $this->assertDatabaseHas('fasts', ['id' => $fast->id]);
});
