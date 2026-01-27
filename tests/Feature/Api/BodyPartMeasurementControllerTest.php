<?php

declare(strict_types=1);

use App\Models\BodyPartMeasurement;
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

    test('user can list their body part measurements', function (): void {
        BodyPartMeasurement::factory()->count(3)->create([
            'user_id' => $this->user->id,
        ]);

        // Create measurement for another user
        BodyPartMeasurement::factory()->create();

        $response = getJson(route('api.v1.body-part-measurements.index'));

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'part',
                        'value',
                        'unit',
                        'measured_at',
                        'notes',
                    ],
                ],
                'links',
                'meta',
            ]);
    });

    test('user cannot see other users measurements in index', function (): void {
        $otherUser = User::factory()->create();
        BodyPartMeasurement::factory()->create(['user_id' => $otherUser->id]);

        $response = getJson(route('api.v1.body-part-measurements.index'));

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    });

    test('user can create a measurement', function (): void {
        $data = [
            'part' => 'Biceps R',
            'value' => 35.5,
            'unit' => 'cm',
            'measured_at' => now()->toDateString(),
            'notes' => 'After workout',
        ];

        $response = postJson(route('api.v1.body-part-measurements.store'), $data);

        $response->assertCreated()
            ->assertJsonFragment([
                'part' => 'Biceps R',
                'value' => '35.50',
            ]);

        assertDatabaseHas('body_part_measurements', [
            'user_id' => $this->user->id,
            'part' => 'Biceps R',
            'value' => 35.5,
        ]);
    });

    test('user cannot create measurement with invalid data', function (): void {
        $response = postJson(route('api.v1.body-part-measurements.store'), [
            'part' => '', // Required
            'value' => 'not-a-number', // Numeric
            'unit' => 'invalid', // in:cm,in
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['part', 'value', 'unit']);
    });

    test('user can view their own measurement', function (): void {
        $measurement = BodyPartMeasurement::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $response = getJson(route('api.v1.body-part-measurements.show', $measurement));

        $response->assertOk()
            ->assertJsonFragment([
                'id' => $measurement->id,
                'part' => $measurement->part,
            ]);
    });

    test('user cannot view others measurement', function (): void {
        $otherUser = User::factory()->create();
        $measurement = BodyPartMeasurement::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        $response = getJson(route('api.v1.body-part-measurements.show', $measurement));

        $response->assertForbidden();
    });

    test('user can update their measurement', function (): void {
        $measurement = BodyPartMeasurement::factory()->create([
            'user_id' => $this->user->id,
            'value' => 30.0,
        ]);

        $response = putJson(route('api.v1.body-part-measurements.update', $measurement), [
            'part' => $measurement->part,
            'value' => 32.5,
            'unit' => $measurement->unit,
            'measured_at' => $measurement->measured_at->format('Y-m-d'),
        ]);

        $response->assertOk()
            ->assertJsonFragment([
                'value' => '32.50',
            ]);

        assertDatabaseHas('body_part_measurements', [
            'id' => $measurement->id,
            'value' => 32.5,
        ]);
    });

    test('user cannot update others measurement', function (): void {
        $otherUser = User::factory()->create();
        $measurement = BodyPartMeasurement::factory()->create([
            'user_id' => $otherUser->id,
            'value' => 30.0,
        ]);

        $response = putJson(route('api.v1.body-part-measurements.update', $measurement), [
            'part' => $measurement->part,
            'value' => 32.5,
            'unit' => $measurement->unit,
            'measured_at' => $measurement->measured_at,
        ]);

        $response->assertForbidden();
    });

    test('user can delete their measurement', function (): void {
        $measurement = BodyPartMeasurement::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $response = deleteJson(route('api.v1.body-part-measurements.destroy', $measurement));

        $response->assertNoContent();

        assertDatabaseMissing('body_part_measurements', ['id' => $measurement->id]);
    });

    test('user cannot delete others measurement', function (): void {
        $otherUser = User::factory()->create();
        $measurement = BodyPartMeasurement::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        $response = deleteJson(route('api.v1.body-part-measurements.destroy', $measurement));

        $response->assertForbidden();

        assertDatabaseHas('body_part_measurements', ['id' => $measurement->id]);
    });
});

describe('Unauthenticated User', function (): void {
    test('guest cannot list measurements', function (): void {
        $response = getJson(route('api.v1.body-part-measurements.index'));
        $response->assertUnauthorized();
    });

    test('guest cannot create measurement', function (): void {
        $response = postJson(route('api.v1.body-part-measurements.store'), []);
        $response->assertUnauthorized();
    });

    test('guest cannot view measurement', function (): void {
        $measurement = BodyPartMeasurement::factory()->create();
        $response = getJson(route('api.v1.body-part-measurements.show', $measurement));
        $response->assertUnauthorized();
    });

    test('guest cannot update measurement', function (): void {
        $measurement = BodyPartMeasurement::factory()->create();
        $response = putJson(route('api.v1.body-part-measurements.update', $measurement), []);
        $response->assertUnauthorized();
    });

    test('guest cannot delete measurement', function (): void {
        $measurement = BodyPartMeasurement::factory()->create();
        $response = deleteJson(route('api.v1.body-part-measurements.destroy', $measurement));
        $response->assertUnauthorized();
    });
});
