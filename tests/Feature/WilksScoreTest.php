<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WilksScore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WilksScoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_wilks_calculator_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('tools.wilks'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Tools/WilksCalculator')
            ->has('history')
        );
    }

    public function test_user_can_save_wilks_score(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('tools.wilks.store'), [
            'body_weight' => 80,
            'lifted_weight' => 400,
            'gender' => 'male',
            'unit' => 'kg',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('wilks_scores', [
            'user_id' => $user->id,
            'body_weight' => 80,
            'lifted_weight' => 400,
            'gender' => 'male',
            'unit' => 'kg',
        ]);

        // Verify score calculation (approximate)
        $score = WilksScore::first()->score;
        $this->assertTrue($score > 0);
    }

    public function test_user_can_delete_wilks_score(): void
    {
        $user = User::factory()->create();
        $score = WilksScore::create([
            'user_id' => $user->id,
            'body_weight' => 80,
            'lifted_weight' => 400,
            'gender' => 'male',
            'unit' => 'kg',
            'score' => 273.0, // Mock value
        ]);

        $response = $this->actingAs($user)->delete(route('tools.wilks.destroy', $score));

        $response->assertRedirect();
        $this->assertDatabaseMissing('wilks_scores', ['id' => $score->id]);
    }

    public function test_user_cannot_delete_others_score(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $score = WilksScore::create([
            'user_id' => $user1->id,
            'body_weight' => 80,
            'lifted_weight' => 400,
            'gender' => 'male',
            'unit' => 'kg',
            'score' => 273.0,
        ]);

        $response = $this->actingAs($user2)->delete(route('tools.wilks.destroy', $score));

        $response->assertForbidden();
        $this->assertDatabaseHas('wilks_scores', ['id' => $score->id]);
    }
}
