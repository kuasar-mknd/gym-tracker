<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Fast;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

it('can list fasts', function (): void {
    Fast::factory()->count(3)->create([
        'user_id' => $this->user->id,
    ]);

    $response = $this->getJson(route('api.v1.fasts.index'));

    $response->assertOk()
        ->assertJsonCount(3, 'data');
});

it('cannot list another users fasts', function (): void {
    $otherUser = User::factory()->create();
    Fast::factory()->count(2)->create([
        'user_id' => $otherUser->id,
    ]);

    $response = $this->getJson(route('api.v1.fasts.index'));

    $response->assertOk()
        ->assertJsonCount(0, 'data');
});

it('can store a fast', function (): void {
    $data = [
        'start_time' => now()->subHours(5)->toDateTimeString(),
        'target_duration_minutes' => 960,
        'type' => '16:8',
    ];

    $response = $this->postJson(route('api.v1.fasts.store'), $data);

    $response->assertCreated()
        ->assertJsonPath('data.target_duration_minutes', 960)
        ->assertJsonPath('data.type', '16:8')
        ->assertJsonPath('data.status', 'active');

    $this->assertDatabaseHas('fasts', [
        'user_id' => $this->user->id,
        'target_duration_minutes' => 960,
        'type' => '16:8',
        'status' => 'active',
    ]);
});

it('cannot store a fast with invalid data', function (): void {
    $data = [
        'start_time' => 'invalid-date',
        'target_duration_minutes' => -10,
        'type' => 'invalid-type',
    ];

    $response = $this->postJson(route('api.v1.fasts.store'), $data);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['start_time', 'target_duration_minutes', 'type']);
});

it('cannot store a fast if one is already active', function (): void {
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

it('can show a fast', function (): void {
    $fast = Fast::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $response = $this->getJson(route('api.v1.fasts.show', $fast));

    $response->assertOk()
        ->assertJsonPath('data.id', $fast->id);
});

it('cannot show another users fast', function (): void {
    $otherUser = User::factory()->create();
    $fast = Fast::factory()->create([
        'user_id' => $otherUser->id,
    ]);

    $response = $this->getJson(route('api.v1.fasts.show', $fast));

    $response->assertForbidden();
});

it('can update a fast', function (): void {
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
        ->assertJsonPath('data.status', 'completed');

    $this->assertDatabaseHas('fasts', [
        'id' => $fast->id,
        'status' => 'completed',
    ]);
});

it('cannot update a fast with invalid data', function (): void {
    $fast = Fast::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $data = [
        'status' => 'invalid-status',
        'target_duration_minutes' => -5,
    ];

    $response = $this->putJson(route('api.v1.fasts.update', $fast), $data);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['status', 'target_duration_minutes']);
});

it('cannot update another users fast', function (): void {
    $otherUser = User::factory()->create();
    $fast = Fast::factory()->create([
        'user_id' => $otherUser->id,
    ]);

    $data = [
        'status' => 'completed',
    ];

    $response = $this->putJson(route('api.v1.fasts.update', $fast), $data);

    $response->assertForbidden();
});

it('can destroy a fast', function (): void {
    $fast = Fast::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $response = $this->deleteJson(route('api.v1.fasts.destroy', $fast));

    $response->assertNoContent();

    $this->assertDatabaseMissing('fasts', [
        'id' => $fast->id,
    ]);
});

it('cannot destroy another users fast', function (): void {
    $otherUser = User::factory()->create();
    $fast = Fast::factory()->create([
        'user_id' => $otherUser->id,
    ]);

    $response = $this->deleteJson(route('api.v1.fasts.destroy', $fast));

    $response->assertForbidden();
});
