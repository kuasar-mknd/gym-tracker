<?php

declare(strict_types=1);

use App\Models\Supplement;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Guest', function (): void {
    test('cannot list supplements', function (): void {
        getJson(route('api.v1.supplements.index'))->assertUnauthorized();
    });

    test('cannot create supplement', function (): void {
        postJson(route('api.v1.supplements.store'), [])->assertUnauthorized();
    });

    test('cannot view supplement', function (): void {
        $supplement = Supplement::factory()->create();
        getJson(route('api.v1.supplements.show', $supplement))->assertUnauthorized();
    });

    test('cannot update supplement', function (): void {
        $supplement = Supplement::factory()->create();
        putJson(route('api.v1.supplements.update', $supplement), [])->assertUnauthorized();
    });

    test('cannot delete supplement', function (): void {
        $supplement = Supplement::factory()->create();
        deleteJson(route('api.v1.supplements.destroy', $supplement))->assertUnauthorized();
    });
});

describe('Authenticated', function (): void {
    beforeEach(function (): void {
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    });

    describe('Index', function (): void {
        test('user can list their supplements', function (): void {
            Supplement::factory()->count(3)->create(['user_id' => $this->user->id]);
            Supplement::factory()->create(['user_id' => User::factory()->create()->id]); // Other user's supplement

            $response = getJson(route('api.v1.supplements.index'));

            $response->assertOk()
                ->assertJsonCount(3, 'data')
                ->assertJsonStructure([
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'brand',
                            'dosage',
                            'servings_remaining',
                            'low_stock_threshold',
                            'created_at',
                            'updated_at',
                        ],
                    ],
                    'links',
                    'meta',
                ]);
        });

        test('supplements are ordered by name asc by default', function (): void {
            $supplement1 = Supplement::factory()->create(['user_id' => $this->user->id, 'name' => 'B Supplement']);
            $supplement2 = Supplement::factory()->create(['user_id' => $this->user->id, 'name' => 'A Supplement']);

            $response = getJson(route('api.v1.supplements.index'));

            $response->assertJsonPath('data.0.id', $supplement2->id)
                ->assertJsonPath('data.1.id', $supplement1->id);
        });

        test('user can include latest log', function (): void {
            $supplement = Supplement::factory()->create(['user_id' => $this->user->id]);
            \App\Models\SupplementLog::create([
                'user_id' => $this->user->id,
                'supplement_id' => $supplement->id,
                'quantity' => 1,
                'consumed_at' => now()->subDay(),
            ]);

            $response = getJson(route('api.v1.supplements.index', ['include' => 'latestLog']));

            $response->assertOk()
                ->assertJsonPath('data.0.last_taken_at', fn ($val) => $val !== null);
        });
    });

    describe('Store', function (): void {
        test('user can create a supplement', function (): void {
            $data = [
                'name' => 'Creatine Monohydrate',
                'brand' => 'Optimum Nutrition',
                'dosage' => '5g',
                'servings_remaining' => 60,
                'low_stock_threshold' => 10,
            ];

            postJson(route('api.v1.supplements.store'), $data)
                ->assertCreated()
                ->assertJsonPath('data.name', $data['name'])
                ->assertJsonPath('data.brand', $data['brand'])
                ->assertJsonPath('data.dosage', $data['dosage'])
                ->assertJsonPath('data.servings_remaining', $data['servings_remaining'])
                ->assertJsonPath('data.low_stock_threshold', $data['low_stock_threshold']);

            assertDatabaseHas('supplements', [
                'user_id' => $this->user->id,
                'name' => $data['name'],
                'servings_remaining' => 60,
            ]);
        });

        test('validation: name is required', function (): void {
            postJson(route('api.v1.supplements.store'), [
                'servings_remaining' => 10,
                'low_stock_threshold' => 5,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['name']);
        });

        test('validation: servings_remaining is required and integer', function (): void {
            postJson(route('api.v1.supplements.store'), [
                'name' => 'Test',
                'low_stock_threshold' => 5,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['servings_remaining']);

            postJson(route('api.v1.supplements.store'), [
                'name' => 'Test',
                'servings_remaining' => 'invalid',
                'low_stock_threshold' => 5,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['servings_remaining']);
        });
    });

    describe('Show', function (): void {
        test('user can view their supplement', function (): void {
            $supplement = Supplement::factory()->create(['user_id' => $this->user->id]);

            getJson(route('api.v1.supplements.show', $supplement))
                ->assertOk()
                ->assertJsonPath('data.id', $supplement->id)
                ->assertJsonPath('data.name', $supplement->name);
        });

        test('user cannot view others supplement', function (): void {
            $otherUser = User::factory()->create();
            $supplement = Supplement::factory()->create(['user_id' => $otherUser->id]);

            getJson(route('api.v1.supplements.show', $supplement))
                ->assertForbidden();
        });

        test('returns 404 for non-existent supplement', function (): void {
            getJson(route('api.v1.supplements.show', 99999))
                ->assertNotFound();
        });
    });

    describe('Update', function (): void {
        test('user can update their supplement', function (): void {
            $supplement = Supplement::factory()->create([
                'user_id' => $this->user->id,
                'name' => 'Old Name',
                'servings_remaining' => 10,
            ]);

            $data = [
                'name' => 'New Name',
                'brand' => 'New Brand',
                'dosage' => '10g',
                'servings_remaining' => 20,
                'low_stock_threshold' => 5,
            ];

            putJson(route('api.v1.supplements.update', $supplement), $data)
                ->assertOk()
                ->assertJsonPath('data.name', 'New Name')
                ->assertJsonPath('data.servings_remaining', 20);

            assertDatabaseHas('supplements', [
                'id' => $supplement->id,
                'name' => 'New Name',
                'servings_remaining' => 20,
            ]);
        });

        test('user cannot update others supplement', function (): void {
            $otherUser = User::factory()->create();
            $supplement = Supplement::factory()->create(['user_id' => $otherUser->id]);

            putJson(route('api.v1.supplements.update', $supplement), [
                'name' => 'Hacked',
                'servings_remaining' => 100,
                'low_stock_threshold' => 10,
            ])
                ->assertForbidden();
        });

        test('validation: fields are validated on update', function (): void {
            $supplement = Supplement::factory()->create(['user_id' => $this->user->id]);

            putJson(route('api.v1.supplements.update', $supplement), [
                'name' => '', // Required
                'servings_remaining' => -1, // Min 0
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['name', 'servings_remaining']);
        });
    });

    describe('Destroy', function (): void {
        test('user can delete their supplement', function (): void {
            $supplement = Supplement::factory()->create(['user_id' => $this->user->id]);

            deleteJson(route('api.v1.supplements.destroy', $supplement))
                ->assertNoContent();

            assertDatabaseMissing('supplements', ['id' => $supplement->id]);
        });

        test('user cannot delete others supplement', function (): void {
            $otherUser = User::factory()->create();
            $supplement = Supplement::factory()->create(['user_id' => $otherUser->id]);

            deleteJson(route('api.v1.supplements.destroy', $supplement))
                ->assertForbidden();
        });
    });
});
