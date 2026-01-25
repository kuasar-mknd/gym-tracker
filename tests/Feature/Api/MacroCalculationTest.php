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
        // Manually create some calculations
        $this->user->macroCalculations()->create([
            'gender' => 'male',
            'age' => 30,
            'height' => 180,
            'weight' => 80,
            'activity_level' => 1.2, // stored as float
            'goal' => 'maintain',
            'tdee' => 2000,
            'target_calories' => 2000,
            'protein' => 150,
            'fat' => 70,
            'carbs' => 200,
        ]);

        $response = getJson(route('api.v1.macro-calculations.index'));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'gender',
                        'age',
                        'tdee',
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
            'height' => 175,
            'weight' => 75,
            'activity_level' => 'moderate', // label
            'goal' => 'cut',
        ];

        $response = postJson(route('api.v1.macro-calculations.store'), $data);

        $response->assertCreated()
            ->assertJsonFragment([
                'gender' => 'male',
                'age' => 25,
            ]);

        // Check if calculation was performed (tdee should be present)
        $response->assertJsonStructure(['data' => ['tdee', 'target_calories', 'protein']]);

        assertDatabaseHas('macro_calculations', [
            'user_id' => $this->user->id,
            'gender' => 'male',
        ]);
    });

    test('user can update a macro calculation', function (): void {
        $calculation = $this->user->macroCalculations()->create([
            'gender' => 'male',
            'age' => 30,
            'height' => 180,
            'weight' => 80,
            'activity_level' => 1.2,
            'goal' => 'maintain',
            'tdee' => 2000,
            'target_calories' => 2000,
            'protein' => 150,
            'fat' => 70,
            'carbs' => 200,
        ]);

        // Update to 'bulk'
        $updateData = [
            'gender' => 'male',
            'age' => 30,
            'height' => 180,
            'weight' => 80,
            'activity_level' => 'sedentary', // 1.2
            'goal' => 'bulk',
        ];

        $response = putJson(route('api.v1.macro-calculations.update', $calculation), $updateData);

        $response->assertOk()
            ->assertJsonFragment([
                'goal' => 'bulk',
            ]);

        // Check database
        assertDatabaseHas('macro_calculations', [
            'id' => $calculation->id,
            'goal' => 'bulk',
        ]);
    });

    test('user can delete a macro calculation', function (): void {
        $calculation = $this->user->macroCalculations()->create([
            'gender' => 'male',
            'age' => 30,
            'height' => 180,
            'weight' => 80,
            'activity_level' => 1.2,
            'goal' => 'maintain',
            'tdee' => 2000,
            'target_calories' => 2000,
            'protein' => 150,
            'fat' => 70,
            'carbs' => 200,
        ]);

        $response = deleteJson(route('api.v1.macro-calculations.destroy', $calculation));

        $response->assertNoContent();
        assertDatabaseMissing('macro_calculations', ['id' => $calculation->id]);
    });

    test('user cannot access others calculations', function(): void {
        $otherUser = User::factory()->create();
        $calculation = $otherUser->macroCalculations()->create([
            'gender' => 'female',
            'age' => 20,
            'height' => 160,
            'weight' => 60,
            'activity_level' => 1.2,
            'goal' => 'maintain',
            'tdee' => 1500,
            'target_calories' => 1500,
            'protein' => 100,
            'fat' => 50,
            'carbs' => 150,
        ]);

        $response = getJson(route('api.v1.macro-calculations.show', $calculation));
        $response->assertForbidden();

        $response = putJson(route('api.v1.macro-calculations.update', $calculation), [
            'gender' => 'female',
            'age' => 20,
            'height' => 160,
            'weight' => 60,
            'activity_level' => 'sedentary',
            'goal' => 'maintain',
        ]);
        $response->assertForbidden();

        $response = deleteJson(route('api.v1.macro-calculations.destroy', $calculation));
        $response->assertForbidden();
    });
});
