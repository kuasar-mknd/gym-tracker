<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Fast;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FastingTest extends TestCase
{
    use RefreshDatabase;

    public function test_fasting_tracker_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('tools.fasting.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Tools/FastingTracker'));
    }

    public function test_user_can_start_fast(): void
    {
        $user = User::factory()->create();
        $startTime = now();

        $response = $this->actingAs($user)->post(route('tools.fasting.store'), [
            'start_time' => $startTime->toDateTimeString(),
            'target_duration_minutes' => 960, // 16 hours
            'type' => '16:8',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('fasts', [
            'user_id' => $user->id,
            'target_duration_minutes' => 960,
            'type' => '16:8',
            'end_time' => null,
        ]);
    }

    public function test_user_cannot_start_concurrent_fast(): void
    {
        $user = User::factory()->create();
        Fast::create([
            'user_id' => $user->id,
            'start_time' => now()->subHour(),
            'target_duration_minutes' => 960,
            'type' => '16:8',
        ]);

        $response = $this->actingAs($user)->post(route('tools.fasting.store'), [
            'start_time' => now()->toDateTimeString(),
            'target_duration_minutes' => 960,
            'type' => '16:8',
        ]);

        $response->assertSessionHasErrors(['message']);
    }

    public function test_user_can_end_fast(): void
    {
        $user = User::factory()->create();
        $fast = Fast::create([
            'user_id' => $user->id,
            'start_time' => now()->subHours(17),
            'target_duration_minutes' => 960,
            'type' => '16:8',
        ]);

        $endTime = now();

        $response = $this->actingAs($user)->patch(route('tools.fasting.update', $fast), [
            'end_time' => $endTime->toDateTimeString(),
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('fasts', [
            'id' => $fast->id,
            'end_time' => $endTime->format('Y-m-d H:i:s'),
        ]);
    }

    public function test_user_can_delete_fast(): void
    {
        $user = User::factory()->create();
        $fast = Fast::create([
            'user_id' => $user->id,
            'start_time' => now()->subHours(5),
            'target_duration_minutes' => 960,
            'type' => '16:8',
        ]);

        $response = $this->actingAs($user)->delete(route('tools.fasting.destroy', $fast));

        $response->assertRedirect();
        $this->assertDatabaseMissing('fasts', [
            'id' => $fast->id,
        ]);
    }

    public function test_user_cannot_edit_others_fast(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $fast = Fast::create([
            'user_id' => $user1->id,
            'start_time' => now()->subHours(5),
            'target_duration_minutes' => 960,
            'type' => '16:8',
        ]);

        $response = $this->actingAs($user2)->patch(route('tools.fasting.update', $fast), [
            'end_time' => now(),
        ]);

        $response->assertStatus(403);
    }
}
