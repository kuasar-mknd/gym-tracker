<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\WilksScore;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

test('user can list their wilks scores', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    WilksScore::factory()->count(3)->create(['user_id' => $user->id]);
    WilksScore::factory()->count(2)->create(['user_id' => $otherUser->id]);

    $response = actingAs($user)->getJson('/api/v1/wilks-scores');

    $response->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'body_weight', 'lifted_weight', 'gender', 'unit', 'score', 'created_at'],
            ],
        ]);
});

test('user can view a specific wilks score', function (): void {
    $user = User::factory()->create();
    $score = WilksScore::factory()->create(['user_id' => $user->id]);

    $response = actingAs($user)->getJson("/api/v1/wilks-scores/{$score->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $score->id);
});

test('user cannot view another users wilks score', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $score = WilksScore::factory()->create(['user_id' => $otherUser->id]);

    $response = actingAs($user)->getJson("/api/v1/wilks-scores/{$score->id}");

    $response->assertForbidden();
});

test('user can create a wilks score', function (): void {
    $user = User::factory()->create();

    $payload = [
        'body_weight' => 80.5,
        'lifted_weight' => 200,
        'gender' => 'male',
        'unit' => 'kg',
        'score' => 150.55,
    ];

    $response = actingAs($user)->postJson('/api/v1/wilks-scores', $payload);

    $response->assertCreated()
        ->assertJsonPath('data.body_weight', 80.5)
        ->assertJsonPath('data.lifted_weight', 200)
        ->assertJsonPath('data.gender', 'male')
        ->assertJsonPath('data.unit', 'kg')
        ->assertJsonPath('data.score', 150.55);

    $this->assertDatabaseHas('wilks_scores', [
        'user_id' => $user->id,
        'body_weight' => 80.5,
        'lifted_weight' => 200.0,
        'gender' => 'male',
        'unit' => 'kg',
        'score' => 150.55,
    ]);
});

test('store requires valid payload', function (): void {
    $user = User::factory()->create();

    $response = actingAs($user)->postJson('/api/v1/wilks-scores', [
        'body_weight' => -10,
        'lifted_weight' => 0,
        'gender' => 'unknown',
        'unit' => 'invalid',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['body_weight', 'lifted_weight', 'gender', 'unit', 'score']);
});

test('user can update their wilks score', function (): void {
    $user = User::factory()->create();
    $score = WilksScore::factory()->create(['user_id' => $user->id]);

    $payload = [
        'body_weight' => 85,
        'lifted_weight' => 210,
    ];

    $response = actingAs($user)->patchJson("/api/v1/wilks-scores/{$score->id}", $payload);

    $response->assertOk()
        ->assertJsonPath('data.body_weight', 85)
        ->assertJsonPath('data.lifted_weight', 210);

    $this->assertDatabaseHas('wilks_scores', [
        'id' => $score->id,
        'body_weight' => 85.0,
        'lifted_weight' => 210.0,
    ]);
});

test('update requires valid payload', function (): void {
    $user = User::factory()->create();
    $score = WilksScore::factory()->create(['user_id' => $user->id]);

    $response = actingAs($user)->patchJson("/api/v1/wilks-scores/{$score->id}", [
        'body_weight' => -5,
        'gender' => 'alien',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['body_weight', 'gender']);
});

test('user cannot update another users wilks score', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $score = WilksScore::factory()->create(['user_id' => $otherUser->id]);

    $response = actingAs($user)->patchJson("/api/v1/wilks-scores/{$score->id}", [
        'body_weight' => 90,
    ]);

    $response->assertForbidden();
});

test('user can delete their wilks score', function (): void {
    $user = User::factory()->create();
    $score = WilksScore::factory()->create(['user_id' => $user->id]);

    $response = actingAs($user)->deleteJson("/api/v1/wilks-scores/{$score->id}");

    $response->assertNoContent();

    $this->assertDatabaseMissing('wilks_scores', ['id' => $score->id]);
});

test('user cannot delete another users wilks score', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $score = WilksScore::factory()->create(['user_id' => $otherUser->id]);

    $response = actingAs($user)->deleteJson("/api/v1/wilks-scores/{$score->id}");

    $response->assertForbidden();

    $this->assertDatabaseHas('wilks_scores', ['id' => $score->id]);
});
