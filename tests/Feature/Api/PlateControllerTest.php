<?php

use App\Models\Plate;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

test('can list plates', function (): void {
    Plate::factory()->count(3)->create(['user_id' => $this->user->id]);

    $response = $this->getJson(route('api.v1.plates.index'));

    $response->assertOk()
        ->assertJsonCount(3, 'data');
});

test('can create a plate', function (): void {
    $data = [
        'weight' => 20.5,
        'quantity' => 2,
    ];

    $response = $this->postJson(route('api.v1.plates.store'), $data);

    $response->assertCreated()
        ->assertJsonFragment([
            'weight' => 20.5,
            'quantity' => 2,
        ]);

    $this->assertDatabaseHas('plates', [
        'user_id' => $this->user->id,
        'weight' => 20.5,
        'quantity' => 2,
    ]);
});

test('can show a plate', function (): void {
    $plate = Plate::factory()->create(['user_id' => $this->user->id]);

    $response = $this->getJson(route('api.v1.plates.show', $plate));

    $response->assertOk()
        ->assertJsonFragment([
            'id' => $plate->id,
            'weight' => (float) $plate->weight,
            'quantity' => $plate->quantity,
        ]);
});

test('can update a plate', function (): void {
    $plate = Plate::factory()->create([
        'user_id' => $this->user->id,
        'weight' => 10,
        'quantity' => 4,
    ]);

    $data = [
        'weight' => 15.0,
        'quantity' => 6,
    ];

    $response = $this->putJson(route('api.v1.plates.update', $plate), $data);

    $response->assertOk()
        ->assertJsonFragment([
            'weight' => 15.0,
            'quantity' => 6,
        ]);

    $this->assertDatabaseHas('plates', [
        'id' => $plate->id,
        'weight' => 15.0,
        'quantity' => 6,
    ]);
});

test('can delete a plate', function (): void {
    $plate = Plate::factory()->create(['user_id' => $this->user->id]);

    $response = $this->deleteJson(route('api.v1.plates.destroy', $plate));

    $response->assertNoContent();

    $this->assertDatabaseMissing('plates', ['id' => $plate->id]);
});

test('cannot view another users plate', function (): void {
    $otherUser = User::factory()->create();
    $plate = Plate::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->getJson(route('api.v1.plates.show', $plate));

    $response->assertForbidden();
});

test('cannot update another users plate', function (): void {
    $otherUser = User::factory()->create();
    $plate = Plate::factory()->create(['user_id' => $otherUser->id]);

    $data = ['weight' => 25.0, 'quantity' => 1];

    $response = $this->putJson(route('api.v1.plates.update', $plate), $data);

    $response->assertForbidden();
});

test('cannot delete another users plate', function (): void {
    $otherUser = User::factory()->create();
    $plate = Plate::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->deleteJson(route('api.v1.plates.destroy', $plate));

    $response->assertForbidden();
});
