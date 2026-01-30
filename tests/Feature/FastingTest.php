<?php

namespace Tests\Feature;

use App\Models\Fast;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Inertia\Testing\AssertableInertia as Assert;

class FastingTest extends TestCase
{
    use RefreshDatabase;

    public function test_fasting_tracker_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('tools.fasting.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Tools/FastingTracker')
            ->has('activeFast')
            ->has('history')
        );
    }

    public function test_user_can_start_fast(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('tools.fasting.store'), [
            'start_time' => now()->toDateTimeString(),
            'target_duration_minutes' => 960,
            'type' => '16:8',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('fasts', [
            'user_id' => $user->id,
            'status' => 'ACTIVE',
            'type' => '16:8',
        ]);
    }

    public function test_user_cannot_start_fast_if_already_active(): void
    {
        $user = User::factory()->create();
        $user->fasts()->create([
            'start_time' => now(),
            'target_duration_minutes' => 960,
            'type' => '16:8',
            'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($user)->post(route('tools.fasting.store'), [
            'start_time' => now()->toDateTimeString(),
            'target_duration_minutes' => 960,
            'type' => '16:8',
        ]);

        $response->assertSessionHasErrors(['message']);
        $this->assertDatabaseCount('fasts', 1);
    }

    public function test_user_can_end_fast(): void
    {
        $user = User::factory()->create();
        $fast = $user->fasts()->create([
            'start_time' => now(),
            'target_duration_minutes' => 960,
            'type' => '16:8',
            'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($user)->post(route('tools.fasting.update', $fast), [
            'action' => 'end',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('fasts', [
            'id' => $fast->id,
            'status' => 'COMPLETED',
        ]);
        $fast->refresh();
        $this->assertNotNull($fast->end_time);
    }

    public function test_user_can_delete_fast(): void
    {
        $user = User::factory()->create();
        $fast = $user->fasts()->create([
            'start_time' => now(),
            'target_duration_minutes' => 960,
            'type' => '16:8',
            'status' => 'COMPLETED',
        ]);

        $response = $this->actingAs($user)->delete(route('tools.fasting.destroy', $fast));

        $response->assertRedirect();
        $this->assertDatabaseMissing('fasts', ['id' => $fast->id]);
    }

    public function test_user_cannot_delete_others_fast(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $fast = $otherUser->fasts()->create([
            'start_time' => now(),
            'target_duration_minutes' => 960,
            'type' => '16:8',
            'status' => 'COMPLETED',
        ]);

        $response = $this->actingAs($user)->delete(route('tools.fasting.destroy', $fast));

        $response->assertStatus(403);
        $this->assertDatabaseHas('fasts', ['id' => $fast->id]);
    }
}
