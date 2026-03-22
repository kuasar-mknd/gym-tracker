<?php

declare(strict_types=1);

use App\Models\Supplement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('SupplementController API', function () {
    describe('GET /api/v1/supplements', function () {
        it('requires authentication', function () {
            $response = $this->getJson(route('api.v1.supplements.index'));
            $response->assertUnauthorized();
        });

        it('returns user supplements', function () {
            $user = User::factory()->create();
            $supplement = Supplement::factory()->create(['user_id' => $user->id]);
            $otherUserSupplement = Supplement::factory()->create();

            $response = $this->actingAs($user)->getJson(route('api.v1.supplements.index'));

            $response->assertOk()
                ->assertJsonPath('meta.total', 1)
                ->assertJsonPath('data.0.id', $supplement->id)
                ->assertJsonMissing(['id' => $otherUserSupplement->id]);
        });
    });

    describe('POST /api/v1/supplements', function () {
        it('requires authentication', function () {
            $response = $this->postJson(route('api.v1.supplements.store'), []);
            $response->assertUnauthorized();
        });

        it('validates required fields', function () {
            $user = User::factory()->create();

            $response = $this->actingAs($user)->postJson(route('api.v1.supplements.store'), []);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['name', 'servings_remaining', 'low_stock_threshold']);
        });

        it('creates a supplement', function () {
            $user = User::factory()->create();
            $data = [
                'name' => 'Creatine',
                'brand' => 'Optimum Nutrition',
                'dosage' => '5g',
                'servings_remaining' => 60,
                'low_stock_threshold' => 10,
            ];

            $response = $this->actingAs($user)->postJson(route('api.v1.supplements.store'), $data);

            $response->assertCreated()
                ->assertJsonPath('data.name', 'Creatine');

            $this->assertDatabaseHas('supplements', [
                'user_id' => $user->id,
                'name' => 'Creatine',
                'brand' => 'Optimum Nutrition',
                'dosage' => '5g',
                'servings_remaining' => 60,
                'low_stock_threshold' => 10,
            ]);
        });
    });

    describe('GET /api/v1/supplements/{supplement}', function () {
        it('requires authentication', function () {
            $supplement = Supplement::factory()->create();
            $response = $this->getJson(route('api.v1.supplements.show', $supplement));
            $response->assertUnauthorized();
        });

        it('prevents accessing other users supplements', function () {
            $user = User::factory()->create();
            $otherUserSupplement = Supplement::factory()->create();

            $response = $this->actingAs($user)->getJson(route('api.v1.supplements.show', $otherUserSupplement));

            $response->assertForbidden();
        });

        it('shows a supplement', function () {
            $user = User::factory()->create();
            $supplement = Supplement::factory()->create(['user_id' => $user->id]);

            $response = $this->actingAs($user)->getJson(route('api.v1.supplements.show', $supplement));

            $response->assertOk()
                ->assertJsonPath('data.id', $supplement->id)
                ->assertJsonPath('data.name', $supplement->name);
        });
    });

    describe('PUT /api/v1/supplements/{supplement}', function () {
        it('requires authentication', function () {
            $supplement = Supplement::factory()->create();
            $response = $this->putJson(route('api.v1.supplements.update', $supplement), []);
            $response->assertUnauthorized();
        });

        it('prevents updating other users supplements', function () {
            $user = User::factory()->create();
            $otherUserSupplement = Supplement::factory()->create();

            $response = $this->actingAs($user)->putJson(route('api.v1.supplements.update', $otherUserSupplement), [
                'name' => 'Updated Name',
                'servings_remaining' => 10,
                'low_stock_threshold' => 5,
            ]);

            $response->assertForbidden();
        });

        it('validates required fields on update', function () {
            $user = User::factory()->create();
            $supplement = Supplement::factory()->create(['user_id' => $user->id]);

            $response = $this->actingAs($user)->putJson(route('api.v1.supplements.update', $supplement), []);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['name', 'servings_remaining', 'low_stock_threshold']);
        });

        it('updates a supplement', function () {
            $user = User::factory()->create();
            $supplement = Supplement::factory()->create(['user_id' => $user->id]);
            $data = [
                'name' => 'Updated Creatine',
                'brand' => 'New Brand',
                'dosage' => '10g',
                'servings_remaining' => 30,
                'low_stock_threshold' => 5,
            ];

            $response = $this->actingAs($user)->putJson(route('api.v1.supplements.update', $supplement), $data);

            $response->assertOk()
                ->assertJsonPath('data.name', 'Updated Creatine');

            $this->assertDatabaseHas('supplements', [
                'id' => $supplement->id,
                'name' => 'Updated Creatine',
                'brand' => 'New Brand',
                'dosage' => '10g',
                'servings_remaining' => 30,
                'low_stock_threshold' => 5,
            ]);
        });
    });

    describe('DELETE /api/v1/supplements/{supplement}', function () {
        it('requires authentication', function () {
            $supplement = Supplement::factory()->create();
            $response = $this->deleteJson(route('api.v1.supplements.destroy', $supplement));
            $response->assertUnauthorized();
        });

        it('prevents deleting other users supplements', function () {
            $user = User::factory()->create();
            $otherUserSupplement = Supplement::factory()->create();

            $response = $this->actingAs($user)->deleteJson(route('api.v1.supplements.destroy', $otherUserSupplement));

            $response->assertForbidden();
            $this->assertDatabaseHas('supplements', ['id' => $otherUserSupplement->id]);
        });

        it('deletes a supplement', function () {
            $user = User::factory()->create();
            $supplement = Supplement::factory()->create(['user_id' => $user->id]);

            $response = $this->actingAs($user)->deleteJson(route('api.v1.supplements.destroy', $supplement));

            $response->assertNoContent();
            $this->assertDatabaseMissing('supplements', ['id' => $supplement->id]);
        });
    });
});
