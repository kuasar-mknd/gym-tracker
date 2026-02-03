<?php

use App\Models\User;
use App\Models\WilksScore;

test('authenticated user can list their wilks scores', function () {
    $user = User::factory()->create();
    WilksScore::factory()->count(3)->create(['user_id' => $user->id]);

    // Create another user's score to ensure isolation
    WilksScore::factory()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/wilks-scores');

    $response->assertStatus(200)
        ->assertJsonCount(3, 'data');
});

test('authenticated user can create a wilks score', function () {
    $user = User::factory()->create();

    $data = [
        'body_weight' => 80,
        'lifted_weight' => 100,
        'gender' => 'male',
        'unit' => 'kg',
    ];

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/wilks-scores', $data);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'data' => [
                'id',
                'body_weight',
                'lifted_weight',
                'gender',
                'unit',
                'score',
            ],
        ]);

    $this->assertDatabaseHas('wilks_scores', [
        'user_id' => $user->id,
        'body_weight' => 80,
    ]);
});

test('authenticated user can view their wilks score', function () {
    $user = User::factory()->create();
    $score = WilksScore::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/wilks-scores/{$score->id}");

    $response->assertStatus(200)
        ->assertJsonPath('data.id', $score->id);
});

test('authenticated user cannot view others wilks score', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $score = WilksScore::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/wilks-scores/{$score->id}");

    $response->assertStatus(403);
});

test('authenticated user can update their wilks score', function () {
    $user = User::factory()->create();
    $score = WilksScore::factory()->create(['user_id' => $user->id]);

    $data = [
        'body_weight' => 85,
        'lifted_weight' => 110,
        'gender' => 'male',
        'unit' => 'kg',
    ];

    $response = $this->actingAs($user, 'sanctum')
        ->putJson("/api/v1/wilks-scores/{$score->id}", $data);

    $response->assertStatus(200)
        ->assertJsonPath('data.body_weight', 85)
        ->assertJsonPath('data.lifted_weight', 110);
});

test('authenticated user can delete their wilks score', function () {
    $user = User::factory()->create();
    $score = WilksScore::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/v1/wilks-scores/{$score->id}");

    $response->assertStatus(204);
    $this->assertDatabaseMissing('wilks_scores', ['id' => $score->id]);
});
