<?php

declare(strict_types=1);

use App\Models\Fast;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

test('can list fasts', function (): void {
    Fast::factory()->count(3)->create([
        'user_id' => $this->user->id,
        'status' => 'completed',
    ]);

    $response = $this->getJson(route('api.v1.fasts.index'));

    $response->assertOk()
        ->assertJsonCount(3, 'data');
});

test('can create a fast', function (): void {
    $data = [
        'start_time' => now()->subHours(2)->toDateTimeString(),
        'target_duration_minutes' => 960,
        'type' => '16:8',
    ];

    $response = $this->postJson(route('api.v1.fasts.store'), $data);

    $response->assertCreated()
        ->assertJsonFragment([
            'target_duration_minutes' => 960,
            'type' => '16:8',
            'status' => 'active',
        ]);

    $this->assertDatabaseHas('fasts', [
        'user_id' => $this->user->id,
        'target_duration_minutes' => 960,
        'type' => '16:8',
        'status' => 'active',
    ]);
});

test('cannot create a fast if an active one exists', function (): void {
    Fast::factory()->create([
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);

    $data = [
        'start_time' => now()->toDateTimeString(),
        'target_duration_minutes' => 960,
        'type' => '16:8',
    ];

    $response = $this->postJson(route('api.v1.fasts.store'), $data);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['base']);
});

test('cannot create a fast with invalid type', function (): void {
    $data = [
        'start_time' => now()->toDateTimeString(),
        'target_duration_minutes' => 960,
        'type' => 'invalid_type',
    ];

    $response = $this->postJson(route('api.v1.fasts.store'), $data);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['type']);
});

test('can show a fast', function (): void {
    $fast = Fast::factory()->create(['user_id' => $this->user->id]);

    $response = $this->getJson(route('api.v1.fasts.show', $fast));

    $response->assertOk()
        ->assertJsonFragment([
            'id' => $fast->id,
            'status' => $fast->status,
        ]);
});

test('can update a fast', function (): void {
    $fast = Fast::factory()->create([
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);

    $data = [
        'status' => 'completed',
        'end_time' => now()->toDateTimeString(),
    ];

    $response = $this->putJson(route('api.v1.fasts.update', $fast), $data);

    $response->assertOk()
        ->assertJsonFragment([
            'status' => 'completed',
        ]);

    $this->assertDatabaseHas('fasts', [
        'id' => $fast->id,
        'status' => 'completed',
    ]);
});

test('can delete a fast', function (): void {
    $fast = Fast::factory()->create(['user_id' => $this->user->id]);

    $response = $this->deleteJson(route('api.v1.fasts.destroy', $fast));

    $response->assertNoContent();

    $this->assertDatabaseMissing('fasts', ['id' => $fast->id]);
});

test('cannot view another users fast', function (): void {
    $otherUser = User::factory()->create();
    $fast = Fast::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->getJson(route('api.v1.fasts.show', $fast));

    $response->assertForbidden();
});

test('cannot update another users fast', function (): void {
    $otherUser = User::factory()->create();
    $fast = Fast::factory()->create(['user_id' => $otherUser->id]);

    $data = [
        'status' => 'completed',
    ];

    $response = $this->putJson(route('api.v1.fasts.update', $fast), $data);

    $response->assertForbidden();
});

test('cannot delete another users fast', function (): void {
    $otherUser = User::factory()->create();
    $fast = Fast::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->deleteJson(route('api.v1.fasts.destroy', $fast));

    $response->assertForbidden();
});
