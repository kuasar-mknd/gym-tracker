<?php

use App\Models\User;
use App\Models\WaterLog;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('can list water logs', function () {
    WaterLog::factory()->count(3)->create(['user_id' => $this->user->id]);

    $response = $this->getJson(route('api.v1.water-logs.index'));

    $response->assertStatus(200)
        ->assertJsonCount(3, 'data');
});

test('can create a water log', function () {
    $data = [
        'amount' => 500,
        'consumed_at' => now()->toIso8601String(),
    ];

    $response = $this->postJson(route('api.v1.water-logs.store'), $data);

    $response->assertStatus(201)
        ->assertJsonFragment(['amount' => 500]);

    $this->assertDatabaseHas('water_logs', [
        'user_id' => $this->user->id,
        'amount' => 500,
    ]);
});

test('can view a specific water log', function () {
    $log = WaterLog::factory()->create(['user_id' => $this->user->id]);

    $response = $this->getJson(route('api.v1.water-logs.show', $log));

    $response->assertStatus(200)
        ->assertJsonFragment(['id' => $log->id]);
});

test('cannot view another users water log', function () {
    $otherUser = User::factory()->create();
    $log = WaterLog::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->getJson(route('api.v1.water-logs.show', $log));

    $response->assertStatus(403);
});

test('can update a water log', function () {
    $log = WaterLog::factory()->create(['user_id' => $this->user->id]);
    $data = ['amount' => 750];

    $response = $this->putJson(route('api.v1.water-logs.update', $log), $data);

    $response->assertStatus(200)
        ->assertJsonFragment(['amount' => 750]);

    $this->assertDatabaseHas('water_logs', [
        'id' => $log->id,
        'amount' => 750,
    ]);
});

test('cannot update another users water log', function () {
    $otherUser = User::factory()->create();
    $log = WaterLog::factory()->create(['user_id' => $otherUser->id]);
    $data = ['amount' => 750];

    $response = $this->putJson(route('api.v1.water-logs.update', $log), $data);

    $response->assertStatus(403);
});

test('can delete a water log', function () {
    $log = WaterLog::factory()->create(['user_id' => $this->user->id]);

    $response = $this->deleteJson(route('api.v1.water-logs.destroy', $log));

    $response->assertStatus(204);

    $this->assertDatabaseMissing('water_logs', ['id' => $log->id]);
});

test('cannot delete another users water log', function () {
    $otherUser = User::factory()->create();
    $log = WaterLog::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->deleteJson(route('api.v1.water-logs.destroy', $log));

    $response->assertStatus(403);
});
