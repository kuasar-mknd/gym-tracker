<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class EquipmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_view_equipment_index(): void
    {
        $user = User::factory()->create();
        Equipment::factory()->create([
            'user_id' => $user->id,
            'name' => 'Test Shoes',
        ]);

        $response = $this->actingAs($user)->get(route('equipment.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Equipment/Index')
            ->has('equipment', 1)
            ->where('equipment.0.name', 'Test Shoes')
        );
    }

    public function test_can_create_equipment(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('equipment.store'), [
            'name' => 'New Belt',
            'type' => 'belt',
            'brand' => 'SBD',
            'model' => '13mm',
            'is_active' => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('equipment', [
            'user_id' => $user->id,
            'name' => 'New Belt',
            'type' => 'belt',
            'brand' => 'SBD',
        ]);
    }

    public function test_can_update_equipment(): void
    {
        $user = User::factory()->create();
        $equipment = Equipment::factory()->create([
            'user_id' => $user->id,
            'name' => 'Old Shoes',
        ]);

        $response = $this->actingAs($user)->patch(route('equipment.update', $equipment), [
            'name' => 'Updated Shoes',
            'type' => 'shoes',
            'is_active' => false,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('equipment', [
            'id' => $equipment->id,
            'name' => 'Updated Shoes',
            'is_active' => false,
        ]);
    }

    public function test_can_delete_equipment(): void
    {
        $user = User::factory()->create();
        $equipment = Equipment::factory()->create([
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->delete(route('equipment.destroy', $equipment));

        $response->assertRedirect();
        $this->assertSoftDeleted('equipment', [
            'id' => $equipment->id,
        ]);
    }

    public function test_cannot_access_others_equipment(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $equipment = Equipment::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        $response = $this->actingAs($user)->patch(route('equipment.update', $equipment), [
            'name' => 'Hacked',
            'type' => 'shoes',
        ]);

        $response->assertStatus(403);

        $response = $this->actingAs($user)->delete(route('equipment.destroy', $equipment));

        $response->assertStatus(403);
    }
}
