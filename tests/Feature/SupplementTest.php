<?php

namespace Tests\Feature;

use App\Models\Supplement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_supplements_page()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('supplements.index'));

        $response->assertStatus(200);
    }

    public function test_user_can_create_supplement()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('supplements.store'), [
            'name' => 'Creatine',
            'brand' => 'Optimum',
            'dosage' => '5g',
            'servings_remaining' => 30,
            'low_stock_threshold' => 5,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('supplements', [
            'user_id' => $user->id,
            'name' => 'Creatine',
            'servings_remaining' => 30,
        ]);
    }

    public function test_user_can_consume_supplement()
    {
        $user = User::factory()->create();
        $supplement = Supplement::create([
            'user_id' => $user->id,
            'name' => 'Whey',
            'servings_remaining' => 10,
            'low_stock_threshold' => 5,
        ]);

        $response = $this->actingAs($user)->post(route('supplements.consume', $supplement));

        $response->assertRedirect();

        $this->assertDatabaseHas('supplements', [
            'id' => $supplement->id,
            'servings_remaining' => 9,
        ]);

        $this->assertDatabaseHas('supplement_logs', [
            'supplement_id' => $supplement->id,
            'user_id' => $user->id,
            'quantity' => 1,
        ]);
    }

    public function test_user_cannot_consume_others_supplement()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $supplement = Supplement::create([
            'user_id' => $user1->id,
            'name' => 'Whey',
            'servings_remaining' => 10,
            'low_stock_threshold' => 5,
        ]);

        $response = $this->actingAs($user2)->post(route('supplements.consume', $supplement));

        $response->assertStatus(403);
    }
}
