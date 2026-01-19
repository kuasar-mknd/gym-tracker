<?php

namespace Tests\Feature;

use App\Models\MacroCalculation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MacroCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_macro_calculator_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('tools.macro-calculator'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Tools/MacroCalculator')
            ->has('history')
        );
    }

    public function test_user_can_save_macro_calculation(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('tools.macro-calculator.store'), [
            'gender' => 'male',
            'age' => 25,
            'height' => 180,
            'weight' => 80,
            'activity_level' => 'moderate',
            'goal' => 'maintain',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('macro_calculations', [
            'user_id' => $user->id,
            'gender' => 'male',
            'age' => 25,
            'height' => 180,
            'weight' => 80,
            'activity_level' => 1.55,
            'goal' => 'maintain',
        ]);

        // Verify calculation logic (BMR: 10*80 + 6.25*180 - 5*25 + 5 = 800 + 1125 - 125 + 5 = 1805)
        // TDEE: 1805 * 1.55 = 2797.75 -> 2798
        $calculation = MacroCalculation::first();
        $this->assertEquals(2798, $calculation->tdee);
        $this->assertEquals(2798, $calculation->target_calories); // maintain
    }

    public function test_user_can_delete_macro_calculation(): void
    {
        $user = User::factory()->create();
        $calculation = MacroCalculation::create([
            'user_id' => $user->id,
            'gender' => 'male',
            'age' => 25,
            'height' => 180,
            'weight' => 80,
            'activity_level' => 1.55,
            'goal' => 'maintain',
            'tdee' => 2800,
            'target_calories' => 2800,
            'protein' => 160,
            'fat' => 70,
            'carbs' => 380,
        ]);

        $response = $this->actingAs($user)->delete(route('tools.macro-calculator.destroy', $calculation));

        $response->assertRedirect();
        $this->assertDatabaseMissing('macro_calculations', ['id' => $calculation->id]);
    }

    public function test_user_cannot_delete_others_calculation(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $calculation = MacroCalculation::create([
            'user_id' => $user1->id,
            'gender' => 'male',
            'age' => 25,
            'height' => 180,
            'weight' => 80,
            'activity_level' => 1.55,
            'goal' => 'maintain',
            'tdee' => 2800,
            'target_calories' => 2800,
            'protein' => 160,
            'fat' => 70,
            'carbs' => 380,
        ]);

        $response = $this->actingAs($user2)->delete(route('tools.macro-calculator.destroy', $calculation));

        $response->assertForbidden();
        $this->assertDatabaseHas('macro_calculations', ['id' => $calculation->id]);
    }
}
