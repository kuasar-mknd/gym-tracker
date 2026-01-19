<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WarmupPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WarmupCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_warmup_calculator_page_can_be_rendered()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('tools.warmup'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tools/WarmupCalculator')
                ->has('preference')
            );
    }

    public function test_warmup_calculator_loads_default_preferences()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('tools.warmup'));

        $response->assertStatus(200);
        $props = $response->inertiaPage()['props'];
        $this->assertEquals(20.0, $props['preference']['bar_weight']);
        $this->assertEquals(2.5, $props['preference']['rounding_increment']);
        $this->assertIsArray($props['preference']['steps']);
    }

    public function test_user_can_update_warmup_preferences()
    {
        $user = User::factory()->create();

        $steps = [
            ['percent' => 50, 'reps' => 10, 'label' => 'Light'],
            ['percent' => 75, 'reps' => 5, 'label' => 'Moderate'],
        ];

        $response = $this->actingAs($user)->post(route('tools.warmup.update'), [
            'bar_weight' => 25.0,
            'rounding_increment' => 1.0,
            'steps' => $steps,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('warmup_preferences', [
            'user_id' => $user->id,
            'bar_weight' => 25.0,
            'rounding_increment' => 1.0,
        ]);

        $pref = WarmupPreference::where('user_id', $user->id)->first();
        $this->assertEquals($steps, $pref->steps);
    }

    public function test_user_can_update_existing_preferences()
    {
        $user = User::factory()->create();
        WarmupPreference::create([
            'user_id' => $user->id,
            'bar_weight' => 20.0,
            'rounding_increment' => 2.5,
            'steps' => [],
        ]);

        $response = $this->actingAs($user)->post(route('tools.warmup.update'), [
            'bar_weight' => 30.0,
            'rounding_increment' => 5.0,
            'steps' => [['percent' => 10, 'reps' => 10]],
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('warmup_preferences', [
            'user_id' => $user->id,
            'bar_weight' => 30.0,
        ]);

        $this->assertEquals(1, WarmupPreference::where('user_id', $user->id)->count());
    }

    public function test_validation_rules()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('tools.warmup.update'), [
            'bar_weight' => 'invalid',
            'steps' => 'invalid',
        ]);

        $response->assertSessionHasErrors(['bar_weight', 'steps']);
    }
}
