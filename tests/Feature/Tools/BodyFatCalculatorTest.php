<?php

namespace Tests\Feature\Tools;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BodyFatCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_body_fat_calculator_page_loads()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('tools.body-fat-calculator'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Tools/BodyFatCalculator')
        );
    }
}
