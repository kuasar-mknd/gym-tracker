<?php

declare(strict_types=1);

use App\Models\BodyMeasurement;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('index returns paginated list of body measurements', function (): void {
    $user = User::factory()->create();
    BodyMeasurement::factory()->count(3)->create(['user_id' => $user->id]);

    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.v1.body-measurements.index'));

    $response->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'user_id', 'weight', 'measured_at', 'notes', 'created_at', 'updated_at'],
            ],
            'links',
            'meta',
        ]);
});

test('index only shows authenticated user measurements', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    BodyMeasurement::factory()->count(2)->create(['user_id' => $user->id]);
    BodyMeasurement::factory()->count(3)->create(['user_id' => $otherUser->id]);

    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.v1.body-measurements.index'));

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

test('unauthenticated user cannot list measurements', function (): void {
    $response = $this->getJson(route('api.v1.body-measurements.index'));

    $response->assertUnauthorized();
});

test('store creates new measurement', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $data = [
        'weight' => 75.5,
        'measured_at' => now()->format('Y-m-d'),
        'notes' => 'Feeling great',
    ];

    $response = $this->postJson(route('api.v1.body-measurements.store'), $data);

    $response->assertCreated()
        ->assertJsonFragment([
            'weight' => '75.50',
            'notes' => 'Feeling great',
        ]);

    $this->assertDatabaseHas('body_measurements', [
        'user_id' => $user->id,
        'weight' => 75.5,
        'notes' => 'Feeling great',
    ]);
});

test('store requires weight and measured_at', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson(route('api.v1.body-measurements.store'), []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['weight', 'measured_at']);
});

test('store validation checks', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // Test weight numeric/min/max
    $response = $this->postJson(route('api.v1.body-measurements.store'), [
        'weight' => 600, // Too high
        'measured_at' => now()->format('Y-m-d'),
    ]);
    $response->assertUnprocessable()->assertJsonValidationErrors(['weight']);

    $response = $this->postJson(route('api.v1.body-measurements.store'), [
        'weight' => 0, // Too low
        'measured_at' => now()->format('Y-m-d'),
    ]);
    $response->assertUnprocessable()->assertJsonValidationErrors(['weight']);

    // Test date format
    $response = $this->postJson(route('api.v1.body-measurements.store'), [
        'weight' => 70,
        'measured_at' => 'not-a-date',
    ]);
    $response->assertUnprocessable()->assertJsonValidationErrors(['measured_at']);
});

test('show returns measurement details', function (): void {
    $user = User::factory()->create();
    $measurement = BodyMeasurement::factory()->create(['user_id' => $user->id]);
    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.v1.body-measurements.show', $measurement));

    $response->assertOk()
        ->assertJsonFragment(['id' => $measurement->id, 'weight' => $measurement->weight]);
});

test('show returns 403 for other user measurement', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $measurement = BodyMeasurement::factory()->create(['user_id' => $otherUser->id]);

    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.v1.body-measurements.show', $measurement));

    $response->assertForbidden();
});

test('show returns 404 for non-existent measurement', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.v1.body-measurements.show', 99999));

    $response->assertNotFound();
});

test('update modifies measurement', function (): void {
    $user = User::factory()->create();
    $measurement = BodyMeasurement::factory()->create(['user_id' => $user->id, 'weight' => 70]);
    Sanctum::actingAs($user);

    $data = [
        'weight' => 72.5,
        'notes' => 'Updated notes',
    ];

    $response = $this->putJson(route('api.v1.body-measurements.update', $measurement), $data);

    $response->assertOk()
        ->assertJsonFragment(['weight' => '72.50', 'notes' => 'Updated notes']);

    $this->assertDatabaseHas('body_measurements', [
        'id' => $measurement->id,
        'weight' => 72.5,
        'notes' => 'Updated notes',
    ]);
});

test('update returns 403 for other user measurement', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $measurement = BodyMeasurement::factory()->create(['user_id' => $otherUser->id]);
    Sanctum::actingAs($user);

    $response = $this->putJson(route('api.v1.body-measurements.update', $measurement), ['weight' => 80]);

    $response->assertForbidden();
});

test('destroy deletes measurement', function (): void {
    $user = User::factory()->create();
    $measurement = BodyMeasurement::factory()->create(['user_id' => $user->id]);
    Sanctum::actingAs($user);

    $response = $this->deleteJson(route('api.v1.body-measurements.destroy', $measurement));

    $response->assertNoContent();

    $this->assertDatabaseMissing('body_measurements', ['id' => $measurement->id]);
});

test('destroy returns 403 for other user measurement', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $measurement = BodyMeasurement::factory()->create(['user_id' => $otherUser->id]);
    Sanctum::actingAs($user);

    $response = $this->deleteJson(route('api.v1.body-measurements.destroy', $measurement));

    $response->assertForbidden();
});
