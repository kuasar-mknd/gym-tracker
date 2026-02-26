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

describe('Authenticated User', function (): void {
    beforeEach(function (): void {
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    });

    test('user can list their supplements', function (): void {
        Supplement::factory()->count(3)->create(['user_id' => $this->user->id]);
        Supplement::factory()->count(2)->create(); // Other users' supplements

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
            ]);
    });

    test('user can create a supplement', function (): void {
        $data = [
            'name' => 'Creatine Monohydrate',
            'brand' => 'MyProtein',
            'dosage' => '5g',
            'servings_remaining' => 50,
            'low_stock_threshold' => 5,
        ];

        $response = postJson(route('api.v1.supplements.store'), $data);

        $response->assertCreated()
            ->assertJsonFragment([
                'name' => 'Creatine Monohydrate',
                'brand' => 'MyProtein',
                'servings_remaining' => 50,
            ]);

        assertDatabaseHas('supplements', [
            'user_id' => $this->user->id,
            'name' => 'Creatine Monohydrate',
        ]);
    });

    test('user can view a specific supplement', function (): void {
        $supplement = Supplement::factory()->create(['user_id' => $this->user->id]);

        $response = getJson(route('api.v1.supplements.show', $supplement));

        $response->assertOk()
            ->assertJsonFragment([
                'id' => $supplement->id,
                'name' => $supplement->name,
            ]);
    });

    test('user cannot view another user\'s supplement', function (): void {
        $otherUser = User::factory()->create();
        $supplement = Supplement::factory()->create(['user_id' => $otherUser->id]);

        $response = getJson(route('api.v1.supplements.show', $supplement));

        $response->assertForbidden();
    });

    test('user can update a supplement', function (): void {
        $supplement = Supplement::factory()->create(['user_id' => $this->user->id]);
        $updateData = [
            'name' => 'Updated Name',
            'brand' => 'Updated Brand',
            'dosage' => '10g',
            'servings_remaining' => 100,
            'low_stock_threshold' => 10,
        ];

        $response = putJson(route('api.v1.supplements.update', $supplement), $updateData);

        $response->assertOk()
            ->assertJsonFragment([
                'name' => 'Updated Name',
                'servings_remaining' => 100,
            ]);

        assertDatabaseHas('supplements', [
            'id' => $supplement->id,
            'name' => 'Updated Name',
        ]);
    });

    test('user cannot update another user\'s supplement', function (): void {
        $otherUser = User::factory()->create();
        $supplement = Supplement::factory()->create(['user_id' => $otherUser->id]);
        $updateData = [
            'name' => 'Hacked Name',
            'brand' => 'Hacked Brand',
            'dosage' => '10g',
            'servings_remaining' => 100,
            'low_stock_threshold' => 10,
        ];

        $response = putJson(route('api.v1.supplements.update', $supplement), $updateData);

        $response->assertForbidden();

        assertDatabaseHas('supplements', [
            'id' => $supplement->id,
            'name' => $supplement->name,
        ]);
    });

    test('user can delete a supplement', function (): void {
        $supplement = Supplement::factory()->create(['user_id' => $this->user->id]);

        $response = deleteJson(route('api.v1.supplements.destroy', $supplement));

        $response->assertNoContent();

        assertDatabaseMissing('supplements', [
            'id' => $supplement->id,
        ]);
    });

    test('user cannot delete another user\'s supplement', function (): void {
        $otherUser = User::factory()->create();
        $supplement = Supplement::factory()->create(['user_id' => $otherUser->id]);

        $response = deleteJson(route('api.v1.supplements.destroy', $supplement));

        $response->assertForbidden();

        assertDatabaseHas('supplements', [
            'id' => $supplement->id,
        ]);
    });

    test('validation errors for creating supplement', function (): void {
        $response = postJson(route('api.v1.supplements.store'), []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'servings_remaining', 'low_stock_threshold']);
    });

    test('validation errors for updating supplement', function (): void {
        $supplement = Supplement::factory()->create(['user_id' => $this->user->id]);

        $response = putJson(route('api.v1.supplements.update', $supplement), [
            'name' => '', // Invalid
            'servings_remaining' => 'not-a-number', // Invalid
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'servings_remaining']);
    });
});

describe('Unauthenticated User', function (): void {
    test('guest cannot list supplements', function (): void {
        $response = getJson(route('api.v1.supplements.index'));
        $response->assertUnauthorized();
    });

    test('guest cannot create a supplement', function (): void {
        $response = postJson(route('api.v1.supplements.store'), [
            'name' => 'Test',
            'servings_remaining' => 10,
            'low_stock_threshold' => 1,
        ]);
        $response->assertUnauthorized();
    });

    test('guest cannot view a supplement', function (): void {
        $supplement = Supplement::factory()->create();
        $response = getJson(route('api.v1.supplements.show', $supplement));
        $response->assertUnauthorized();
    });

    test('guest cannot update a supplement', function (): void {
        $supplement = Supplement::factory()->create();
        $response = putJson(route('api.v1.supplements.update', $supplement), [
            'name' => 'Updated',
            'servings_remaining' => 10,
            'low_stock_threshold' => 1,
        ]);
        $response->assertUnauthorized();
    });

    test('guest cannot delete a supplement', function (): void {
        $supplement = Supplement::factory()->create();
        $response = deleteJson(route('api.v1.supplements.destroy', $supplement));
        $response->assertUnauthorized();
    });
});
