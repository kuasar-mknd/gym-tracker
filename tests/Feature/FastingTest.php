<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Fast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FastingTest extends TestCase
{
    use RefreshDatabase;

    public function test_fasting_page_can_be_viewed()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('tools.fasting.index'));

        $response->assertStatus(200);
    }

    public function test_can_start_fast()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('tools.fasting.store'), [
            'target_duration_minutes' => 960,
            'type' => '16:8',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('fasts', [
            'user_id' => $user->id,
            'type' => '16:8',
            'end_time' => null,
        ]);
    }

    public function test_cannot_start_fast_if_already_active()
    {
        $user = User::factory()->create();
        Fast::create([
            'user_id' => $user->id,
            'start_time' => now(),
            'target_duration_minutes' => 960,
            'type' => '16:8',
        ]);

        $response = $this->actingAs($user)->post(route('tools.fasting.store'), [
            'target_duration_minutes' => 960,
            'type' => '16:8',
        ]);

        $response->assertSessionHasErrors(['message']);
        $this->assertDatabaseCount('fasts', 1);
    }

    public function test_can_end_fast()
    {
        $user = User::factory()->create();
        $fast = Fast::create([
            'user_id' => $user->id,
            'start_time' => now()->subHours(1),
            'target_duration_minutes' => 960,
            'type' => '16:8',
        ]);

        $response = $this->actingAs($user)->patch(route('tools.fasting.update', $fast));

        $response->assertRedirect();
        $this->assertNotNull($fast->fresh()->end_time);
    }

    public function test_can_delete_fast()
    {
        $user = User::factory()->create();
        $fast = Fast::create([
            'user_id' => $user->id,
            'start_time' => now()->subHours(1),
            'target_duration_minutes' => 960,
            'type' => '16:8',
            'end_time' => now(),
        ]);

        $response = $this->actingAs($user)->delete(route('tools.fasting.destroy', $fast));

        $response->assertRedirect();
        $this->assertDatabaseMissing('fasts', ['id' => $fast->id]);
    }
}
