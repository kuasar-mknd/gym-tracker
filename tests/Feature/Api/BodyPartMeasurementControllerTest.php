<?php

declare(strict_types=1);

use App\Models\BodyPartMeasurement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('index returns paginated list of body part measurements', function (): void {
    $user = User::factory()->create();
    BodyPartMeasurement::factory()->count(3)->create(['user_id' => $user->id]);

    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.v1.body-part-measurements.index'));

    $response->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'user_id', 'part', 'value', 'unit', 'measured_at', 'notes', 'created_at', 'updated_at'],
            ],
            'links',
            'meta',
        ]);
});

test('index filters by part', function (): void {
    $user = User::factory()->create();
    BodyPartMeasurement::factory()->create(['user_id' => $user->id, 'part' => 'Chest']);
    BodyPartMeasurement::factory()->create(['user_id' => $user->id, 'part' => 'Chest']);
    BodyPartMeasurement::factory()->create(['user_id' => $user->id, 'part' => 'Biceps']);

    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.v1.body-part-measurements.index', ['filter[part]' => 'Chest']));

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

test('index only shows authenticated user measurements', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    BodyPartMeasurement::factory()->count(2)->create(['user_id' => $user->id]);
    BodyPartMeasurement::factory()->count(3)->create(['user_id' => $otherUser->id]);

    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.v1.body-part-measurements.index'));

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

test('unauthenticated user cannot list measurements', function (): void {
    $response = $this->getJson(route('api.v1.body-part-measurements.index'));

    $response->assertUnauthorized();
});

test('store creates new measurement', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $data = [
        'part' => 'Chest',
        'value' => 100.5,
        'unit' => 'cm',
        'measured_at' => now()->format('Y-m-d'),
        'notes' => 'Looking good',
    ];

    $response = $this->postJson(route('api.v1.body-part-measurements.store'), $data);

    $response->assertCreated()
        ->assertJsonFragment([
            'part' => 'Chest',
            'value' => 100.5,
            'unit' => 'cm',
            'notes' => 'Looking good',
        ]);

    $this->assertDatabaseHas('body_part_measurements', [
        'user_id' => $user->id,
        'part' => 'Chest',
        'value' => 100.5,
        'unit' => 'cm',
        'notes' => 'Looking good',
    ]);
});

test('store validation checks', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // Missing required
    $response = $this->postJson(route('api.v1.body-part-measurements.store'), []);
    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['part', 'value', 'unit', 'measured_at']);

    // Invalid value
    $response = $this->postJson(route('api.v1.body-part-measurements.store'), [
        'part' => 'Chest',
        'value' => -5,
        'unit' => 'cm',
        'measured_at' => now()->format('Y-m-d'),
    ]);
    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['value']);

    // Invalid unit
    $response = $this->postJson(route('api.v1.body-part-measurements.store'), [
        'part' => 'Chest',
        'value' => 100,
        'unit' => 'invalid',
        'measured_at' => now()->format('Y-m-d'),
    ]);
    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['unit']);

    // Future date
    $response = $this->postJson(route('api.v1.body-part-measurements.store'), [
        'part' => 'Chest',
        'value' => 100,
        'unit' => 'cm',
        'measured_at' => now()->addDay()->format('Y-m-d'),
    ]);
    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['measured_at']);
});

test('show returns measurement details', function (): void {
    $user = User::factory()->create();
    $measurement = BodyPartMeasurement::factory()->create(['user_id' => $user->id]);
    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.v1.body-part-measurements.show', $measurement));

    $response->assertOk()
        ->assertJsonFragment(['id' => $measurement->id, 'part' => $measurement->part]);
});

test('show returns 403 for other user measurement', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $measurement = BodyPartMeasurement::factory()->create(['user_id' => $otherUser->id]);

    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.v1.body-part-measurements.show', $measurement));

    $response->assertForbidden();
});

test('show returns 404 for non-existent measurement', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.v1.body-part-measurements.show', 99999));

    $response->assertNotFound();
});

test('update modifies measurement', function (): void {
    $user = User::factory()->create();
    $measurement = BodyPartMeasurement::factory()->create(['user_id' => $user->id, 'part' => 'Chest', 'value' => 100]);
    Sanctum::actingAs($user);

    $data = [
        'part' => 'Chest',
        'value' => 102.5,
        'unit' => 'cm',
        'measured_at' => now()->format('Y-m-d'),
        'notes' => 'Updated notes',
    ];

    $response = $this->putJson(route('api.v1.body-part-measurements.update', $measurement), $data);

    $response->assertOk()
        ->assertJsonFragment(['value' => 102.5, 'notes' => 'Updated notes']);

    $this->assertDatabaseHas('body_part_measurements', [
        'id' => $measurement->id,
        'value' => 102.5,
        'notes' => 'Updated notes',
    ]);
});

test('update validation checks', function (): void {
    $user = User::factory()->create();
    $measurement = BodyPartMeasurement::factory()->create(['user_id' => $user->id]);
    Sanctum::actingAs($user);

    // Invalid value
    $response = $this->putJson(route('api.v1.body-part-measurements.update', $measurement), [
        'part' => 'Chest',
        'value' => 1500, // Too large
        'unit' => 'cm',
        'measured_at' => now()->format('Y-m-d'),
    ]);
    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['value']);
});

test('update returns 403 for other user measurement', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $measurement = BodyPartMeasurement::factory()->create(['user_id' => $otherUser->id]);
    Sanctum::actingAs($user);

    $response = $this->putJson(route('api.v1.body-part-measurements.update', $measurement), [
        'part' => 'Chest',
        'value' => 105,
        'unit' => 'cm',
        'measured_at' => now()->format('Y-m-d'),
    ]);

    $response->assertForbidden();
});

test('destroy deletes measurement', function (): void {
    $user = User::factory()->create();
    $measurement = BodyPartMeasurement::factory()->create(['user_id' => $user->id]);
    Sanctum::actingAs($user);

    $response = $this->deleteJson(route('api.v1.body-part-measurements.destroy', $measurement));

    $response->assertNoContent();

    $this->assertDatabaseMissing('body_part_measurements', ['id' => $measurement->id]);
});

test('destroy returns 403 for other user measurement', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $measurement = BodyPartMeasurement::factory()->create(['user_id' => $otherUser->id]);
    Sanctum::actingAs($user);

    $response = $this->deleteJson(route('api.v1.body-part-measurements.destroy', $measurement));

    $response->assertForbidden();
});
