<?php

declare(strict_types=1);

use App\Models\MacroCalculation;
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

    test('user can list their macro calculations', function (): void {
        MacroCalculation::factory()->count(3)->create([
            'user_id' => $this->user->id,
        ]);

        // Create for another user
        MacroCalculation::factory()->create();

        $response = getJson(route('api.v1.macro-calculations.index'));

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'user_id',
                        'gender',
                        'age',
                        'height',
                        'weight',
                        'activity_level',
                        'goal',
                        'tdee',
                        'target_calories',
                        'protein',
                        'fat',
                        'carbs',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'links',
                'meta',
            ]);
    });

    test('user can create a macro calculation', function (): void {
        $data = [
            'gender' => 'male',
            'age' => 25,
            'height' => 180,
            'weight' => 80,
            'activity_level' => 'moderate',
            'goal' => 'maintain',
        ];

        $response = postJson(route('api.v1.macro-calculations.store'), $data);

        $response->assertCreated()
            ->assertJsonFragment([
                'gender' => 'male',
                'age' => 25,
            ]);
    });

    test('user can view their macro calculation', function (): void {
        $calculation = MacroCalculation::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $response = getJson(route('api.v1.macro-calculations.show', $calculation));

        $response->assertOk()
            ->assertJsonFragment([
                'id' => $calculation->id,
            ]);
    });

    test('user cannot view others macro calculation', function (): void {
        $otherUser = User::factory()->create();
        $calculation = MacroCalculation::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        $response = getJson(route('api.v1.macro-calculations.show', $calculation));

        $response->assertForbidden();
    });

    test('user can update their macro calculation', function (): void {
        $calculation = MacroCalculation::factory()->create([
            'user_id' => $this->user->id,
            'weight' => 80,
            'goal' => 'maintain',
        ]);

        $response = putJson(route('api.v1.macro-calculations.update', $calculation), [
            'gender' => $calculation->gender,
            'age' => $calculation->age,
            'height' => $calculation->height,
            'weight' => 85, // changed
            'activity_level' => 'moderate',
            'goal' => 'bulk', // changed
        ]);

        $response->assertOk()
            ->assertJsonFragment([
                'weight' => '85.00',
                'goal' => 'bulk',
            ]);

        assertDatabaseHas('macro_calculations', [
            'id' => $calculation->id,
            'weight' => 85,
            'goal' => 'bulk',
        ]);
    });

    test('user can delete their macro calculation', function (): void {
        $calculation = MacroCalculation::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $response = deleteJson(route('api.v1.macro-calculations.destroy', $calculation));

        $response->assertNoContent();

        assertDatabaseMissing('macro_calculations', ['id' => $calculation->id]);
    });
});
