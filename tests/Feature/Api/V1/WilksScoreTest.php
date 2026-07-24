<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\WilksScore;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Guest', function (): void {
    test('cannot list wilks scores', function (): void {
        getJson(route('api.v1.wilks-scores.index'))->assertUnauthorized();
    });
});

describe('Authenticated', function (): void {
    beforeEach(function (): void {
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    });

    describe('Index', function (): void {
        test('user can list their wilks scores', function (): void {
            WilksScore::factory()->count(3)->create(['user_id' => $this->user->id]);

            $response = getJson(route('api.v1.wilks-scores.index'));

            $response->assertOk()
                ->assertJsonCount(3, 'data')
                ->assertJsonStructure([
                    'data' => [
                        '*' => ['id', 'user_id', 'body_weight', 'lifted_weight', 'gender', 'unit', 'score', 'created_at'],
                    ],
                    'links',
                    'meta',
                ]);
        });

        test('user cannot see others wilks scores', function (): void {
            $otherUser = User::factory()->create();
            WilksScore::factory()->create(['user_id' => $otherUser->id]);

            $response = getJson(route('api.v1.wilks-scores.index'));

            $response->assertOk()
                ->assertJsonCount(0, 'data');
        });
    });

    describe('Store', function (): void {
        test('user can create a wilks score', function (): void {
            $data = [
                'body_weight' => 80.5,
                'lifted_weight' => 150.5,
                'gender' => 'male',
                'unit' => 'kg',
                'score' => 300.5,
            ];

            $response = postJson(route('api.v1.wilks-scores.store'), $data);

            $response->assertCreated()
                ->assertJsonPath('data.body_weight', 80.5)
                ->assertJsonPath('data.lifted_weight', 150.5)
                ->assertJsonPath('data.gender', 'male')
                ->assertJsonPath('data.unit', 'kg')
                ->assertJsonPath('data.score', 300.5);

            assertDatabaseHas('wilks_scores', [
                'user_id' => $this->user->id,
                'score' => 300.5,
            ]);
        });

        test('validation: required fields', function (): void {
            postJson(route('api.v1.wilks-scores.store'), [])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['body_weight', 'lifted_weight', 'gender', 'unit', 'score']);
        });

        test('validation: numeric constraints', function (): void {
            $data = [
                'body_weight' => -10,
                'lifted_weight' => 0,
                'gender' => 'unknown',
                'unit' => 'stone',
                'score' => 'not-a-number',
            ];

            postJson(route('api.v1.wilks-scores.store'), $data)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['body_weight', 'lifted_weight', 'gender', 'unit', 'score']);
        });
    });

    describe('Show', function (): void {
        test('user can view their wilks score', function (): void {
            $score = WilksScore::factory()->create(['user_id' => $this->user->id]);

            getJson(route('api.v1.wilks-scores.show', $score))
                ->assertOk()
                ->assertJsonPath('data.id', $score->id);
        });

        test('user cannot view others wilks score', function (): void {
            $otherUser = User::factory()->create();
            $score = WilksScore::factory()->create(['user_id' => $otherUser->id]);

            getJson(route('api.v1.wilks-scores.show', $score))
                ->assertForbidden();
        });
    });

    describe('Update', function (): void {
        test('user can update their wilks score', function (): void {
            $score = WilksScore::factory()->create(['user_id' => $this->user->id]);

            putJson(route('api.v1.wilks-scores.update', $score), ['score' => 500.5])
                ->assertOk()
                ->assertJsonPath('data.score', 500.5);

            assertDatabaseHas('wilks_scores', ['id' => $score->id, 'score' => 500.5]);
        });

        test('user cannot update others wilks score', function (): void {
            $otherUser = User::factory()->create();
            $score = WilksScore::factory()->create(['user_id' => $otherUser->id]);

            putJson(route('api.v1.wilks-scores.update', $score), ['score' => 500.0])
                ->assertForbidden();
        });
    });

    describe('Destroy', function (): void {
        test('user can delete their wilks score', function (): void {
            $score = WilksScore::factory()->create(['user_id' => $this->user->id]);

            deleteJson(route('api.v1.wilks-scores.destroy', $score))
                ->assertNoContent();

            assertDatabaseMissing('wilks_scores', ['id' => $score->id]);
        });

        test('user cannot delete others wilks score', function (): void {
            $otherUser = User::factory()->create();
            $score = WilksScore::factory()->create(['user_id' => $otherUser->id]);

            deleteJson(route('api.v1.wilks-scores.destroy', $score))
                ->assertForbidden();

            assertDatabaseHas('wilks_scores', ['id' => $score->id]);
        });
    });
});
