<?php

declare(strict_types=1);

use App\Models\BodyPartMeasurement;
use App\Models\User;

test('api body parts index', function (): void {
    $user = User::factory()->create();
    BodyPartMeasurement::factory()->count(3)->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->getJson(route('api.v1.body-part-measurements.index'));

    $response->assertOk()
        ->assertJsonStructure(['data' => [['id', 'part', 'value', 'unit', 'measured_at']]]);
});

test('api can add body part measurement', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('api.v1.body-part-measurements.store'), [
        'part' => 'Chest',
        'value' => 100.5,
        'unit' => 'cm',
        'measured_at' => '2023-01-01',
        'notes' => 'Test note',
    ]);

    $response->assertCreated()
        ->assertJsonFragment(['part' => 'Chest', 'value' => '100.50']);

    $this->assertDatabaseHas('body_part_measurements', [
        'user_id' => $user->id,
        'part' => 'Chest',
        'value' => 100.5,
    ]);
});

test('api can update body part measurement', function (): void {
    $user = User::factory()->create();
    $measurement = BodyPartMeasurement::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->putJson(route('api.v1.body-part-measurements.update', $measurement), [
        'value' => 105.0,
    ]);

    $response->assertOk()
        ->assertJsonFragment(['value' => '105.00']);

    $this->assertDatabaseHas('body_part_measurements', [
        'id' => $measurement->id,
        'value' => 105.0,
    ]);
});

test('api can delete body part measurement', function (): void {
    $user = User::factory()->create();
    $measurement = BodyPartMeasurement::factory()->create([
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->deleteJson(route('api.v1.body-part-measurements.destroy', $measurement));

    $response->assertNoContent();
    $this->assertDatabaseMissing('body_part_measurements', [
        'id' => $measurement->id,
    ]);
});

test('api cannot delete others body part measurement', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $measurement = BodyPartMeasurement::factory()->create([
        'user_id' => $otherUser->id,
    ]);

    $response = $this->actingAs($user)->deleteJson(route('api.v1.body-part-measurements.destroy', $measurement));

    $response->assertForbidden();
    $this->assertDatabaseHas('body_part_measurements', [
        'id' => $measurement->id,
    ]);
});

test('api show returns measurement', function (): void {
    $user = User::factory()->create();
    $measurement = BodyPartMeasurement::factory()->create([
        'user_id' => $user->id,
        'part' => 'Chest',
        'value' => 100,
    ]);

    $response = $this->actingAs($user)->getJson(route('api.v1.body-part-measurements.show', $measurement));

    $response->assertOk()
        ->assertJsonFragment(['part' => 'Chest', 'value' => '100.00']);
});
