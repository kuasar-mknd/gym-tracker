<?php

declare(strict_types=1);

use App\Models\BodyPartMeasurement;
use App\Models\User;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('can list body part measurements', function (): void {
    BodyPartMeasurement::factory()->count(3)->create(['user_id' => $this->user->id]);

    $response = $this->getJson(route('api.v1.body-part-measurements.index'));

    $response->assertStatus(200)
        ->assertJsonCount(3, 'data');
});

test('can create a body part measurement', function (): void {
    $data = [
        'part' => 'Biceps R',
        'value' => 35.5,
        'unit' => 'cm',
        'measured_at' => now()->format('Y-m-d'),
        'notes' => 'Post workout',
    ];

    $response = $this->postJson(route('api.v1.body-part-measurements.store'), $data);

    $response->assertStatus(201)
        ->assertJsonFragment(['part' => 'Biceps R', 'value' => 35.5]);

    $this->assertDatabaseHas('body_part_measurements', [
        'user_id' => $this->user->id,
        'part' => 'Biceps R',
        'value' => 35.5,
    ]);
});

test('can view a specific body part measurement', function (): void {
    $measurement = BodyPartMeasurement::factory()->create(['user_id' => $this->user->id]);

    $response = $this->getJson(route('api.v1.body-part-measurements.show', $measurement));

    $response->assertStatus(200)
        ->assertJsonFragment(['id' => $measurement->id]);
});

test('cannot view another users body part measurement', function (): void {
    $otherUser = User::factory()->create();
    $measurement = BodyPartMeasurement::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->getJson(route('api.v1.body-part-measurements.show', $measurement));

    $response->assertStatus(403);
});

test('can update a body part measurement', function (): void {
    $measurement = BodyPartMeasurement::factory()->create(['user_id' => $this->user->id]);
    $data = ['value' => 36.0];

    $response = $this->putJson(route('api.v1.body-part-measurements.update', $measurement), $data);

    $response->assertStatus(200)
        ->assertJsonFragment(['value' => 36.0]);

    $this->assertDatabaseHas('body_part_measurements', [
        'id' => $measurement->id,
        'value' => 36.0,
    ]);
});

test('cannot update another users body part measurement', function (): void {
    $otherUser = User::factory()->create();
    $measurement = BodyPartMeasurement::factory()->create(['user_id' => $otherUser->id]);
    $data = ['value' => 36.0];

    $response = $this->putJson(route('api.v1.body-part-measurements.update', $measurement), $data);

    $response->assertStatus(403);
});

test('can delete a body part measurement', function (): void {
    $measurement = BodyPartMeasurement::factory()->create(['user_id' => $this->user->id]);

    $response = $this->deleteJson(route('api.v1.body-part-measurements.destroy', $measurement));

    $response->assertStatus(204);

    $this->assertDatabaseMissing('body_part_measurements', ['id' => $measurement->id]);
});

test('cannot delete another users body part measurement', function (): void {
    $otherUser = User::factory()->create();
    $measurement = BodyPartMeasurement::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->deleteJson(route('api.v1.body-part-measurements.destroy', $measurement));

    $response->assertStatus(403);
});
