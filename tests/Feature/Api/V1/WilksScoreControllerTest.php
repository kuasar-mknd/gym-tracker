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

    test('cannot create wilks score', function (): void {
        postJson(route('api.v1.wilks-scores.store'), [])->assertUnauthorized();
    });
});

describe('Authenticated', function (): void {
    beforeEach(function (): void {
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    });

    describe('Index', function (): void {
        test('user can list their wilks scores', function (): void {
            WilksScore::create([
                'user_id' => $this->user->id,
                'body_weight' => 80,
                'lifted_weight' => 400,
                'gender' => 'male',
                'unit' => 'kg',
                'score' => 273.00,
            ]);

            WilksScore::create([
                'user_id' => $this->user->id,
                'body_weight' => 85,
                'lifted_weight' => 420,
                'gender' => 'male',
                'unit' => 'kg',
                'score' => 275.00,
            ]);

            $response = getJson(route('api.v1.wilks-scores.index'));

            $response->assertOk()
                ->assertJsonCount(2, 'data')
                ->assertJsonStructure([
                    'data' => [
                        '*' => ['id', 'body_weight', 'lifted_weight', 'gender', 'unit', 'score', 'created_at'],
                    ],
                    'links',
                    'meta',
                ]);
        });

        test('user cannot see others wilks scores', function (): void {
            $otherUser = User::factory()->create();
            WilksScore::create([
                'user_id' => $otherUser->id,
                'body_weight' => 80,
                'lifted_weight' => 400,
                'gender' => 'male',
                'unit' => 'kg',
                'score' => 273.00,
            ]);

            $response = getJson(route('api.v1.wilks-scores.index'));

            $response->assertOk()
                ->assertJsonCount(0, 'data');
        });
    });

    describe('Store', function (): void {
        test('user can create a wilks score', function (): void {
            $data = [
                'body_weight' => 80,
                'lifted_weight' => 400,
                'gender' => 'male',
                'unit' => 'kg',
            ];

            $response = postJson(route('api.v1.wilks-scores.store'), $data);

            $response->assertCreated()
                ->assertJsonPath('data.body_weight', '80.00')
                ->assertJsonPath('data.lifted_weight', '400.00')
                ->assertJsonPath('data.score', '273.08'); // Calculated value

            assertDatabaseHas('wilks_scores', [
                'user_id' => $this->user->id,
                'body_weight' => 80,
                'lifted_weight' => 400,
            ]);
        });

        test('validation: required fields', function (): void {
            postJson(route('api.v1.wilks-scores.store'), [])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['body_weight', 'lifted_weight', 'gender', 'unit']);
        });

        test('validation: gender must be male or female', function (): void {
            postJson(route('api.v1.wilks-scores.store'), [
                'body_weight' => 80,
                'lifted_weight' => 400,
                'gender' => 'invalid',
                'unit' => 'kg',
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['gender']);
        });
    });

    describe('Show', function (): void {
        test('user can view their wilks score', function (): void {
            $score = WilksScore::create([
                'user_id' => $this->user->id,
                'body_weight' => 80,
                'lifted_weight' => 400,
                'gender' => 'male',
                'unit' => 'kg',
                'score' => 273.00,
            ]);

            getJson(route('api.v1.wilks-scores.show', $score))
                ->assertOk()
                ->assertJsonPath('data.id', $score->id);
        });

        test('user cannot view others wilks score', function (): void {
            $otherUser = User::factory()->create();
            $score = WilksScore::create([
                'user_id' => $otherUser->id,
                'body_weight' => 80,
                'lifted_weight' => 400,
                'gender' => 'male',
                'unit' => 'kg',
                'score' => 273.00,
            ]);

            getJson(route('api.v1.wilks-scores.show', $score))
                ->assertForbidden();
        });
    });

    describe('Update', function (): void {
        test('user can update their wilks score and it recalculates', function (): void {
            $score = WilksScore::create([
                'user_id' => $this->user->id,
                'body_weight' => 80,
                'lifted_weight' => 400,
                'gender' => 'male',
                'unit' => 'kg',
                'score' => 272.98,
            ]);

            // Update with more lifted weight
            $data = [
                'body_weight' => 80,
                'lifted_weight' => 410,
                'gender' => 'male',
                'unit' => 'kg',
            ];

            $response = putJson(route('api.v1.wilks-scores.update', $score), $data);

            $response->assertOk()
                ->assertJsonPath('data.lifted_weight', '410.00')
                ->assertJsonPath('data.score', '279.91');

            assertDatabaseHas('wilks_scores', [
                'id' => $score->id,
                'lifted_weight' => 410,
                'score' => 279.91,
            ]);
        });

        test('user cannot update others wilks score', function (): void {
            $otherUser = User::factory()->create();
            $score = WilksScore::create([
                'user_id' => $otherUser->id,
                'body_weight' => 80,
                'lifted_weight' => 400,
                'gender' => 'male',
                'unit' => 'kg',
                'score' => 273.00,
            ]);

            putJson(route('api.v1.wilks-scores.update', $score), [
                'body_weight' => 80,
                'lifted_weight' => 410,
                'gender' => 'male',
                'unit' => 'kg',
            ])->assertForbidden();
        });
    });

    describe('Destroy', function (): void {
        test('user can delete their wilks score', function (): void {
            $score = WilksScore::create([
                'user_id' => $this->user->id,
                'body_weight' => 80,
                'lifted_weight' => 400,
                'gender' => 'male',
                'unit' => 'kg',
                'score' => 273.00,
            ]);

            deleteJson(route('api.v1.wilks-scores.destroy', $score))
                ->assertNoContent();

            assertDatabaseMissing('wilks_scores', ['id' => $score->id]);
        });

        test('user cannot delete others wilks score', function (): void {
            $otherUser = User::factory()->create();
            $score = WilksScore::create([
                'user_id' => $otherUser->id,
                'body_weight' => 80,
                'lifted_weight' => 400,
                'gender' => 'male',
                'unit' => 'kg',
                'score' => 273.00,
            ]);

            deleteJson(route('api.v1.wilks-scores.destroy', $score))
                ->assertForbidden();

            assertDatabaseHas('wilks_scores', ['id' => $score->id]);
        });
    });
});
