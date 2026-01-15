<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlateControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_plates(): void
    {
        $user = User::factory()->create();
        $plate = $user->plates()->create([
            'weight' => 20,
            'quantity' => 2,
        ]);

        $response = $this->actingAs($user)->get(route('plates.index'));

        $response->assertStatus(200);
        // Inertia testing
        $response->assertInertia(fn ($page) => $page
            ->component('Tools/PlateCalculator')
        );
    }

    public function test_can_create_plate(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('plates.store'), [
            'weight' => 20,
            'quantity' => 4,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('plates', [
            'user_id' => $user->id,
            'weight' => 20,
            'quantity' => 4,
        ]);
    }

    public function test_can_update_plate(): void
    {
        $user = User::factory()->create();
        $plate = $user->plates()->create([
            'weight' => 20,
            'quantity' => 2,
        ]);

        $response = $this->actingAs($user)->put(route('plates.update', $plate), [
            'weight' => 20,
            'quantity' => 6,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('plates', [
            'id' => $plate->id,
            'quantity' => 6,
        ]);
    }

    public function test_can_delete_plate(): void
    {
        $user = User::factory()->create();
        $plate = $user->plates()->create([
            'weight' => 20,
            'quantity' => 2,
        ]);

        $response = $this->actingAs($user)->delete(route('plates.destroy', $plate));

        $response->assertRedirect();
        $this->assertDatabaseMissing('plates', [
            'id' => $plate->id,
        ]);
    }

    public function test_cannot_modify_others_plates(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $plate = $user1->plates()->create([
            'weight' => 20,
            'quantity' => 2,
        ]);

        $response = $this->actingAs($user2)->delete(route('plates.destroy', $plate));
        $response->assertStatus(403);
    }
}
