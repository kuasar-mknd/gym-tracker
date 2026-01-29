<?php

namespace Tests\Feature;

use App\Models\Injury;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InjuryTest extends TestCase
{
    use RefreshDatabase;

    public function test_injuries_screen_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('injuries.index'));

        $response->assertStatus(200);
    }

    public function test_user_can_create_injury(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('injuries.store'), [
            'title' => 'Test Injury',
            'body_part' => 'Knee',
            'status' => 'active',
            'pain_level' => 5,
            'occurred_at' => '2023-01-01',
            'notes' => 'Ouch',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('injuries', [
            'user_id' => $user->id,
            'title' => 'Test Injury',
            'pain_level' => 5,
        ]);
    }

    public function test_user_can_update_injury(): void
    {
        $user = User::factory()->create();
        $injury = Injury::create([
            'user_id' => $user->id,
            'title' => 'Old Title',
            'body_part' => 'Knee',
            'status' => 'active',
            'pain_level' => 5,
            'occurred_at' => '2023-01-01',
        ]);

        $response = $this->actingAs($user)->put(route('injuries.update', $injury), [
            'title' => 'New Title',
            'body_part' => 'Knee',
            'status' => 'recovering',
            'pain_level' => 3,
            'occurred_at' => '2023-01-01',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('injuries', [
            'id' => $injury->id,
            'title' => 'New Title',
            'status' => 'recovering',
        ]);
    }

    public function test_user_can_delete_injury(): void
    {
        $user = User::factory()->create();
        $injury = Injury::create([
            'user_id' => $user->id,
            'title' => 'To Delete',
            'body_part' => 'Knee',
            'status' => 'active',
            'pain_level' => 5,
            'occurred_at' => '2023-01-01',
        ]);

        $response = $this->actingAs($user)->delete(route('injuries.destroy', $injury));

        $response->assertRedirect();
        $this->assertDatabaseMissing('injuries', [
            'id' => $injury->id,
        ]);
    }
}
