<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WaterLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class WaterControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_water_tracker_index_is_displayed_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Freeze time to noon to avoid day-boundary issues
        $this->travelTo(now()->startOfDay()->addHours(12));

        $todayLog = WaterLog::factory()->create([
            'user_id' => $user->id,
            'amount' => 500,
            'consumed_at' => now(),
        ]);

        WaterLog::factory()->create([
            'user_id' => $user->id,
            'amount' => 300,
            'consumed_at' => now()->subDay(),
        ]);

        $response = $this->get(route('tools.water.index'));

        $response->assertOk();

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Tools/WaterTracker')
            ->has('logs', 1)
            ->where('logs.0.id', $todayLog->id)
            ->where('todayTotal', fn ($val) => (int) $val === 500)
            ->has('history', 7)
            ->where('goal', 2500)
        );
    }

    public function test_water_tracker_index_redirects_unauthenticated_users(): void
    {
        $response = $this->get(route('tools.water.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_user_can_add_water_log(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->travelTo(now());

        $data = [
            'amount' => 300,
            'consumed_at' => now()->toDateTimeString(),
        ];

        $response = $this->post(route('tools.water.store'), $data);

        $response->assertRedirect();

        $this->assertDatabaseHas('water_logs', [
            'user_id' => $user->id,
            'amount' => 300,
        ]);
    }

    public function test_water_log_creation_requires_amount(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->post(route('tools.water.store'), [
            'consumed_at' => now()->toDateTimeString(),
        ]);

        $response->assertSessionHasErrors('amount');
    }

    public function test_water_log_creation_requires_consumed_at(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->post(route('tools.water.store'), [
            'amount' => 500,
        ]);

        $response->assertSessionHasErrors('consumed_at');
    }

    public function test_user_can_delete_their_own_water_log(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $log = WaterLog::factory()->create([
            'user_id' => $user->id,
        ]);

        $response = $this->delete(route('tools.water.destroy', $log));

        $response->assertRedirect();

        $this->assertDatabaseMissing('water_logs', [
            'id' => $log->id,
        ]);
    }

    public function test_user_cannot_delete_another_users_water_log(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $otherUser = User::factory()->create();
        $log = WaterLog::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        $response = $this->delete(route('tools.water.destroy', $log));

        $response->assertForbidden();

        $this->assertDatabaseHas('water_logs', [
            'id' => $log->id,
        ]);
    }
}
