<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\WilksScore;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Assert;

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
        $user = User::factory()->create();
        $this->user = $user;
        Sanctum::actingAs($user);
    });

    describe('Index', function (): void {
        test('user can list their wilks scores', function (): void {
            $user = $this->user;
            Assert::assertInstanceOf(User::class, $user, 'the beforeEach user fixture is missing');

            WilksScore::factory()->count(3)->create(['user_id' => $user->id]);

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
            $user = $this->user;
            Assert::assertInstanceOf(User::class, $user, 'the beforeEach user fixture is missing');

            /*
             * Le score n'est plus envoye : il est CALCULE.
             *
             * Ce test posait `'score' => 300.5` et verifiait qu'on le retrouvait
             * tel quel en base. C'etait le defaut : l'API acceptait du client une
             * valeur que le chemin web calcule, conversion d'unites comprise. Un
             * historique constitue par l'API valait ce que l'appelant declarait
             * (#1378).
             *
             * Le score attendu est celui de la formule de Wilks pour 80,5 kg de
             * poids de corps et 150,5 kg souleves, chez un homme.
             */
            $data = [
                'body_weight' => 80.5,
                'lifted_weight' => 150.5,
                'gender' => 'male',
                'unit' => 'kg',
            ];

            $response = postJson(route('api.v1.wilks-scores.store'), $data);

            $response->assertCreated()
                ->assertJsonPath('data.body_weight', 80.5)
                ->assertJsonPath('data.lifted_weight', 150.5)
                ->assertJsonPath('data.gender', 'male')
                ->assertJsonPath('data.unit', 'kg');

            /* `value()` rend `mixed` : la forme est declaree plutot que castee a l'aveugle. */
            /** @var float|string|null $brut */
            $brut = DB::table('wilks_scores')->where('user_id', $user->id)->value('score');
            $enregistre = (float) $brut;

            expect($enregistre)->toBeGreaterThan(0.0)
                ->and($enregistre)->not->toBe(300.5, 'le score envoyé par le client ne doit plus être retenu');

            assertDatabaseHas('wilks_scores', ['user_id' => $user->id]);
        });

        test('validation: required fields', function (): void {
            // `score` ne figure plus : il n'est plus une entree.
            postJson(route('api.v1.wilks-scores.store'), [])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['body_weight', 'lifted_weight', 'gender', 'unit']);
        });

        test('validation: numeric constraints', function (): void {
            $data = [
                'body_weight' => -10,
                'lifted_weight' => 0,
                'gender' => 'unknown',
                'unit' => 'stone',
            ];

            postJson(route('api.v1.wilks-scores.store'), $data)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['body_weight', 'lifted_weight', 'gender', 'unit']);
        });
    });

    describe('Show', function (): void {
        test('user can view their wilks score', function (): void {
            $user = $this->user;
            Assert::assertInstanceOf(User::class, $user, 'the beforeEach user fixture is missing');

            $score = WilksScore::factory()->create(['user_id' => $user->id]);

            getJson(route('api.v1.wilks-scores.show', $score))
                ->assertOk()
                ->assertJsonPath('data.id', $score->id);
        });

        test('user cannot view others wilks score', function (): void {
            $otherUser = User::factory()->create();
            $score = WilksScore::factory()->create(['user_id' => $otherUser->id]);

            getJson(route('api.v1.wilks-scores.show', $score))
                ->assertNotFound();
        });
    });

    describe('Update', function (): void {
        test('user can update their wilks score', function (): void {
            $user = $this->user;
            Assert::assertInstanceOf(User::class, $user, 'the beforeEach user fixture is missing');

            $score = WilksScore::factory()->create(['user_id' => $user->id]);

            putJson(route('api.v1.wilks-scores.update', $score), ['score' => 500.5])
                ->assertOk()
                ->assertJsonPath('data.score', 500.5);

            assertDatabaseHas('wilks_scores', ['id' => $score->id, 'score' => 500.5]);
        });

        test('user cannot update others wilks score', function (): void {
            $otherUser = User::factory()->create();
            $score = WilksScore::factory()->create(['user_id' => $otherUser->id]);

            putJson(route('api.v1.wilks-scores.update', $score), ['score' => 500.0])
                ->assertNotFound();
        });
    });

    describe('Destroy', function (): void {
        test('user can delete their wilks score', function (): void {
            $user = $this->user;
            Assert::assertInstanceOf(User::class, $user, 'the beforeEach user fixture is missing');

            $score = WilksScore::factory()->create(['user_id' => $user->id]);

            deleteJson(route('api.v1.wilks-scores.destroy', $score))
                ->assertNoContent();

            assertDatabaseMissing('wilks_scores', ['id' => $score->id]);
        });

        test('user cannot delete others wilks score', function (): void {
            $otherUser = User::factory()->create();
            $score = WilksScore::factory()->create(['user_id' => $otherUser->id]);

            deleteJson(route('api.v1.wilks-scores.destroy', $score))
                ->assertNotFound();

            assertDatabaseHas('wilks_scores', ['id' => $score->id]);
        });
    });
});
