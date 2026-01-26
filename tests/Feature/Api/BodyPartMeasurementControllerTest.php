<?php

declare(strict_types=1);

use App\Models\BodyPartMeasurement;
use App\Models\User;

use function Pest\Laravel\actingAs;

test('index returns measurements', function () {
    $user = User::factory()->create();
    BodyPartMeasurement::factory()->count(3)->create(['user_id' => $user->id]);

    actingAs($user)
        ->getJson(route('api.v1.body-part-measurements.index'))
        ->assertSuccessful()
        ->assertJsonCount(3, 'data');
});

test('store creates measurement', function () {
    $user = User::factory()->create();

    $data = [
        'part' => 'Bicep',
        'value' => 35.5,
        'unit' => 'cm',
        'measured_at' => now()->format('Y-m-d'),
        'notes' => 'Post workout',
    ];

    actingAs($user)
        ->postJson(route('api.v1.body-part-measurements.store'), $data)
        ->assertCreated()
        ->assertJsonFragment(['part' => 'Bicep']);

    $this->assertDatabaseHas('body_part_measurements', [
        'user_id' => $user->id,
        'part' => 'Bicep',
        'value' => 35.5,
    ]);
});

test('show returns measurement', function () {
    $user = User::factory()->create();
    $measurement = BodyPartMeasurement::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->getJson(route('api.v1.body-part-measurements.show', $measurement))
        ->assertSuccessful()
        ->assertJsonFragment(['id' => $measurement->id]);
});

test('update updates measurement', function () {
    $user = User::factory()->create();
    $measurement = BodyPartMeasurement::factory()->create(['user_id' => $user->id]);

    $data = [
        'value' => 36.0,
    ];

    actingAs($user)
        ->putJson(route('api.v1.body-part-measurements.update', $measurement), $data)
        ->assertSuccessful()
        ->assertJsonFragment(['value' => '36.00']); // Decimal cast might return string

    $this->assertDatabaseHas('body_part_measurements', [
        'id' => $measurement->id,
        'value' => 36.0,
    ]);
});

test('destroy deletes measurement', function () {
    $user = User::factory()->create();
    $measurement = BodyPartMeasurement::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->deleteJson(route('api.v1.body-part-measurements.destroy', $measurement))
        ->assertNoContent();

    $this->assertDatabaseMissing('body_part_measurements', ['id' => $measurement->id]);
});

test('cannot access others measurements', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $measurement = BodyPartMeasurement::factory()->create(['user_id' => $user1->id]);

    actingAs($user2)
        ->getJson(route('api.v1.body-part-measurements.show', $measurement))
        ->assertForbidden();

    actingAs($user2)
        ->putJson(route('api.v1.body-part-measurements.update', $measurement), ['value' => 40])
        ->assertForbidden();

    actingAs($user2)
        ->deleteJson(route('api.v1.body-part-measurements.destroy', $measurement))
        ->assertForbidden();
});
