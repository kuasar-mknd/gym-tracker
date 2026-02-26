<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\MacroCalculation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MacroCalculationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_macro_calculations(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        MacroCalculation::factory()->count(3)->create(['user_id' => $user->id]);

        $response = $this->getJson(route('api.v1.macro-calculations.index'));

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_can_create_macro_calculation(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $data = [
            'gender' => 'male',
            'age' => 25,
            'height' => 180,
            'weight' => 75,
            'activity_level' => 'moderate',
            'goal' => 'maintain',
        ];

        $response = $this->postJson(route('api.v1.macro-calculations.store'), $data);

        $response->assertCreated()
            ->assertJsonFragment(['gender' => 'male']);

        $this->assertDatabaseHas('macro_calculations', [
            'user_id' => $user->id,
            'age' => 25,
        ]);
    }
}
