<?php

use App\Models\Supplement;
use App\Models\User;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

test('it limits per_page to 100', function (): void {
    $user = User::factory()->create();

    // Create a supplement for the user
    Supplement::create([
        'user_id' => $user->id,
        'name' => 'Creatine',
        'servings_remaining' => 50,
        'low_stock_threshold' => 10,
    ]);

    $response = $this->actingAs($user)
        ->getJson(route('api.v1.supplement-logs.index', ['per_page' => 101]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['per_page']);
});

test('it allows valid per_page values', function (): void {
    $user = User::factory()->create();

    Supplement::create([
        'user_id' => $user->id,
        'name' => 'Creatine',
        'servings_remaining' => 50,
        'low_stock_threshold' => 10,
    ]);

    $response = $this->actingAs($user)
        ->getJson(route('api.v1.supplement-logs.index', ['per_page' => 50]));

    $response->assertStatus(200);
});
