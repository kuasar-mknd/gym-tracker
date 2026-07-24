<?php

use App\Models\Supplement;
use App\Models\SupplementLog;
use App\Models\User;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->supplement = Supplement::create([
        'user_id' => $this->user->id,
        'name' => 'Creatine',
        'brand' => 'ON',
        'dosage' => '5g',
        'servings_remaining' => 50,
        'low_stock_threshold' => 10,
    ]);
});

test('can list supplement logs', function (): void {
    SupplementLog::create([
        'user_id' => $this->user->id,
        'supplement_id' => $this->supplement->id,
        'quantity' => 1,
        'consumed_at' => now(),
    ]);

    $response = $this->getJson(route('api.v1.supplement-logs.index'));

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data');
});

test('can create a supplement log', function (): void {
    $data = [
        'supplement_id' => $this->supplement->id,
        'quantity' => 2,
        'consumed_at' => now()->toIso8601String(),
    ];

    $response = $this->postJson(route('api.v1.supplement-logs.store'), $data);

    $response->assertStatus(201)
        ->assertJsonFragment(['quantity' => 2]);

    $this->assertDatabaseHas('supplement_logs', [
        'user_id' => $this->user->id,
        'supplement_id' => $this->supplement->id,
        'quantity' => 2,
    ]);
});

test('can view a specific supplement log', function (): void {
    $log = SupplementLog::create([
        'user_id' => $this->user->id,
        'supplement_id' => $this->supplement->id,
        'quantity' => 1,
        'consumed_at' => now(),
    ]);

    $response = $this->getJson(route('api.v1.supplement-logs.show', $log));

    $response->assertStatus(200)
        ->assertJsonFragment(['id' => $log->id]);
});

test('cannot view another users supplement log', function (): void {
    $otherUser = User::factory()->create();
    $otherSupplement = Supplement::create([
        'user_id' => $otherUser->id,
        'name' => 'Whey',
        'brand' => 'ON',
        'dosage' => '30g',
        'servings_remaining' => 20,
        'low_stock_threshold' => 5,
    ]);

    $log = SupplementLog::create([
        'user_id' => $otherUser->id,
        'supplement_id' => $otherSupplement->id,
        'quantity' => 1,
        'consumed_at' => now(),
    ]);

    $response = $this->getJson(route('api.v1.supplement-logs.show', $log));

    $response->assertStatus(403);
});

test('can update a supplement log', function (): void {
    $log = SupplementLog::create([
        'user_id' => $this->user->id,
        'supplement_id' => $this->supplement->id,
        'quantity' => 1,
        'consumed_at' => now(),
    ]);

    $data = ['quantity' => 5];

    $response = $this->putJson(route('api.v1.supplement-logs.update', $log), $data);

    $response->assertStatus(200)
        ->assertJsonFragment(['quantity' => 5]);

    $this->assertDatabaseHas('supplement_logs', [
        'id' => $log->id,
        'quantity' => 5,
    ]);
});

test('cannot update another users supplement log', function (): void {
    $otherUser = User::factory()->create();
    $otherSupplement = Supplement::create([
        'user_id' => $otherUser->id,
        'name' => 'Whey',
        'brand' => 'ON',
        'dosage' => '30g',
        'servings_remaining' => 20,
        'low_stock_threshold' => 5,
    ]);

    $log = SupplementLog::create([
        'user_id' => $otherUser->id,
        'supplement_id' => $otherSupplement->id,
        'quantity' => 1,
        'consumed_at' => now(),
    ]);

    $data = ['quantity' => 5];

    $response = $this->putJson(route('api.v1.supplement-logs.update', $log), $data);

    $response->assertStatus(403);
});

test('can delete a supplement log', function (): void {
    $log = SupplementLog::create([
        'user_id' => $this->user->id,
        'supplement_id' => $this->supplement->id,
        'quantity' => 1,
        'consumed_at' => now(),
    ]);

    $response = $this->deleteJson(route('api.v1.supplement-logs.destroy', $log));

    $response->assertStatus(204);

    $this->assertDatabaseMissing('supplement_logs', ['id' => $log->id]);
});

test('cannot delete another users supplement log', function (): void {
    $otherUser = User::factory()->create();
    $otherSupplement = Supplement::create([
        'user_id' => $otherUser->id,
        'name' => 'Whey',
        'brand' => 'ON',
        'dosage' => '30g',
        'servings_remaining' => 20,
        'low_stock_threshold' => 5,
    ]);

    $log = SupplementLog::create([
        'user_id' => $otherUser->id,
        'supplement_id' => $otherSupplement->id,
        'quantity' => 1,
        'consumed_at' => now(),
    ]);

    $response = $this->deleteJson(route('api.v1.supplement-logs.destroy', $log));

    $response->assertStatus(403);
});
