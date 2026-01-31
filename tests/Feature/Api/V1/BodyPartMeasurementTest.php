<?php

declare(strict_types=1);

use App\Models\BodyPartMeasurement;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('index lists body part measurements for authenticated user', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    BodyPartMeasurement::factory()->count(3)->create([
        'user_id' => $user->id,
    ]);

    BodyPartMeasurement::factory()->count(2)->create(); // other user's data

    $response = $this->getJson(route('api.v1.body-part-measurements.index'));

    $response->assertOk()
        ->assertJsonCount(3, 'data');
});

test('store creates a new body part measurement', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $data = [
        'part' => 'Biceps',
        'value' => 35.5,
        'unit' => 'cm',
        'measured_at' => now()->format('Y-m-d'),
        'notes' => 'Good pump',
    ];

    $response = $this->postJson(route('api.v1.body-part-measurements.store'), $data);

    $response->assertCreated()
        ->assertJsonFragment([
            'part' => 'Biceps',
            'value' => 35.5,
            'unit' => 'cm',
        ]);

    $this->assertDatabaseHas('body_part_measurements', [
        'user_id' => $user->id,
        'part' => 'Biceps',
        'value' => 35.5,
    ]);
});

test('show retrieves a body part measurement', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $measurement = BodyPartMeasurement::factory()->create([
        'user_id' => $user->id,
    ]);

    $response = $this->getJson(route('api.v1.body-part-measurements.show', $measurement));

    $response->assertOk()
        ->assertJsonFragment([
            'id' => $measurement->id,
        ]);
});

test('show prevents access to other users measurements', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $measurement = BodyPartMeasurement::factory()->create(); // other user

    $response = $this->getJson(route('api.v1.body-part-measurements.show', $measurement));

    $response->assertForbidden();
});

test('update modifies a body part measurement', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $measurement = BodyPartMeasurement::factory()->create([
        'user_id' => $user->id,
        'value' => 30.0,
    ]);

    $data = [
        'value' => 32.5,
    ];

    $response = $this->patchJson(route('api.v1.body-part-measurements.update', $measurement), $data);

    $response->assertOk()
        ->assertJsonFragment([
            'value' => 32.5,
        ]);

    $this->assertDatabaseHas('body_part_measurements', [
        'id' => $measurement->id,
        'value' => 32.5,
    ]);
});

test('update prevents modifying other users measurements', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $measurement = BodyPartMeasurement::factory()->create(); // other user

    $response = $this->patchJson(route('api.v1.body-part-measurements.update', $measurement), [
        'value' => 40.0,
    ]);

    $response->assertForbidden();
});

test('destroy deletes a body part measurement', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $measurement = BodyPartMeasurement::factory()->create([
        'user_id' => $user->id,
    ]);

    $response = $this->deleteJson(route('api.v1.body-part-measurements.destroy', $measurement));

    $response->assertNoContent();

    $this->assertDatabaseMissing('body_part_measurements', [
        'id' => $measurement->id,
    ]);
});

test('destroy prevents deleting other users measurements', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $measurement = BodyPartMeasurement::factory()->create(); // other user

    $response = $this->deleteJson(route('api.v1.body-part-measurements.destroy', $measurement));

    $response->assertForbidden();
});
