<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\Supplement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_supplements_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('supplements.index'));

        $response->assertStatus(200);
    }

    public function test_user_can_create_supplement(): void
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

    public function test_user_can_consume_supplement(): void
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

    public function test_user_cannot_consume_others_supplement(): void
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

    public function test_scope_for_user_filters_correctly(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        Supplement::factory()->count(3)->create(['user_id' => $user1->id]);
        Supplement::factory()->count(2)->create(['user_id' => $user2->id]);

        $user1Supplements = Supplement::forUser($user1->id)->get();
        $user2Supplements = Supplement::forUser($user2->id)->get();

        $this->assertCount(3, $user1Supplements);
        $this->assertCount(2, $user2Supplements);

        foreach ($user1Supplements as $supplement) {
            $this->assertEquals($user1->id, $supplement->user_id);
        }

        foreach ($user2Supplements as $supplement) {
            $this->assertEquals($user2->id, $supplement->user_id);
        }
    }
}
