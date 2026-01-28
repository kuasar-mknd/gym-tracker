<?php

declare(strict_types=1);

use App\Models\MacroCalculation;
use App\Models\User;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('can list macro calculations', function (): void {
    MacroCalculation::factory()->count(3)->create(['user_id' => $this->user->id]);

    $response = $this->getJson(route('api.v1.macro-calculations.index'));

    $response->assertStatus(200)
        ->assertJsonCount(3, 'data');
});

test('can create a macro calculation', function (): void {
    $data = [
        'gender' => 'male',
        'age' => 30,
        'height' => 180,
        'weight' => 80,
        'activity_level' => 'moderate',
        'goal' => 'cut',
    ];

    $response = $this->postJson(route('api.v1.macro-calculations.store'), $data);

    $response->assertStatus(201)
        ->assertJsonFragment([
            'gender' => 'male',
            'age' => 30,
            // 'activity_level' => 1.55, // Don't test float exact match in fragment loosely
            'goal' => 'cut',
        ]);

    $this->assertDatabaseHas('macro_calculations', [
        'user_id' => $this->user->id,
        'gender' => 'male',
        'age' => 30,
        'goal' => 'cut',
    ]);
});

test('can view a specific macro calculation', function (): void {
    $calculation = MacroCalculation::factory()->create(['user_id' => $this->user->id]);

    $response = $this->getJson(route('api.v1.macro-calculations.show', $calculation));

    $response->assertStatus(200)
        ->assertJsonFragment(['id' => $calculation->id]);
});

test('cannot view another users macro calculation', function (): void {
    $otherUser = User::factory()->create();
    $calculation = MacroCalculation::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->getJson(route('api.v1.macro-calculations.show', $calculation));

    $response->assertStatus(403);
});

test('can update a macro calculation', function (): void {
    $calculation = MacroCalculation::factory()->create(['user_id' => $this->user->id]);

    $data = [
        'gender' => 'female',
        'age' => 25,
        'height' => 165,
        'weight' => 60,
        'activity_level' => 'active', // 'active' is not in list? wait 'extra'?
        // 'sedentary,light,moderate,very,extra'
        'activity_level' => 'very',
        'goal' => 'maintain',
    ];

    $response = $this->putJson(route('api.v1.macro-calculations.update', $calculation), $data);

    $response->assertStatus(200)
        ->assertJsonFragment(['gender' => 'female', 'goal' => 'maintain']);

    $this->assertDatabaseHas('macro_calculations', [
        'id' => $calculation->id,
        'gender' => 'female',
        'goal' => 'maintain',
    ]);
});

test('cannot update another users macro calculation', function (): void {
    $otherUser = User::factory()->create();
    $calculation = MacroCalculation::factory()->create(['user_id' => $otherUser->id]);

    $data = [
        'gender' => 'female',
        'age' => 25,
        'height' => 165,
        'weight' => 60,
        'activity_level' => 'very',
        'goal' => 'maintain',
    ];

    $response = $this->putJson(route('api.v1.macro-calculations.update', $calculation), $data);

    $response->assertStatus(403);
});

test('can delete a macro calculation', function (): void {
    $calculation = MacroCalculation::factory()->create(['user_id' => $this->user->id]);

    $response = $this->deleteJson(route('api.v1.macro-calculations.destroy', $calculation));

    $response->assertStatus(204);

    $this->assertDatabaseMissing('macro_calculations', ['id' => $calculation->id]);
});

test('cannot delete another users macro calculation', function (): void {
    $otherUser = User::factory()->create();
    $calculation = MacroCalculation::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->deleteJson(route('api.v1.macro-calculations.destroy', $calculation));

    $response->assertStatus(403);
});
