<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EquipmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_equipment_page(): void
    {
        $user = User::factory()->create();
        $equipment = Equipment::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->get(route('equipment.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Equipment/Index')
            ->has('equipment', 1)
        );
    }

    public function test_user_can_create_equipment(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('equipment.store'), [
            'name' => 'Nike Romaleos',
            'type' => 'shoes',
            'brand' => 'Nike',
            'model' => '4',
            'purchased_at' => '2023-01-01',
            'is_active' => true,
            'notes' => 'Best shoes ever',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('equipment', [
            'user_id' => $user->id,
            'name' => 'Nike Romaleos',
            'type' => 'shoes',
            'brand' => 'Nike',
        ]);
    }

    public function test_user_can_update_equipment(): void
    {
        $user = User::factory()->create();
        $equipment = Equipment::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->put(route('equipment.update', $equipment), [
            'name' => 'Updated Name',
            'type' => 'belt',
            'is_active' => false,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('equipment', [
            'id' => $equipment->id,
            'name' => 'Updated Name',
            'type' => 'belt',
            'is_active' => false,
        ]);
    }

    public function test_user_can_delete_equipment(): void
    {
        $user = User::factory()->create();
        $equipment = Equipment::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->delete(route('equipment.destroy', $equipment));

        $response->assertRedirect();
        $this->assertDatabaseMissing('equipment', [
            'id' => $equipment->id,
        ]);
    }

    public function test_user_cannot_update_others_equipment(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $equipment = Equipment::factory()->create(['user_id' => $user1->id]);

        $response = $this->actingAs($user2)->put(route('equipment.update', $equipment), [
            'name' => 'Hacked',
        ]);

        $response->assertStatus(403);
    }

    public function test_user_cannot_delete_others_equipment(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $equipment = Equipment::factory()->create(['user_id' => $user1->id]);

        $response = $this->actingAs($user2)->delete(route('equipment.destroy', $equipment));

        $response->assertStatus(403);
    }
}
